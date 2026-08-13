<?php

namespace SuperAgent\Tests\Unit\Spill;

use PHPUnit\Framework\TestCase;
use SuperAgent\Spill\LocalSpillStore;
use SuperAgent\Spill\SpillPolicy;
use SuperAgent\Spill\SpillRef;
use SuperAgent\Spill\SpillStoreInterface;
use SuperAgent\Tools\Builtin\SpillReadTool;

class SpillTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/superagent-spill-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->baseDir);
        }
    }

    // ── LocalSpillStore ───────────────────────────────────────────

    public function testSaveReadRoundtrip(): void
    {
        $store = new LocalSpillStore($this->baseDir, 'session-a');
        $content = str_repeat("line of output\n", 100);

        $ref = $store->save('bash', 'tu_1', $content);

        $this->assertInstanceOf(SpillRef::class, $ref);
        $this->assertSame(strlen($content), $ref->bytes);
        $this->assertMatchesRegularExpression('#^spill://[a-f0-9]{16}/[a-f0-9]{16}-bash\.txt$#', $ref->locator);

        $chunk = $store->read($ref->locator);
        $this->assertSame($content, $chunk['content']);
        $this->assertSame(strlen($content), $chunk['total']);
    }

    public function testChunkedRead(): void
    {
        $store = new LocalSpillStore($this->baseDir, 'session-a');
        $ref = $store->save('grep', 'tu_2', 'abcdefghij');

        $chunk = $store->read($ref->locator, offset: 3, limit: 4);
        $this->assertSame('defg', $chunk['content']);
        $this->assertSame(3, $chunk['offset']);
        $this->assertSame(4, $chunk['length']);
        $this->assertSame(10, $chunk['total']);

        $past = $store->read($ref->locator, offset: 50);
        $this->assertSame('', $past['content']);
    }

    public function testFileIsPrivate(): void
    {
        $store = new LocalSpillStore($this->baseDir, 'session-a');
        $ref = $store->save('bash', 'tu_3', 'secret output');

        $this->assertNotNull($ref);
        preg_match('#^spill://([a-f0-9]{16})/(.+)$#', $ref->locator, $m);
        $dir = $this->baseDir . '/session-' . $m[1];
        $file = $dir . '/' . $m[2];

        $this->assertSame(0700, fileperms($dir) & 0777);
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    public function testMalformedLocatorRejected(): void
    {
        $store = new LocalSpillStore($this->baseDir, 'session-a');

        foreach ([
            'spill://../../etc/passwd',
            'spill://abcd1234abcd1234/../escape.txt',
            'spill://abcd1234abcd1234/.hidden',
            '/etc/passwd',
            'spill://short/file.txt',
            'spill://abcd1234abcd1234/sub/dir.txt',
        ] as $bad) {
            try {
                $store->read($bad);
                $this->fail("Locator should have been rejected: {$bad}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Malformed spill locator', $e->getMessage());
            }
        }
    }

    public function testSymlinkRefused(): void
    {
        $store = new LocalSpillStore($this->baseDir, 'session-a');
        $ref = $store->save('bash', 'tu_4', 'real content');
        $this->assertNotNull($ref);

        preg_match('#^spill://([a-f0-9]{16})/(.+)$#', $ref->locator, $m);
        $dir = $this->baseDir . '/session-' . $m[1];
        $target = tempnam(sys_get_temp_dir(), 'spill-outside-');
        file_put_contents($target, 'outside content');
        $linkName = bin2hex(random_bytes(8)) . '-link.txt';
        symlink($target, $dir . '/' . $linkName);

        try {
            $this->expectException(\RuntimeException::class);
            $store->read("spill://{$m[1]}/{$linkName}");
        } finally {
            unlink($target);
        }
    }

    public function testForkedSessionCanReadParentLocator(): void
    {
        $parent = new LocalSpillStore($this->baseDir, 'parent-session');
        $ref = $parent->save('bash', 'tu_5', 'parent output');
        $this->assertNotNull($ref);

        // A child store over the same base dir resolves the parent's locator
        // because the locator embeds the owning session's key (copy-free
        // inheritance, dsh spill semantics).
        $child = new LocalSpillStore($this->baseDir, 'child-session');
        $this->assertTrue($child->exists($ref->locator));
        $this->assertSame('parent output', $child->read($ref->locator)['content']);
    }

    // ── SpillPolicy ───────────────────────────────────────────────

    public function testBelowThresholdPassesThrough(): void
    {
        $policy = new SpillPolicy(
            store: new LocalSpillStore($this->baseDir, 's'),
            thresholdChars: 100,
        );

        $this->assertSame('short output', $policy->apply('bash', 'tu', 'short output'));
    }

    public function testAboveThresholdSpillsWithPreview(): void
    {
        $policy = new SpillPolicy(
            store: $store = new LocalSpillStore($this->baseDir, 's'),
            thresholdChars: 100,
            previewHeadChars: 20,
            previewTailChars: 20,
        );

        $content = str_repeat('x', 5000) . 'TAIL-MARKER';
        $replaced = $policy->apply('bash', 'tu', $content);

        $this->assertStringContainsString('spilled to storage', $replaced);
        $this->assertStringContainsString('spill://', $replaced);
        $this->assertStringContainsString('TAIL-MARKER', $replaced);
        $this->assertLessThan(strlen($content), strlen($replaced));

        preg_match('#(spill://[a-f0-9]{16}/[A-Za-z0-9_.-]+)#', $replaced, $m);
        $this->assertSame($content, $store->read($m[1], limit: 100_000)['content']);
    }

    public function testSkipToolsNotSpilled(): void
    {
        $policy = new SpillPolicy(
            store: new LocalSpillStore($this->baseDir, 's'),
            thresholdChars: 10,
            skipTools: ['spill_read'],
        );

        $big = str_repeat('y', 100);
        $this->assertSame($big, $policy->apply('spill_read', 'tu', $big));
    }

    public function testSaveFailureKeepsInlineContent(): void
    {
        $failing = new class implements SpillStoreInterface {
            public function save(string $toolName, string $toolUseId, string $content): ?SpillRef
            {
                return null;
            }

            public function read(string $locator, int $offset = 0, int $limit = 20000): array
            {
                throw new \RuntimeException('unreachable');
            }

            public function exists(string $locator): bool
            {
                return false;
            }
        };

        $policy = new SpillPolicy(store: $failing, thresholdChars: 10);
        $big = str_repeat('z', 100);

        $this->assertSame($big, $policy->apply('bash', 'tu', $big));
    }

    public function testDisabledPolicyPassesThrough(): void
    {
        $policy = new SpillPolicy(
            store: new LocalSpillStore($this->baseDir, 's'),
            enabled: false,
            thresholdChars: 10,
        );

        $big = str_repeat('w', 100);
        $this->assertFalse($policy->isEnabled());
        $this->assertSame($big, $policy->apply('bash', 'tu', $big));
    }

    // ── SpillReadTool ─────────────────────────────────────────────

    public function testSpillReadToolReadsBack(): void
    {
        $store = new LocalSpillStore($this->baseDir, 's');
        $ref = $store->save('bash', 'tu', 'tool output body');
        $tool = new SpillReadTool($store);

        $result = $tool->execute(['locator' => $ref->locator]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('tool output body', $result->contentAsString());
        $this->assertStringContainsString($ref->locator, $result->contentAsString());
    }

    public function testSpillReadToolReportsRemaining(): void
    {
        $store = new LocalSpillStore($this->baseDir, 's');
        $ref = $store->save('bash', 'tu', str_repeat('a', 50));
        $tool = new SpillReadTool($store);

        $result = $tool->execute(['locator' => $ref->locator, 'limit' => 10]);

        $this->assertStringContainsString('bytes 0-10 of 50', $result->contentAsString());
        $this->assertStringContainsString('offset=10', $result->contentAsString());
    }

    public function testSpillReadToolErrors(): void
    {
        $tool = new SpillReadTool(new LocalSpillStore($this->baseDir, 's'));

        $this->assertTrue($tool->execute([])->isError);
        $this->assertTrue($tool->execute(['locator' => 'not-a-locator'])->isError);
        $this->assertTrue(
            $tool->execute(['locator' => 'spill://aaaaaaaaaaaaaaaa/bbbbbbbbbbbbbbbb-bash.txt'])->isError,
        );
    }
}
