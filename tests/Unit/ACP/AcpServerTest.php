<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\ACP;

use PHPUnit\Framework\TestCase;
use SuperAgent\ACP\AcpException;
use SuperAgent\ACP\DefaultHandler;
use SuperAgent\ACP\Protocol;
use SuperAgent\ACP\Server;
use SuperAgent\ACP\SessionEntry;

class AcpServerTest extends TestCase
{
    /** @var resource */
    private $out;
    private string $outPath;

    protected function setUp(): void
    {
        $this->outPath = tempnam(sys_get_temp_dir(), 'acp-out-');
        $this->out = fopen($this->outPath, 'w+');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->out)) {
            fclose($this->out);
        }
        @unlink($this->outPath);
    }

    private function readOutput(): array
    {
        rewind($this->out);
        $contents = stream_get_contents($this->out) ?: '';
        $lines = array_filter(array_map('trim', explode("\n", $contents)));
        return array_values(array_map(static fn (string $l) => json_decode($l, true), $lines));
    }

    private function makeHandler(?\Closure $promptFn = null): DefaultHandler
    {
        return new DefaultHandler(
            agentName: 'superagent-test',
            agentVersion: '0.0.1',
            promptFn: $promptFn ?? fn (SessionEntry $s, array $p, Server $srv) => ['stopReason' => 'end_turn'],
        );
    }

    private function makeServer(DefaultHandler $h): Server
    {
        return new Server($h, in: STDIN, out: $this->out);
    }

    public function test_initialize_returns_capabilities(): void
    {
        $server = $this->makeServer($this->makeHandler());
        $server->handleLine(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => 1],
        ]));

        $out = $this->readOutput();
        $this->assertCount(1, $out);
        $this->assertSame(1, $out[0]['id']);
        $this->assertSame(1, $out[0]['result']['protocolVersion']);
        $this->assertSame('superagent-test', $out[0]['result']['agentInfo']['name']);
    }

    public function test_session_new_then_prompt(): void
    {
        $handler = $this->makeHandler(function (SessionEntry $s, array $params, Server $server) {
            $server->notify('session/update', ['sessionId' => $s->id, 'kind' => 'message_delta', 'text' => 'hi']);
            return ['stopReason' => 'end_turn', 'usage' => ['input' => 10, 'output' => 5]];
        });
        $server = $this->makeServer($handler);

        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'session/new', 'params' => ['cwd' => '/tmp']]));
        $out = $this->readOutput();
        $sessionId = $out[0]['result']['sessionId'];
        $this->assertNotEmpty($sessionId);

        // Run prompt
        ftruncate($this->out, 0);
        rewind($this->out);
        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'session/prompt', 'params' => ['sessionId' => $sessionId]]));

        $out = $this->readOutput();
        // First: notification, then response.
        $this->assertCount(2, $out);
        $this->assertSame('session/update', $out[0]['method']);
        $this->assertArrayNotHasKey('id', $out[0]);
        $this->assertSame(2, $out[1]['id']);
        $this->assertSame('end_turn', $out[1]['result']['stopReason']);
    }

    public function test_unknown_method_returns_method_not_found(): void
    {
        $server = $this->makeServer($this->makeHandler());
        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'totally/unknown']));
        $out = $this->readOutput();
        $this->assertSame(Protocol::ERR_METHOD_NOT_FOUND, $out[0]['error']['code']);
    }

    public function test_invalid_json_returns_parse_error(): void
    {
        $server = $this->makeServer($this->makeHandler());
        $server->handleLine('not json at all');
        $out = $this->readOutput();
        $this->assertSame(Protocol::ERR_PARSE, $out[0]['error']['code']);
        $this->assertNull($out[0]['id']);
    }

    public function test_notification_has_no_response(): void
    {
        $server = $this->makeServer($this->makeHandler());
        // No id → notification.
        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'method' => 'session/cancel', 'params' => ['sessionId' => 'sid']]));
        $this->assertSame([], $this->readOutput());
    }

    public function test_cancel_then_prompt_returns_cancelled(): void
    {
        $handler = $this->makeHandler();
        $server = $this->makeServer($handler);

        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'session/new', 'params' => ['cwd' => '/tmp']]));
        $sessionId = $this->readOutput()[0]['result']['sessionId'];

        // Notification cancel
        ftruncate($this->out, 0);
        rewind($this->out);
        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'method' => 'session/cancel', 'params' => ['sessionId' => $sessionId]]));
        $this->assertTrue($handler->wasCancelled($sessionId));

        // Prompt should now short-circuit.
        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'session/prompt', 'params' => ['sessionId' => $sessionId]]));
        $out = $this->readOutput();
        $this->assertSame('cancelled', $out[0]['result']['stopReason']);
    }

    public function test_load_unknown_session_returns_error(): void
    {
        $server = $this->makeServer($this->makeHandler());
        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'session/load', 'params' => ['sessionId' => 'nope']]));
        $out = $this->readOutput();
        $this->assertSame(Protocol::ERR_SESSION_NOT_FOUND, $out[0]['error']['code']);
    }

    public function test_handler_exception_surfaces_as_internal_error(): void
    {
        $handler = $this->makeHandler(fn () => throw new \RuntimeException('boom'));
        $server = $this->makeServer($handler);

        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'session/new', 'params' => ['cwd' => '/tmp']]));
        $sessionId = $this->readOutput()[0]['result']['sessionId'];

        ftruncate($this->out, 0);
        rewind($this->out);
        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'session/prompt', 'params' => ['sessionId' => $sessionId]]));
        $out = $this->readOutput();
        $this->assertSame(Protocol::ERR_INTERNAL, $out[0]['error']['code']);
        $this->assertSame('boom', $out[0]['error']['message']);
    }

    public function test_acp_exception_carries_its_code(): void
    {
        $handler = $this->makeHandler(fn () => throw new AcpException('need auth', Protocol::ERR_AUTH_REQUIRED));
        $server = $this->makeServer($handler);

        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'session/new', 'params' => ['cwd' => '/tmp']]));
        $sessionId = $this->readOutput()[0]['result']['sessionId'];

        ftruncate($this->out, 0);
        rewind($this->out);
        $server->handleLine(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'session/prompt', 'params' => ['sessionId' => $sessionId]]));
        $out = $this->readOutput();
        $this->assertSame(Protocol::ERR_AUTH_REQUIRED, $out[0]['error']['code']);
    }
}
