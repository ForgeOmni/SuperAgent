<?php

declare(strict_types=1);

namespace SuperAgent\Format;

final class FormatterRun
{
    /**
     * @param array<int, string> $command Resolved command-line (no `$FILE` placeholder).
     */
    public function __construct(
        public readonly string $formatter,
        public readonly array $command,
        public readonly string $output,
        public readonly int $durationMs,
        public readonly bool $ok,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'formatter' => $this->formatter,
            'command' => $this->command,
            'output' => $this->output,
            'duration_ms' => $this->durationMs,
            'ok' => $this->ok,
        ];
    }
}
