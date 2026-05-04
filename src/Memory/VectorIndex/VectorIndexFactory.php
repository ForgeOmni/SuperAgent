<?php

declare(strict_types=1);

namespace SuperAgent\Memory\VectorIndex;

/**
 * Picks a vector index backend based on what's available on the host.
 *
 * Resolution order:
 *   1. If `SUPERAGENT_VECTOR_BACKEND=brute`, force brute force
 *      (useful for tests / debugging).
 *   2. If `$serverScript` exists and a `node` binary is on PATH, use
 *      `HnswVectorIndex` (which itself falls back to brute force on
 *      runtime failure).
 *   3. Otherwise, brute force.
 *
 * Hosts that want a different backend (sqlite-vss, Qdrant, OpenSearch,
 * …) construct their `VectorIndex` directly instead of going through
 * the factory.
 */
final class VectorIndexFactory
{
    public static function create(
        int $dimensions,
        ?string $serverScript = null,
        string $nodeBinary = 'node',
    ): VectorIndex {
        $forced = getenv('SUPERAGENT_VECTOR_BACKEND') ?: ($_ENV['SUPERAGENT_VECTOR_BACKEND'] ?? null);
        if ($forced === 'brute') {
            return new BruteForceVectorIndex($dimensions);
        }

        if ($serverScript !== null && is_file($serverScript) && self::nodeAvailable($nodeBinary)) {
            return new HnswVectorIndex(
                dimensions: $dimensions,
                serverScript: $serverScript,
                nodeBinary: $nodeBinary,
            );
        }

        return new BruteForceVectorIndex($dimensions);
    }

    /**
     * Probe `node --version` non-blockingly. Returns false on Windows
     * if PATH lookup fails or `node` is missing.
     */
    private static function nodeAvailable(string $nodeBinary): bool
    {
        $cmd = [$nodeBinary, '--version'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        try {
            $proc = @proc_open($cmd, $descriptors, $pipes);
        } catch (\Throwable) {
            return false;
        }
        if (!is_resource($proc)) return false;

        // Drain pipes so a slow `node --version` doesn't deadlock.
        if (isset($pipes[0]) && is_resource($pipes[0])) @fclose($pipes[0]);

        $deadline = microtime(true) + 1.5;
        while (microtime(true) < $deadline) {
            $status = @proc_get_status($proc);
            if (is_array($status) && !($status['running'] ?? true)) {
                $exit = (int) ($status['exitcode'] ?? -1);
                foreach ($pipes as $p) if (is_resource($p)) @fclose($p);
                @proc_close($proc);
                return $exit === 0;
            }
            usleep(10000); // 10ms
        }

        // Timed out — kill it and report unavailable rather than hanging.
        @proc_terminate($proc);
        foreach ($pipes as $p) if (is_resource($p)) @fclose($p);
        @proc_close($proc);
        return false;
    }
}
