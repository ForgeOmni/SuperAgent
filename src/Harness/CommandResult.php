<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

class CommandResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $output,
    ) {}

    public static function success(string $output): self
    {
        return new self(true, $output);
    }

    public static function error(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * Check if the output is a special control signal.
     */
    public function isSignal(string $prefix): bool
    {
        return str_starts_with($this->output, $prefix);
    }

    /**
     * Extract the payload after a signal prefix.
     */
    public function signalPayload(string $prefix): string
    {
        return substr($this->output, strlen($prefix));
    }
}
