<?php

declare(strict_types=1);

namespace SuperAgent\Output;

/**
 * Safe stream writer that silently handles broken pipes and closed streams.
 *
 * Inspired by hermes-agent's _SafeWriter — prevents agent crashes when
 * stdout/stderr is unavailable (systemd services, Docker containers,
 * headless daemons, or piped-to-closed-consumer scenarios).
 *
 * Usage:
 *   $safe = new SafeStreamWriter(STDOUT);
 *   $safe->write("output text");       // Never throws on broken pipe
 *   $safe->writeln("line of text");     // Convenience for line output
 */
class SafeStreamWriter
{
    /** @var resource|null */
    private $stream;
    private bool $broken = false;

    /**
     * @param resource $stream  A writable stream (STDOUT, STDERR, fopen result, etc.)
     */
    public function __construct($stream)
    {
        $this->stream = is_resource($stream) ? $stream : null;
    }

    /**
     * Write to the stream, silently catching broken pipe / closed stream errors.
     *
     * @return int|false  Bytes written, or false if the stream is broken/unavailable
     */
    public function write(string $data): int|false
    {
        if ($this->broken || $this->stream === null) {
            return false;
        }

        // Suppress E_WARNING from fwrite on broken pipes
        $written = @fwrite($this->stream, $data);

        if ($written === false) {
            $this->broken = true;
            return false;
        }

        return $written;
    }

    /**
     * Write a line to the stream (appends newline).
     */
    public function writeln(string $data): int|false
    {
        return $this->write($data . PHP_EOL);
    }

    /**
     * Flush the stream buffer, silently handling errors.
     */
    public function flush(): bool
    {
        if ($this->broken || $this->stream === null) {
            return false;
        }

        return @fflush($this->stream);
    }

    /**
     * Check if the stream is still writable.
     */
    public function isWritable(): bool
    {
        return !$this->broken && $this->stream !== null && is_resource($this->stream);
    }

    /**
     * Check if the stream has been detected as broken.
     */
    public function isBroken(): bool
    {
        return $this->broken;
    }

    /**
     * Create a SafeStreamWriter for STDOUT.
     */
    public static function stdout(): self
    {
        return new self(defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
    }

    /**
     * Create a SafeStreamWriter for STDERR.
     */
    public static function stderr(): self
    {
        return new self(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
    }
}
