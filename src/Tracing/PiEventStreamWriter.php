<?php

declare(strict_types=1);

namespace SuperAgent\Tracing;

/**
 * JSONL writer for the Pi event stream. Wire-format identical to pi.dev's
 * JSON Event Stream Mode: each line is one event JSON object terminated by
 * "\n" (LF only — no CRLF). Use as a PiEventStream listener.
 */
final class PiEventStreamWriter
{
    /** @var resource|null */
    private $fh;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fh = fopen($path, 'ab');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open Pi event stream file: {$path}");
        }
        $this->fh = $fh;
    }

    public function __invoke(array $event): void
    {
        if ($this->fh === null) return;
        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) return;
        fwrite($this->fh, $line . "\n");
    }

    public function close(): void
    {
        if ($this->fh !== null) {
            fclose($this->fh);
            $this->fh = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
