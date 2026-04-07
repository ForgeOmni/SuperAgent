<?php

namespace SuperAgent\Telemetry;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StructuredLogger
{
    private static ?self $instance = null;
    private bool $enabled;
    private array $globalContext = [];
    private string $sessionId;
    private string $requestId;

    public function __construct()
    {
        $this->enabled = config('superagent.telemetry.enabled', false)
            && config('superagent.telemetry.logging.enabled', false);
        $this->sessionId = uniqid('session_');
        $this->requestId = uniqid('request_');
    }

    /**
     * @deprecated Use constructor injection instead.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set global context for all logs.
     */
    public function setGlobalContext(array $context): void
    {
        $this->globalContext = array_merge($this->globalContext, $context);
    }

    /**
     * Set session ID.
     */
    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Set request ID.
     */
    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * Log an LLM request.
     */
    public function logLLMRequest(
        string $model,
        array $messages,
        array $response = null,
        float $duration = null,
        array $metadata = []
    ): void {
        if (!$this->enabled) {
            return;
        }

        $context = $this->buildContext([
            'type' => 'llm_request',
            'model' => $model,
            'message_count' => count($messages),
            'duration_ms' => $duration,
            'has_response' => $response !== null,
            'metadata' => $metadata,
        ]);

        // Log request details
        Log::info('LLM Request', $context);

        // Log messages (with truncation for large content)
        foreach ($messages as $index => $message) {
            $messageContext = $this->buildContext([
                'type' => 'llm_message',
                'model' => $model,
                'message_index' => $index,
                'role' => $message['role'] ?? 'unknown',
                'content_length' => strlen(json_encode($message['content'] ?? '')),
                'content_preview' => $this->truncate($message['content'] ?? '', 200),
            ]);
            Log::debug('LLM Message', $messageContext);
        }

        // Log response if present
        if ($response) {
            $responseContext = $this->buildContext([
                'type' => 'llm_response',
                'model' => $model,
                'usage' => $response['usage'] ?? null,
                'finish_reason' => $response['finish_reason'] ?? null,
                'response_length' => strlen(json_encode($response)),
            ]);
            Log::info('LLM Response', $responseContext);
        }
    }

    /**
     * Log tool execution.
     */
    public function logToolExecution(
        string $toolName,
        array $input,
        $result = null,
        float $duration = null,
        bool $success = true,
        string $error = null
    ): void {
        if (!$this->enabled) {
            return;
        }

        $context = $this->buildContext([
            'type' => 'tool_execution',
            'tool' => $toolName,
            'input_size' => strlen(json_encode($input)),
            'has_result' => $result !== null,
            'duration_ms' => $duration,
            'success' => $success,
            'error' => $error,
        ]);

        $level = $success ? 'info' : 'error';
        Log::$level("Tool Execution: {$toolName}", $context);

        // Log input details at debug level
        Log::debug("Tool Input: {$toolName}", $this->buildContext([
            'type' => 'tool_input',
            'tool' => $toolName,
            'input' => $this->sanitizeForLogging($input),
        ]));

        // Log result if present
        if ($result !== null) {
            Log::debug("Tool Result: {$toolName}", $this->buildContext([
                'type' => 'tool_result',
                'tool' => $toolName,
                'result_preview' => $this->truncate(json_encode($result), 500),
            ]));
        }
    }

    /**
     * Log an error with context.
     */
    public function logError(
        string $message,
        \Throwable $exception = null,
        array $context = []
    ): void {
        if (!$this->enabled) {
            return;
        }

        $errorContext = $this->buildContext(array_merge([
            'type' => 'error',
            'error_message' => $message,
        ], $context));

        if ($exception) {
            $errorContext['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->truncate($exception->getTraceAsString(), 2000),
            ];
        }

        Log::error($message, $errorContext);
    }

    /**
     * Log performance metrics.
     */
    public function logPerformance(string $operation, float $duration, array $metadata = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $context = $this->buildContext(array_merge([
            'type' => 'performance',
            'operation' => $operation,
            'duration_ms' => $duration,
        ], $metadata));

        Log::info("Performance: {$operation}", $context);
    }

    /**
     * Log session start.
     */
    public function logSessionStart(array $metadata = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $context = $this->buildContext(array_merge([
            'type' => 'session_start',
        ], $metadata));

        Log::info('Session Started', $context);
    }

    /**
     * Log session end.
     */
    public function logSessionEnd(array $summary = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $context = $this->buildContext(array_merge([
            'type' => 'session_end',
            'summary' => $summary,
        ], []));

        Log::info('Session Ended', $context);
    }

    /**
     * Build log context with common fields.
     */
    private function buildContext(array $context): array
    {
        return array_merge($this->globalContext, [
            'timestamp' => Carbon::now()->toIso8601String(),
            'session_id' => $this->sessionId,
            'request_id' => $this->requestId,
            'environment' => app()->environment(),
            'service' => 'superagent',
        ], $context);
    }

    /**
     * Truncate long strings for logging.
     */
    private function truncate($value, int $maxLength = 1000): string
    {
        if (!is_string($value)) {
            $value = json_encode($value);
        }

        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength) . '... [truncated]';
    }

    /**
     * Sanitize sensitive data for logging.
     */
    private function sanitizeForLogging($data): mixed
    {
        if (is_string($data)) {
            // Redact potential secrets
            $data = preg_replace('/(["\']?api[_-]?key["\']?\s*[:=]\s*["\']?)([^"\']+)/i', '$1[REDACTED]', $data);
            $data = preg_replace('/(["\']?token["\']?\s*[:=]\s*["\']?)([^"\']+)/i', '$1[REDACTED]', $data);
            $data = preg_replace('/(["\']?password["\']?\s*[:=]\s*["\']?)([^"\']+)/i', '$1[REDACTED]', $data);
            return $data;
        }

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // Redact sensitive keys
                if (preg_match('/(api[_-]?key|token|password|secret|credential)/i', $key)) {
                    $sanitized[$key] = '[REDACTED]';
                } else {
                    $sanitized[$key] = $this->sanitizeForLogging($value);
                }
            }
            return $sanitized;
        }

        return $data;
    }

    /**
     * Check if logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}