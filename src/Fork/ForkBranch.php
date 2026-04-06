<?php

declare(strict_types=1);

namespace SuperAgent\Fork;

final class ForkBranch
{
    public string $id;
    public string $prompt;
    public string $status = 'pending';
    public ?array $resultMessages = null;
    public ?float $cost = null;
    public ?int $turns = null;
    public ?float $score = null;
    public ?string $error = null;
    public float $durationMs = 0.0;
    public array $config;

    public function __construct(string $prompt, array $config = [])
    {
        $this->id = substr(md5(uniqid((string) mt_rand(), true)), 0, 12);
        $this->prompt = $prompt;
        $this->config = $config;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function getLastAssistantMessage(): ?string
    {
        if ($this->resultMessages === null) {
            return null;
        }

        for ($i = count($this->resultMessages) - 1; $i >= 0; $i--) {
            $msg = $this->resultMessages[$i];
            $role = is_array($msg) ? ($msg['role'] ?? '') : (isset($msg->role) ? $msg->role : '');
            if ($role === 'assistant') {
                $content = is_array($msg) ? ($msg['content'] ?? '') : (isset($msg->content) ? $msg->content : '');
                return is_string($content) ? $content : json_encode($content);
            }
        }

        return null;
    }

    public function markRunning(): void
    {
        $this->status = 'running';
    }

    public function markCompleted(array $messages, float $cost, int $turns, float $durationMs): void
    {
        $this->status = 'completed';
        $this->resultMessages = $messages;
        $this->cost = $cost;
        $this->turns = $turns;
        $this->durationMs = $durationMs;
    }

    public function markFailed(string $error, float $durationMs): void
    {
        $this->status = 'failed';
        $this->error = $error;
        $this->durationMs = $durationMs;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'status' => $this->status,
            'cost' => $this->cost,
            'turns' => $this->turns,
            'score' => $this->score,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
            'config' => $this->config,
            'last_message' => $this->getLastAssistantMessage(),
        ];
    }
}
