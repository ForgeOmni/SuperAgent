<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\AgentCompleteEvent;
use SuperAgent\Harness\BackendProtocol;
use SuperAgent\Harness\CompactionEvent;
use SuperAgent\Harness\ErrorEvent;
use SuperAgent\Harness\FrontendRequest;
use SuperAgent\Harness\StatusEvent;
use SuperAgent\Harness\TextDeltaEvent;
use SuperAgent\Harness\ToolCompletedEvent;
use SuperAgent\Harness\ToolStartedEvent;
use SuperAgent\Harness\TurnCompleteEvent;
use SuperAgent\Messages\AssistantMessage;

class BackendProtocolTest extends TestCase
{
    /**
     * Create a BackendProtocol with in-memory streams for testing.
     *
     * @return array{protocol: BackendProtocol, output: resource, input: resource}
     */
    private function makeProtocol(string $inputData = ''): array
    {
        $output = fopen('php://memory', 'r+');
        $input = fopen('php://memory', 'r+');

        if ($inputData !== '') {
            fwrite($input, $inputData);
            rewind($input);
        }

        $protocol = new BackendProtocol($output, $input);

        return ['protocol' => $protocol, 'output' => $output, 'input' => $input];
    }

    /**
     * Read everything written to the output stream.
     */
    private function readOutput($output): string
    {
        rewind($output);
        return stream_get_contents($output);
    }

    /**
     * Parse a single SAJSON line from output into an array.
     */
    private function parseLine(string $line): array
    {
        $this->assertStringStartsWith(BackendProtocol::PREFIX, $line);
        $json = substr($line, strlen(BackendProtocol::PREFIX));
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        return $data;
    }

    // ── emit() ───────────────────────────────────────────────────

    public function testEmitWritesCorrectPrefixAndJson(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emit('test_event', ['foo' => 'bar']);

        $raw = $this->readOutput($out);
        $this->assertStringStartsWith('SAJSON:', $raw);
        $this->assertStringEndsWith("\n", $raw);

        $data = $this->parseLine(trim($raw));
        $this->assertEquals('test_event', $data['type']);
        $this->assertEquals('bar', $data['foo']);
        $this->assertArrayHasKey('ts', $data);
        $this->assertIsFloat($data['ts']);
    }

    public function testEmitIncludesTimestamp(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $before = microtime(true);
        $proto->emit('ping');
        $after = microtime(true);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertGreaterThanOrEqual($before, $data['ts']);
        $this->assertLessThanOrEqual($after, $data['ts']);
    }

    // ── emitReady ────────────────────────────────────────────────

    public function testEmitReady(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitReady(['model' => 'claude', 'version' => '1.0']);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('ready', $data['type']);
        $this->assertEquals('claude', $data['state']['model']);
        $this->assertEquals('1.0', $data['state']['version']);
    }

    // ── emitAssistantDelta ───────────────────────────────────────

    public function testEmitAssistantDelta(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitAssistantDelta('Hello world');

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('assistant_delta', $data['type']);
        $this->assertEquals('Hello world', $data['text']);
    }

    // ── emitAssistantComplete ────────────────────────────────────

    public function testEmitAssistantComplete(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitAssistantComplete('Full response', ['input_tokens' => 100, 'output_tokens' => 50]);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('assistant_complete', $data['type']);
        $this->assertEquals('Full response', $data['text']);
        $this->assertEquals(100, $data['usage']['input_tokens']);
    }

    public function testEmitAssistantCompleteNullUsage(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitAssistantComplete('Done');

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertNull($data['usage']);
    }

    // ── emitToolStarted ──────────────────────────────────────────

    public function testEmitToolStarted(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitToolStarted('bash', 'tu_001', ['command' => 'ls -la']);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('tool_started', $data['type']);
        $this->assertEquals('bash', $data['tool_name']);
        $this->assertEquals('tu_001', $data['tool_use_id']);
        $this->assertEquals(['command' => 'ls -la'], $data['input']);
    }

    // ── emitToolCompleted ────────────────────────────────────────

    public function testEmitToolCompleted(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitToolCompleted('bash', 'tu_001', 'file1.txt\nfile2.txt', false);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('tool_completed', $data['type']);
        $this->assertEquals('bash', $data['tool_name']);
        $this->assertEquals('tu_001', $data['tool_use_id']);
        $this->assertEquals('file1.txt\nfile2.txt', $data['output']);
        $this->assertFalse($data['is_error']);
    }

    public function testEmitToolCompletedWithError(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitToolCompleted('bash', 'tu_err', 'command not found', true);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertTrue($data['is_error']);
    }

    // ── emitStatus ───────────────────────────────────────────────

    public function testEmitStatus(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitStatus('Processing...', ['step' => 3]);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('status', $data['type']);
        $this->assertEquals('Processing...', $data['message']);
        $this->assertEquals(3, $data['data']['step']);
    }

    // ── emitError ────────────────────────────────────────────────

