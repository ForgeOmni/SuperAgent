<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

/**
 * Uniform return shape from every `ModeOrchestrator::run()` call.
 *
 * Three mandatory fields (`text`, `costUsd`, `mode`) every renderer
 * can rely on. `trace` carries the mode-stack the result traversed
 * — useful for "auto → squad → smart" UI breadcrumbs. `modeSpecific`
 * is a free bag for fields only one mode produces (e.g. `subtask_results`
 * for smart, `roles` / `mailbox_log` / `squad_id` for squad).
 */
final class ModeResult
{
    public function __construct(
        public readonly string $text,
        public readonly float $costUsd,
        public readonly string $mode,
        /** @var list<string> */
        public readonly array $trace = [],
        /** @var array<string, mixed> */
        public readonly array $modeSpecific = [],
    ) {}

    /**
     * Convert to a flat envelope shape suitable for JSON output / a
     * `Backend::generate` return value.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text'         => $this->text,
            'cost_usd'     => $this->costUsd,
            'mode'         => $this->mode,
            'trace'        => $this->trace,
            'mode_specific'=> $this->modeSpecific,
        ];
    }
}
