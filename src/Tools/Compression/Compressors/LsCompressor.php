<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Compression\Compressors;

use SuperAgent\Tools\Compression\CompressorInterface;

/**
 * Compress `ls -l` / `ls -la` output by dropping owner/group/date/size
 * columns when they aren't load-bearing for the model's task. Preserves
 * permission strings (lets the model spot exec bits) and filenames.
 *
 *   -rw-r--r-- 1 alice staff 1240 Mar  5 10:14 foo.txt
 *
 *   becomes:
 *
 *   -rw-r--r--  foo.txt
 *
 * For plain `ls` (single column or `ls -1`), no-op.
 */
final class LsCompressor implements CompressorInterface
{
    public function compress(string $output): ?string
    {
        $lines = preg_split("/\r?\n/", $output) ?: [];
        $touched = false;
        $out = [];
        foreach ($lines as $line) {
            // ls -l pattern: perms links owner group size date1 date2 date3 name
            if (preg_match('/^([dlrwxs\-]{10,11}\+?)\s+\d+\s+\S+\s+\S+\s+\d+\s+\S+\s+\S+\s+\S+\s+(.+)$/', $line, $m)) {
                $out[] = $m[1] . '  ' . $m[2];
                $touched = true;
                continue;
            }
            // Drop "total N" header
            if (preg_match('/^total \d+$/', trim($line))) {
                $touched = true;
                continue;
            }
            $out[] = $line;
        }
        return $touched ? implode("\n", $out) : null;
    }
}