    public function testEmitError(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitError('Rate limited', true, 'rate_limit');

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('error', $data['type']);
        $this->assertEquals('Rate limited', $data['message']);
        $this->assertTrue($data['recoverable']);
        $this->assertEquals('rate_limit', $data['code']);
    }

    public function testEmitErrorDefaults(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitError('Something broke');

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertTrue($data['recoverable']);
        $this->assertNull($data['code']);
    }

    // ── emitStateUpdate ──────────────────────────────────────────

    public function testEmitStateUpdate(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitStateUpdate(['turn' => 5, 'cost' => 0.03]);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('state_update', $data['type']);
        $this->assertEquals(5, $data['state']['turn']);
        $this->assertEquals(0.03, $data['state']['cost']);
    }

    // ── emitModalRequest ─────────────────────────────────────────

    public function testEmitModalRequest(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitModalRequest('req_42', 'permission', ['tool' => 'bash', 'command' => 'rm -rf /']);

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('modal_request', $data['type']);
        $this->assertEquals('req_42', $data['request_id']);
        $this->assertEquals('permission', $data['modal_type']);
        $this->assertEquals('bash', $data['options']['tool']);
    }

    // ── readRequest ──────────────────────────────────────────────

    public function testReadRequestParsesValidJson(): void
    {
        $json = json_encode(['type' => 'submit_line', 'data' => ['line' => 'hello']]) . "\n";
        ['protocol' => $proto] = $this->makeProtocol($json);

        $result = $proto->readRequest();

        $this->assertIsArray($result);
        $this->assertEquals('submit_line', $result['type']);
        $this->assertEquals('hello', $result['data']['line']);
    }

    public function testReadRequestReturnsNullForInvalidJson(): void
    {
        ['protocol' => $proto] = $this->makeProtocol("not valid json\n");

        $result = $proto->readRequest();
        $this->assertNull($result);
    }

    public function testReadRequestReturnsNullForEmptyLine(): void
    {
        ['protocol' => $proto] = $this->makeProtocol("\n");

        $result = $proto->readRequest();
        $this->assertNull($result);
    }

    public function testReadRequestReturnsNullForMissingType(): void
    {
        $json = json_encode(['data' => 'no type field']) . "\n";
        ['protocol' => $proto] = $this->makeProtocol($json);

        $result = $proto->readRequest();
        $this->assertNull($result);
    }

    public function testReadRequestReturnsNullOnStreamEnd(): void
    {
        ['protocol' => $proto] = $this->makeProtocol('');

        $result = $proto->readRequest();
        $this->assertNull($result);
    }

    // ── readRequestWithTimeout ───────────────────────────────────

    public function testReadRequestWithTimeoutReturnsNullOnTimeout(): void
    {
        // Use a socket pair which supports stream_select, unlike php://memory
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        $output = fopen('php://memory', 'r+');
        // $sockets[0] is the input side; nothing will be written to $sockets[1]
        $proto = new BackendProtocol($output, $sockets[0]);

        $start = microtime(true);
        $result = $proto->readRequestWithTimeout(0.05);
        $elapsed = microtime(true) - $start;

        $this->assertNull($result);
        $this->assertGreaterThanOrEqual(0.04, $elapsed);

        fclose($sockets[0]);
        fclose($sockets[1]);
    }

    // ── createStreamBridge ───────────────────────────────────────

    public function testStreamBridgeMapsTextDeltaEvent(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $bridge = $proto->createStreamBridge();

        $bridge(new TextDeltaEvent('hello'));

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('assistant_delta', $data['type']);
        $this->assertEquals('hello', $data['text']);
    }

    public function testStreamBridgeMapsToolStartedEvent(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $bridge = $proto->createStreamBridge();

        $bridge(new ToolStartedEvent('read', 'tu_100', ['path' => '/foo']));

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('tool_started', $data['type']);
        $this->assertEquals('read', $data['tool_name']);
        $this->assertEquals('tu_100', $data['tool_use_id']);
    }

    public function testStreamBridgeMapsToolCompletedEvent(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $bridge = $proto->createStreamBridge();

        $bridge(new ToolCompletedEvent('bash', 'tu_200', 'output text', true));

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('tool_completed', $data['type']);
        $this->assertEquals('bash', $data['tool_name']);
        $this->assertTrue($data['is_error']);
    }

    public function testStreamBridgeMapsTurnCompleteEvent(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $bridge = $proto->createStreamBridge();

        $msg = new AssistantMessage();
        $bridge(new TurnCompleteEvent($msg, 2, ['input_tokens' => 50]));

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('assistant_complete', $data['type']);
        $this->assertEquals(['input_tokens' => 50], $data['usage']);
    }

    public function testStreamBridgeMapsCompactionEvent(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $bridge = $proto->createStreamBridge();

        $bridge(new CompactionEvent('micro', 3000, 'truncation'));

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('status', $data['type']);
        $this->assertEquals('Context compacted', $data['message']);
        $this->assertEquals('micro', $data['data']['tier']);
        $this->assertEquals(3000, $data['data']['tokens_saved']);
    }

