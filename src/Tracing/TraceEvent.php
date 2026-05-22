<?php

declare(strict_types=1);

namespace SuperAgent\Tracing;

/**
 * Immutable Chrome Trace Event record.
 *
 * Mirrors the spec at
 * https://docs.google.com/document/d/1CvAClvFfyA5R-PhYUmn5OOQtYMH4h6I0nSsKchNAySU
 *
 * See .claude/refs/ref-trace-format.md in the SuperTeam repo for the
 * cross-repo contract this format implements.
 */
final class TraceEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $category,
        public readonly string $phase,       // X, i, B, E, C, M
        public readonly string $pid,
        public readonly string $tid,
        public readonly int $tsMicros,
        public readonly ?int $durationMicros,
        public readonly array $args = [],
        public readonly ?string $scope = null,  // for 'i' instant events: g|p|t
        public readonly ?string $color = null,
    ) {}

    public static function duration(
        string $name,
        string $category,
        string $pid,
        string $tid,
        int $startMicros,
        int $durationMicros,
        array $args = [],
        ?string $color = null,
    ): self {
        return new self(
            name: $name,
            category: $category,
            phase: 'X',
            pid: $pid,
            tid: $tid,
            tsMicros: $startMicros,
            durationMicros: $durationMicros,
            args: $args,
            scope: null,
            color: $color,
        );
    }

    public static function instant(
        string $name,
        string $category,
        string $pid,
        string $tid,
        ?int $tsMicros = null,
        array $args = [],
        string $scope = 'g',
        ?string $color = null,
    ): self {
        return new self(
            name: $name,
            category: $category,
            phase: 'i',
            pid: $pid,
            tid: $tid,
            tsMicros: $tsMicros ?? (int) (microtime(true) * 1_000_000),
            durationMicros: null,
            args: $args,
            scope: $scope,
            color: $color,
        );
    }

    public static function counter(
        string $name,
        string $category,
        string $pid,
        string $tid,
        array $values,
        ?int $tsMicros = null,
    ): self {
        return new self(
            name: $name,
            category: $category,
            phase: 'C',
            pid: $pid,
            tid: $tid,
            tsMicros: $tsMicros ?? (int) (microtime(true) * 1_000_000),
            durationMicros: null,
            args: $values,
            scope: null,
            color: null,
        );
    }

    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'cat' => $this->category,
            'ph' => $this->phase,
            'pid' => $this->pid,
            'tid' => $this->tid,
            'ts' => $this->tsMicros,
        ];
        if ($this->durationMicros !== null) {
            $out['dur'] = $this->durationMicros;
        }
        if (!empty($this->args)) {
            $out['args'] = $this->args;
        }
        if ($this->scope !== null) {
            $out['s'] = $this->scope;
        }
        if ($this->color !== null) {
            $out['cname'] = $this->color;
        }

        return $out;
    }
}
