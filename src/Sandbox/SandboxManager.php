<?php

declare(strict_types=1);

namespace SuperAgent\Sandbox;

/**
 * Resolves the active sandbox backend from config and wraps commands.
 *
 * Modes:
 *  - off     (default) — no sandboxing, byte-identical legacy behavior
 *  - auto    — use the platform backend when available, otherwise run
 *              unconfined (best-effort)
 *  - require — fail closed: no backend ⇒ SandboxUnavailableException
 */
class SandboxManager
{
    public const MODE_OFF = 'off';
    public const MODE_AUTO = 'auto';
    public const MODE_REQUIRE = 'require';

    /** @var SandboxInterface[] */
    private array $backends;

    public function __construct(
        private readonly string $mode = self::MODE_OFF,
        private readonly ?SandboxSpec $spec = null,
        ?array $backends = null,
    ) {
        $this->backends = $backends ?? [new SeatbeltSandbox(), new BubblewrapSandbox()];
    }

    public static function fromConfig(?string $workspaceRoot = null): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.sandbox') ?? []) : [];
        } catch (\Throwable $e) {
            $config = [];
        }

        $root = $workspaceRoot ?? (getcwd() ?: sys_get_temp_dir());

        return new self(
            mode: $config['mode'] ?? self::MODE_OFF,
            spec: new SandboxSpec(
                workspaceRoot: $root,
                writablePaths: array_merge(
                    [sys_get_temp_dir()],
                    $config['writable_paths'] ?? [],
                ),
                allowNetwork: (bool) ($config['allow_network'] ?? true),
            ),
        );
    }

    public function isActive(): bool
    {
        if ($this->mode === self::MODE_OFF) {
            return false;
        }

        return $this->resolveBackend() !== null || $this->mode === self::MODE_REQUIRE;
    }

    /**
     * Wrap a shell command for the active backend.
     *
     * @throws SandboxUnavailableException in mode=require with no backend
     */
    public function wrapCommand(string $command, ?string $cwd = null): string
    {
        if ($this->mode === self::MODE_OFF) {
            return $command;
        }

        $backend = $this->resolveBackend();

        if ($backend === null) {
            if ($this->mode === self::MODE_REQUIRE) {
                throw new SandboxUnavailableException(
                    'sandbox.mode=require but no sandbox backend is available on this host '
                    . '(need macOS sandbox-exec or Linux bwrap)'
                );
            }

            return $command; // auto: best-effort passthrough
        }

        return $backend->wrapCommand($command, $this->specFor($cwd));
    }

    /** Description of what is (or is not) enforced — for logs/results. */
    public function describeEnforcement(?string $cwd = null): string
    {
        if ($this->mode === self::MODE_OFF) {
            return 'sandbox off';
        }

        $backend = $this->resolveBackend();
        if ($backend === null) {
            return 'sandbox unavailable (mode=' . $this->mode . ')';
        }

        return $backend->describeEnforcement($this->specFor($cwd));
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    private function resolveBackend(): ?SandboxInterface
    {
        foreach ($this->backends as $backend) {
            if ($backend->isAvailable()) {
                return $backend;
            }
        }

        return null;
    }

    /**
     * The configured spec, with the workspace root swapped for the actual
     * working directory of this call so bash and future consumers confine
     * to the same root they run in.
     */
    private function specFor(?string $cwd): SandboxSpec
    {
        $spec = $this->spec ?? new SandboxSpec(getcwd() ?: sys_get_temp_dir());

        if ($cwd === null || $cwd === $spec->workspaceRoot) {
            return $spec;
        }

        return new SandboxSpec(
            workspaceRoot: $cwd,
            writablePaths: $spec->writablePaths,
            allowNetwork: $spec->allowNetwork,
        );
    }
}
