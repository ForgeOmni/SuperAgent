<?php

namespace SuperAgent\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use SuperAgent\Context\CompactionShadowStore;
use SuperAgent\Harness\AutoCompactor;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Optimization\ToolResultCompactor;
use SuperAgent\Tools\Builtin\SessionQueryTool;

class CompactionShadowStoreTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/superagent-shadow-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->baseDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->baseDir)) {
            rmdir($this->baseDir);
        }
    }

    private function store(string $session = 'test-session', int $minBytes = 10): CompactionShadowStore
    {
        return new CompactionShadowStore($this->baseDir, $session, minContentBytes: $minBytes);
    }

    // ── record / get ──────────────────────────────────────────────

    public function testRecordAndGet(): void
    {
        $store = $this->store();
        $content = str_repeat("important tool output line\n", 20);

        $id = $store->record('test_source', 'unit_test', $content, 'tu_1', 'bash');

        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id);

        $entry = $store->get($id);
        $this->assertNotNull($entry);
        $this->assertSame($content, $entry['content']);
        $this->assertSame('bash', $entry['tool_name']);
        $this->assertSame(strlen($content), $entry['total']);
    }

    public function testChunkedGet(): void
    {
        $store = $this->store();
        $id = $store->record('s', 'r', '0123456789abcdefghij');

        $entry = $store->get($id, offset: 5, limit: 5);
        $this->assertSame('56789', $entry['content']);
        $this->assertSame(20, $entry['total']);
    }

    public function testBelowMinContentSkipped(): void
    {
        $store = $this->store(minBytes: 100);

        $this->assertNull($store->record('s', 'r', 'tiny'));
    }

    public function testDedupSameContentSameId(): void
    {
        $store = $this->store();
        $content = str_repeat('x', 50);

        $id1 = $store->record('s', 'r', $content, 'tu_1');
        $id2 = $store->record('s', 'r', $content, 'tu_1');

        $this->assertSame($id1, $id2);

        // Only one line in the JSONL file
        $files = glob($this->baseDir . '/session-*.jsonl');
        $this->assertCount(1, $files);
        $this->assertCount(1, file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function testGetRejectsMalformedId(): void
    {
        $store = $this->store();
        $store->record('s', 'r', str_repeat('x', 50));

        $this->assertNull($store->get('../../etc/passwd'));
        $this->assertNull($store->get('zzzz'));
        $this->assertNull($store->get('aaaaaaaaaaaaaaaa')); // well-formed but unknown
    }

    // ── search ────────────────────────────────────────────────────

    public function testSearchAcrossSessions(): void
    {
        $a = $this->store('session-a');
        $b = $this->store('session-b');
        $a->record('src_a', 'r', str_repeat('filler ', 10) . 'NEEDLE-ALPHA here');
        $b->record('src_b', 'r', str_repeat('filler ', 10) . 'NEEDLE-ALPHA there');

        $matches = $a->search('needle-alpha');

        $this->assertCount(2, $matches);
        $this->assertArrayHasKey('preview', $matches[0]);
        $this->assertArrayNotHasKey('content', $matches[0]);
        $this->assertStringContainsStringIgnoringCase('NEEDLE-ALPHA', $matches[0]['preview']);
    }

    public function testSearchNoMatch(): void
    {
        $store = $this->store();
        $store->record('s', 'r', str_repeat('haystack ', 10));

        $this->assertSame([], $store->search('missing-needle'));
    }

    // ── compactor wiring ──────────────────────────────────────────

    public function testToolResultCompactorShadowsBeforeTruncating(): void
    {
        $store = $this->store();
        $compactor = new ToolResultCompactor(
            preserveRecentTurns: 1,
            maxResultLength: 50,
            shadowStore: $store,
        );

        $bigContent = str_repeat('tool output with UNIQUE-MARKER data ', 20);

        $toolUseMsg = new \SuperAgent\Messages\AssistantMessage();
        $toolUseMsg->content = [\SuperAgent\Messages\ContentBlock::toolUse('tu_1', 'bash', [])];

        $doneMsg = new \SuperAgent\Messages\AssistantMessage();

        $messages = [
            $toolUseMsg,
            ToolResultMessage::fromResults([
                ['tool_use_id' => 'tu_1', 'content' => $bigContent, 'is_error' => false],
            ]),
            $doneMsg,
        ];

        $compacted = $compactor->compact($messages);

        // The compacted message carries a retrieval hint
        $compactedContent = json_encode($compacted[1]->toArray());
        $this->assertStringContainsString('session_query get id=', $compactedContent);

        // And the full content is retrievable
        preg_match('/id=([a-f0-9]{16})/', $compactedContent, $m);
        $entry = $store->get($m[1], limit: 100_000);
        $this->assertSame($bigContent, $entry['content']);
    }

    public function testAutoCompactorMicroCompactShadows(): void
    {
        $store = $this->store();
        $compactor = new AutoCompactor(
            preserveRecentResults: 0,
            truncateLength: 50,
            shadowStore: $store,
        );

        $bigContent = str_repeat('micro compact shadow test content ', 20);
        $messages = [
            ToolResultMessage::fromResults([
                ['tool_use_id' => 'tu_9', 'content' => $bigContent, 'is_error' => false],
            ]),
        ];

        $saved = $compactor->microCompact($messages);

        $this->assertGreaterThan(0, $saved);
        $this->assertStringContainsString('session_query get id=', json_encode($messages[0]->toArray()));
    }

    // ── SessionQueryTool ──────────────────────────────────────────

    public function testSessionQueryToolSearchAndGet(): void
    {
        $store = $this->store();
        $id = $store->record('test', 'r', str_repeat('pad ', 30) . 'FINDME-TOKEN in shadowed output', 'tu_1', 'grep');
        $tool = new SessionQueryTool($store);

        $search = $tool->execute(['action' => 'search', 'query' => 'FINDME-TOKEN']);
        $this->assertTrue($search->isSuccess());
        $this->assertStringContainsString("id={$id}", $search->contentAsString());
        $this->assertStringContainsString('tool=grep', $search->contentAsString());

        $get = $tool->execute(['action' => 'get', 'id' => $id]);
        $this->assertTrue($get->isSuccess());
        $this->assertStringContainsString('FINDME-TOKEN in shadowed output', $get->contentAsString());
    }

    public function testSessionQueryToolErrors(): void
    {
        $tool = new SessionQueryTool($this->store());

        $this->assertTrue($tool->execute(['action' => 'bogus'])->isError);
        $this->assertTrue($tool->execute(['action' => 'search'])->isError);
        $this->assertTrue($tool->execute(['action' => 'get'])->isError);
        $this->assertTrue($tool->execute(['action' => 'get', 'id' => 'aaaaaaaaaaaaaaaa'])->isError);
    }

    public function testSessionQueryToolNoMatches(): void
    {
        $tool = new SessionQueryTool($this->store());

        $result = $tool->execute(['action' => 'search', 'query' => 'nothing-here']);
        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('No matches', $result->contentAsString());
    }
}
