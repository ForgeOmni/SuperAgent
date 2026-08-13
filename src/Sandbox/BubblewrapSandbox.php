<?php

declare(strict_types=1);

namespace SuperAgent\Sandbox;

/**
 * Linux backend over bubblewrap (`bwrap`).
 *
 * Policy: the whole filesystem is bind-mounted read-only; the spec's
 * writable paths are re-bound read-write; /dev and /proc are fresh; network
 * namespace is unshared when the spec denies network. Children inherit the
 * confinement (kernel-enforced), mirroring landlock-run's
 * self-restrict-then-exec property.
 */
class BubblewrapSandbox implements SandboxInterface
{
    private ?string $binary = null;
    private bool $probed = false;

    public function name(): string
    {
        return 'bubblewrap';
    }

    public function isAvailable(): bool
    {
        return PHP_OS_FAMILY === 'Linux' && $this->binaryPath() !== null;
    }

    public function wrapCommand(string $command, SandboxSpec $spec): string
    {
        $binary = $this->binaryPath();
        if ($binary === null) {
            throw new SandboxUnavailableException('bwrap binary not found');
        }

        $args = [
            escapeshellarg($binary),
            '--ro-bind / /',
            '--dev /dev',
            '--proc /proc',
            '--tmpfs /tmp',
        ];

        foreach ($spec->resolvedWritablePaths() as $path) {
            $quoted = escapeshellarg($path);
            $args[] = "--bind {$quoted} {$quoted}";
        }

        if (!$spec->allowNetwork) {
            $args[] = '--unshare-net';
        }

        $args[] = '--die-with-parent';
        $args[] = '/bin/sh -c ' . escapeshellarg($command);

        return implode(' ', $args);
    }

    public function describeEnforcement(SandboxSpec $spec): string
    {
        $paths = implode(', ', $spec->resolvedWritablePaths());
        $net = $spec->allowNetwork ? 'network allowed' : 'network namespace unshared';

        return "bubblewrap: root read-only, writes restricted to [{$paths}], {$net}";
    }

    private function binaryPath(): ?string
    {
        if (!$this->probed) {
            $this->probed = true;
            $found = trim((string) shell_exec('command -v bwrap 2>/dev/null'));
            $this->binary = $found !== '' ? $found : null;
        }

        return $this->binary;
    }
}
