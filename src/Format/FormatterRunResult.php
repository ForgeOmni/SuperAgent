<?php

declare(strict_types=1);

namespace SuperAgent\Format;

final class FormatterRunResult
{
    /**
     * @param array<int, FormatterRun> $runs
     */
    public function __construct(
        public readonly string $path,
        public readonly array $runs,
    ) {
    }

    public function ran(): bool
    {
        return $this->runs !== [];
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_map(static fn (FormatterRun $r) => $r->formatter, $this->runs);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'runs' => array_map(static fn (FormatterRun $r) => $r->toArray(), $this->runs),
        ];
    }
}
