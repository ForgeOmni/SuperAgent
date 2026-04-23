<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Harness;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\StatusEvent;
use SuperAgent\Harness\Wire\WireStreamOutput;
use SuperAgent\Harness\Wire\WireTransport;

/**
 * Exercises the DSN resolver + a live unix-socket round-trip to
 * confirm the renderer layer is unchanged by the transport swap.
 * File and socket paths are real syscalls (no mocks) — the transport
 * is small enough that mocking would hide more than it'd test.
 */
class WireTransportTest extends TestCase
{
    private array $toCleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->toCleanup as $path) {
            @unlink($path);
        }
        $this->toCleanup = [];
    }

    // ------------------------------------------------------------------
    // DSN resolution
    // ------------------------------------------------------------------

    public function test_stdout_dsn(): void
    {
        $t = WireTransport::open('stdout');
        $this->assertIsResource($t->stream);
        $this->assertNull($t->server);
        $this->assertSame('stdout', $t->dsn);
    }

    public function test_stderr_dsn(): void
    {
        $t = WireTransport::open('stderr');
        $this->assertIsResource($t->stream);
        $this->assertNull($t->server);
    }

    public function test_empty_dsn_defaults_to_stdout(): void
    {
        $t = WireTransport::open('');
        $this->assertSame('stdout', $t->dsn);
    }

    public function test_file_dsn_opens_in_append_mode(): void
    {
        $path = sys_get_temp_dir() . '/wire-' . bin2hex(random_bytes(4)) . '.ndjson';
        $this->toCleanup[] = $path;

        file_put_contents($path, "existing-content\n");

        $t = WireTransport::open('file://' . $path);
        fwrite($t->stream, "appended\n");
        fclose($t->stream);

        $this->assertSame("existing-content\nappended\n", file_get_contents($path));
    }

    public function test_file_dsn_creates_parent_directory(): void
    {
        $subdir = sys_get_temp_dir() . '/wire-nested-' . bin2hex(random_bytes(4));
        $path = $subdir . '/log.ndjson';
        $this->toCleanup[] = $path;

        try {
            $t = WireTransport::open('file://' . $path);
            fwrite($t->stream, "line\n");
            fclose($t->stream);
            $this->assertFileExists($path);
        } finally {
            @rmdir($subdir);
        }
    }

    public function test_unsupported_dsn_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsupported DSN/');
        WireTransport::open('ftp://nope.example.com/x');
    }

    public function test_listen_dsn_without_address_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing address|must specify scheme/');
        WireTransport::open('listen://');
    }

    public function test_listen_dsn_missing_scheme_slash_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        WireTransport::open('listen://tcp');
    }

    public function test_tcp_connect_to_nonexistent_peer_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/connect to .* failed/');
        // Pick a port on localhost almost certainly not listening. 1
        // (< 1024) is fine — stream_socket_client returns connection
        // refused fast, we just need any fast failure.
        WireTransport::open('tcp://127.0.0.1:1', ['connect_timeout' => 0.5]);
    }

    // ------------------------------------------------------------------
    // Live unix-socket round-trip
    // ------------------------------------------------------------------

    public function test_unix_socket_round_trip(): void
    {
        $sock = sys_get_temp_dir() . '/wire-unix-' . bin2hex(random_bytes(4)) . '.sock';
        $this->toCleanup[] = $sock;

        $parentPid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
        if ($parentPid === -1) {
            $this->markTestSkipped('pcntl_fork unavailable — live socket test requires forking');
        }

        if ($parentPid === 0) {
            // Child process: act as the IDE peer. Connect, drain
            // everything the parent emits, write to a sentinel file,
            // then exit.
            try {
                // Small delay so parent has time to listen.
                usleep(200_000);
                $fh = @stream_socket_client('unix://' . $sock, $errno, $errstr, 2.0);
                if ($fh === false) exit(2);

                $received = '';
                stream_set_timeout($fh, 2);
                while (! feof($fh)) {
                    $chunk = fread($fh, 4096);
                    if ($chunk === '' || $chunk === false) break;
                    $received .= $chunk;
                }
                @fclose($fh);
                file_put_contents($sock . '.received', $received);
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }

        // Parent: listen, accept, emit one event, close.
        try {
            $t = WireTransport::open('listen://unix/' . $sock, ['accept_timeout' => 3.0]);
            $this->assertIsResource($t->stream);
            $this->assertIsResource($t->server);

            $out = new WireStreamOutput($t->stream, flushAfterWrite: true);
            $event = new StatusEvent('ping', ['turn' => 7]);
            $out->emit($event);

            // Close peer so child sees EOF.
            @fclose($t->stream);
            $t->close();

            // Wait for child.
            $status = 0;
            pcntl_waitpid($parentPid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));

            $this->toCleanup[] = $sock . '.received';
            $received = (string) @file_get_contents($sock . '.received');
            $this->assertNotSame('', $received);

            $decoded = json_decode(trim($received), true);
            $this->assertIsArray($decoded);
            $this->assertSame(1, $decoded['wire_version']);
            $this->assertSame('ping', $decoded['message']);
            $this->assertSame(['turn' => 7], $decoded['data']);
        } catch (\Throwable $e) {
            if ($parentPid > 0) {
                posix_kill($parentPid, SIGTERM);
                pcntl_waitpid($parentPid, $status);
            }
            throw $e;
        }
    }
}
