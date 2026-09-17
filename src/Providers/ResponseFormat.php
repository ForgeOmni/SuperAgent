<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * Structured output configuration for LLM responses.
 *
 * Allows forcing the LLM to respond in valid JSON matching a schema,
 * using the provider's native JSON mode capabilities.
 */
class ResponseFormat
{
    private function __construct(
        public readonly string $type,
        public readonly ?array $schema = null,
        public readonly ?string $schemaName = null,
    ) {}

    /**
     * Plain text response (default behavior).
     */
    public static function text(): self
    {
        return new self('text');
    }

    /**
     * Force JSON output without a specific schema.
     */
    public static function json(): self
    {
        return new self('json_object');
    }

    /**
     * Force JSON output matching a specific JSON Schema.
     *
     * @param array  $schema     JSON Schema definition
     * @param string $schemaName Name for the schema (required by some providers)
     */
    public static function jsonSchema(array $schema, string $schemaName = 'response'): self
    {
        return new self('json_schema', $schema, $schemaName);
    }

    /**
     * Convert to Anthropic API format (tool_use trick for structured output).
     */
    public function toAnthropicFormat(): array
    {
        if ($this->type === 'text') {
            return [];
        }

        if ($this->type === 'json_object') {
            return ['response_format' => ['type' => 'json']];
        }

        // Anthropic uses tool_use for structured output
        if ($this->type === 'json_schema' && $this->schema !== null) {
            return [
                '_structured_output' => [
                    'name' => $this->schemaName ?? 'response',
                    'schema' => $this->schema,
                ],
            ];
        }

        return [];
    }

    /**
     * Convert to OpenAI API format.
     */
    public function toOpenAIFormat(): array
    {
        if ($this->type === 'text') {
            return [];
        }

        if ($this->type === 'json_object') {
            return ['response_format' => ['type' => 'json_object']];
        }

        if ($this->type === 'json_schema' && $this->schema !== null) {
            return [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $this->schemaName ?? 'response',
                        'schema' => $this->schema,
                        'strict' => true,
                    ],
                ],
            ];
        }

        return [];
    }

    /**
     * Convert to Gemini API format.
     *
     * Gemini takes structured output inside `generationConfig`, not beside it,
     * and its schema dialect is the OpenAPI 3.0 subset — the caller (the
     * provider) sanitises what it gets here before putting it on the wire.
     */
    public function toGeminiFormat(): array
    {
        if ($this->type === 'text') {
            return [];
        }

        if ($this->type === 'json_object') {
            return ['responseMimeType' => 'application/json'];
        }

        if ($this->type === 'json_schema' && $this->schema !== null) {
            return [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->schema,
            ];
        }

        return [];
    }

    public function isStructured(): bool
    {
        return $this->type !== 'text';
    }
}
