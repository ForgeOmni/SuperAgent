<?php

declare(strict_types=1);

namespace SuperAgent\Sandbox;

/**
 * macOS backend over `sandbox-exec` (Seatbelt).
 *
 * Policy: reads allowed everywhere, writes denied by default and re-allowed
 * only under the spec's writable paths (+ /dev for tty/null), network
 * denied when the spec says so. The generated profile is written to a
 * private 0600 file per call.
 */
class SeatbeltSandbox implements SandboxInterface
{
    private const BINARY = '/usr/bin/sandbox-exec';

    public function name(): string
    {
        return 'seatbelt';
    }

    public function isAvailable(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' && is_executable(self::BINARY);
    }

    public function wrapCommand(string $command, SandboxSpec $spec): string
    {
        $profilePath = $this->writeProfile($this->buildProfile($spec));

        return self::BINARY
            . ' -f ' . escapeshellarg($profilePath)
            . ' /bin/sh -c ' . escapeshellarg($command);
    }

    public function describeEnforcement(SandboxSpec $spec): string
    {
        $paths = implode(', ', $spec->resolvedWritablePaths());
        $net = $spec->allowNetwork ? 'network allowed' : 'network denied';

        return "seatbelt: writes restricted to [{$paths}], {$net}";
    }

    /**
     * Build the Seatbelt profile (SBPL).
     */
    public function buildProfile(SandboxSpec $spec): string
    {
        $lines = [
            '(version 1)',
            '(allow default)',
            '(deny file-write*)',
        ];

        $allow = ['(allow file-write*'];
        foreach ($spec->resolvedWritablePaths() as $path) {
            $allow[] = '    (subpath "' . $this->escapePath($path) . '")';
        }
        // ttys, /dev/null, pipes
        $allow[] = '    (subpath "/dev")';
        $allow[] = ')';
        $lines[] = implode("\n", $allow);

        if (!$spec->allowNetwork) {
            $lines[] = '(deny network*)';
        }

        return implode("\n", $lines) . "\n";
    }

    private function writeProfile(string $profile): string
    {
        $dir = sys_get_temp_dir() . '/superagent-sandbox';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $path = $dir . '/profile-' . bin2hex(random_bytes(8)) . '.sb';
        // Exclusive create + private perms (dsh defensive pattern: private
        // temp files, no symlink-follow races).
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write sandbox profile: ' . $path);
        }
        chmod($path, 0600);
        fwrite($handle, $profile);
        fclose($handle);

        return $path;
    }

    private function escapePath(string $path): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $path);
    }
}