    public function testStreamBridgeMapsStatusEvent(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $bridge = $proto->createStreamBridge();

        $bridge(new StatusEvent('Retrying...', ['attempt' => 2]));

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('status', $data['type']);
        $this->assertEquals('Retrying...', $data['message']);
    }

    public function testStreamBridgeMapsErrorEvent(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $bridge = $proto->createStreamBridge();

        $bridge(new ErrorEvent('API down', false, 'api_error'));

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('error', $data['type']);
        $this->assertEquals('API down', $data['message']);
        $this->assertFalse($data['recoverable']);
        $this->assertEquals('api_error', $data['code']);
    }

    public function testStreamBridgeMapsAgentCompleteEvent(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();
        $bridge = $proto->createStreamBridge();

        $bridge(new AgentCompleteEvent(10, 1.25));

        $data = $this->parseLine(trim($this->readOutput($out)));
        $this->assertEquals('agent_complete', $data['type']);
        $this->assertEquals(10, $data['total_turns']);
        $this->assertEquals(1.25, $data['total_cost_usd']);
    }

    // ── Multiple emissions ───────────────────────────────────────

    public function testMultipleEmitsProduceMultipleLines(): void
    {
        ['protocol' => $proto, 'output' => $out] = $this->makeProtocol();

        $proto->emitAssistantDelta('one');
        $proto->emitAssistantDelta('two');
        $proto->emitAssistantDelta('three');

        $lines = array_filter(explode("\n", $this->readOutput($out)));
        $this->assertCount(3, $lines);

        foreach ($lines as $line) {
            $this->assertStringStartsWith('SAJSON:', $line);
        }
    }

    // ── FrontendRequest ──────────────────────────────────────────

    public function testFrontendRequestFromArray(): void
    {
        $req = FrontendRequest::fromArray([
            'type' => 'submit_line',
            'data' => ['line' => 'help me'],
        ]);

        $this->assertInstanceOf(FrontendRequest::class, $req);
        $this->assertEquals('submit_line', $req->type);
        $this->assertEquals(['line' => 'help me'], $req->data);
    }

    public function testFrontendRequestFromArrayReturnsNullWithoutType(): void
    {
        $req = FrontendRequest::fromArray(['data' => 'no type']);
        $this->assertNull($req);
    }

    public function testFrontendRequestFromArrayDefaultsDataToEmpty(): void
    {
        $req = FrontendRequest::fromArray(['type' => 'ping']);
        $this->assertNotNull($req);
        $this->assertEquals([], $req->data);
    }

    public function testFrontendRequestIsSubmit(): void
    {
        $req = new FrontendRequest(FrontendRequest::TYPE_SUBMIT);
        $this->assertTrue($req->isSubmit());
        $this->assertFalse($req->isPermission());
        $this->assertFalse($req->isQuestion());
    }

    public function testFrontendRequestIsPermission(): void
    {
        $req = new FrontendRequest(FrontendRequest::TYPE_PERMISSION);
        $this->assertFalse($req->isSubmit());
        $this->assertTrue($req->isPermission());
        $this->assertFalse($req->isQuestion());
    }

    public function testFrontendRequestIsQuestion(): void
    {
        $req = new FrontendRequest(FrontendRequest::TYPE_QUESTION);
        $this->assertFalse($req->isSubmit());
        $this->assertFalse($req->isPermission());
        $this->assertTrue($req->isQuestion());
    }

    public function testFrontendRequestGetLine(): void
    {
        $req = new FrontendRequest('submit_line', ['line' => 'explain this code']);
        $this->assertEquals('explain this code', $req->getLine());
    }

    public function testFrontendRequestGetLineReturnsNullWhenMissing(): void
    {
        $req = new FrontendRequest('submit_line', []);
        $this->assertNull($req->getLine());
    }

    public function testFrontendRequestGetRequestId(): void
    {
        $req = new FrontendRequest('permission_response', ['request_id' => 'req_99']);
        $this->assertEquals('req_99', $req->getRequestId());
    }

    public function testFrontendRequestGetValue(): void
    {
        $req = new FrontendRequest('permission_response', ['value' => true]);
        $this->assertTrue($req->getValue());
    }

    public function testFrontendRequestGetValueReturnsNullWhenMissing(): void
    {
        $req = new FrontendRequest('permission_response', []);
        $this->assertNull($req->getValue());
    }

    public function testFrontendRequestConstants(): void
    {
        $this->assertEquals('submit_line', FrontendRequest::TYPE_SUBMIT);
        $this->assertEquals('permission_response', FrontendRequest::TYPE_PERMISSION);
        $this->assertEquals('question_response', FrontendRequest::TYPE_QUESTION);
        $this->assertEquals('select_command', FrontendRequest::TYPE_SELECT);
        $this->assertEquals('apply_select_command', FrontendRequest::TYPE_APPLY_SELECT);
    }
}
