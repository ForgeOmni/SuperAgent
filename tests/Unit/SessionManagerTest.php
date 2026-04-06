<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Session\SessionManager;

class SessionManagerTest extends TestCase
{
    private string $tmpDir;
    private SessionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/superagent_test_sessions_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->manager = new SessionManager($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
        parent::tearDown();
    }

    // ── Save & Load ───────────────────────────────────────────────

    public function testSaveAndLoadById(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ];

        $this->manager->save('sess-001', $messages, [
            'model' => 'claude-sonnet-4-6',
            'cwd' => '/project',
        ]);

        $data = $this->manager->loadById('sess-001');

        $this->assertNotNull($data);
        $this->assertEquals('sess-001', $data['session_id']);
        $this->assertEquals('/project', $data['cwd']);
        $this->assertEquals('claude-sonnet-4-6', $data['model']);
        $this->assertCount(2, $data['messages']);
        $this->assertEquals(2, $data['message_count']);
    }

    public function testSaveUpdatesLatest(): void
    {
        $this->manager->save('sess-A', [
            ['role' => 'user', 'content' => 'First session'],
        ]);

        $this->manager->save('sess-B', [
            ['role' => 'user', 'content' => 'Second session'],
        ]);

        $latest = $this->manager->loadLatest();
        $this->assertNotNull($latest);
        $this->assertEquals('sess-B', $latest['session_id']);
    }

    public function testLoadLatestReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->manager->loadLatest());
    }

    public function testLoadByIdReturnsNullForMissing(): void
    {
        $this->assertNull($this->manager->loadById('nonexistent'));
    }

    // ── Summary extraction ────────────────────────────────────────

    public function testSummaryExtractedFromFirstUserMessage(): void
    {
        $this->manager->save('sess-summary', [
            ['role' => 'user', 'content' => 'Please fix the login bug in auth.php'],
            ['role' => 'assistant', 'content' => 'Looking into it...'],
        ]);

        $data = $this->manager->loadById('sess-summary');
        $this->assertEquals('Please fix the login bug in auth.php', $data['summary']);
    }

    public function testSummaryTruncatedToMaxLength(): void
    {
        $longPrompt = str_repeat('A', 200);
        $this->manager->save('sess-long', [
            ['role' => 'user', 'content' => $longPrompt],
        ]);

        $data = $this->manager->loadById('sess-long');
        $this->assertLessThanOrEqual(120, mb_strlen($data['summary']));
    }

    public function testSummaryFromArrayContent(): void
    {
        $this->manager->save('sess-array', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Array content message']]],
        ]);

        $data = $this->manager->loadById('sess-array');
        $this->assertEquals('Array content message', $data['summary']);
    }

    public function testCustomSummaryOverridesExtraction(): void
    {
        $this->manager->save('sess-custom', [
            ['role' => 'user', 'content' => 'Some prompt'],
        ], ['summary' => 'My custom summary']);

        $data = $this->manager->loadById('sess-custom');
        $this->assertEquals('My custom summary', $data['summary']);
    }

    // ── List sessions ─────────────────────────────────────────────

    public function testListSessionsOrderedByUpdatedAt(): void
    {
        $this->manager->save('sess-old', [
            ['role' => 'user', 'content' => 'Old session'],
        ]);

        // Small delay to ensure different timestamps
        usleep(10_000);

        $this->manager->save('sess-new', [
            ['role' => 'user', 'content' => 'New session'],
        ]);

        $sessions = $this->manager->listSessions();
        $this->assertCount(2, $sessions);
        $this->assertEquals('sess-new', $sessions[0]['session_id']);
        $this->assertEquals('sess-old', $sessions[1]['session_id']);
    }

    public function testListSessionsRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->manager->save("sess-{$i}", [
                ['role' => 'user', 'content' => "Session {$i}"],
            ]);
            usleep(5_000);
        }

        $sessions = $this->manager->listSessions(3);
        $this->assertCount(3, $sessions);
    }

    public function testListSessionsExcludesMessages(): void
    {
        $this->manager->save('sess-list', [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'World'],
        ]);

        $sessions = $this->manager->listSessions();
        $this->assertArrayNotHasKey('messages', $sessions[0]);
        $this->assertEquals(2, $sessions[0]['message_count']);
    }

    public function testListSessionsEmpty(): void
    {
        $sessions = $this->manager->listSessions();
        $this->assertEmpty($sessions);
    }

    // ── CWD filtering ─────────────────────────────────────────────

    public function testLoadLatestByCwd(): void
    {
        $this->manager->save('sess-proj-a', [
            ['role' => 'user', 'content' => 'Project A'],
        ], ['cwd' => '/project-a']);

        $this->manager->save('sess-proj-b', [
            ['role' => 'user', 'content' => 'Project B'],
        ], ['cwd' => '/project-b']);

        $data = $this->manager->loadLatest('/project-a');
        $this->assertNotNull($data);
        $this->assertEquals('sess-proj-a', $data['session_id']);
    }

    public function testLoadLatestByCwdReturnsNullWhenNoMatch(): void
    {
        $this->manager->save('sess-x', [
            ['role' => 'user', 'content' => 'Hello'],
        ], ['cwd' => '/some-dir']);

        $data = $this->manager->loadLatest('/other-dir');
        $this->assertNull($data);
    }

    // ── Delete ────────────────────────────────────────────────────

    public function testDeleteSession(): void
    {
        $this->manager->save('sess-del', [
            ['role' => 'user', 'content' => 'Delete me'],
        ]);

        $this->assertTrue($this->manager->exists('sess-del'));
        $this->assertTrue($this->manager->delete('sess-del'));
        $this->assertFalse($this->manager->exists('sess-del'));
        $this->assertNull($this->manager->loadById('sess-del'));
    }

    public function testDeleteNonexistentReturnsFalse(): void
    {
        $this->assertFalse($this->manager->delete('no-such-session'));
    }

    // ── Pruning ───────────────────────────────────────────────────

    public function testPruneByMaxSessions(): void
    {
        $manager = new SessionManager($this->tmpDir, maxSessions: 3);

        for ($i = 0; $i < 6; $i++) {
            $manager->save("sess-prune-{$i}", [
                ['role' => 'user', 'content' => "Session {$i}"],
            ]);
            usleep(5_000);
        }

        $pruned = $manager->prune();
        $this->assertEquals(3, $pruned);

        $remaining = $manager->listSessions(0);
        $this->assertCount(3, $remaining);
    }

    public function testPruneByAge(): void
    {
        $manager = new SessionManager($this->tmpDir, pruneAfterDays: 1);

        // Save a session, then manually backdate its updated_at
        $manager->save('sess-old', [
            ['role' => 'user', 'content' => 'Old session'],
        ]);

        // Rewrite with old timestamp
        $path = $this->tmpDir . '/session-sess-old.json';
        $data = json_decode(file_get_contents($path), true);
        $data['updated_at'] = date('c', time() - 2 * 86400);
        file_put_contents($path, json_encode($data));

        // Save a recent session
        $manager->save('sess-new', [
            ['role' => 'user', 'content' => 'New session'],
        ]);

        $pruned = $manager->prune();
        $this->assertEquals(1, $pruned);
        $this->assertNull($manager->loadById('sess-old'));
        $this->assertNotNull($manager->loadById('sess-new'));
    }

    public function testPruneDisabledWhenZeroDays(): void
    {
        $manager = new SessionManager($this->tmpDir, maxSessions: 0, pruneAfterDays: 0);

        for ($i = 0; $i < 5; $i++) {
            $manager->save("sess-{$i}", [
                ['role' => 'user', 'content' => "Session {$i}"],
            ]);
        }

        $pruned = $manager->prune();
        $this->assertEquals(0, $pruned);
    }

    // ── Exists ────────────────────────────────────────────────────

    public function testExists(): void
    {
        $this->assertFalse($this->manager->exists('sess-x'));

        $this->manager->save('sess-x', [
            ['role' => 'user', 'content' => 'Test'],
        ]);

        $this->assertTrue($this->manager->exists('sess-x'));
    }

    // ── Session ID sanitization ───────────────────────────────────

    public function testSessionIdSanitized(): void
    {
        // Path traversal attempt should be sanitized
        $this->manager->save('../../../etc/passwd', [
            ['role' => 'user', 'content' => 'Nope'],
        ]);

        // Should not create a file outside storageDir
        $this->assertFileDoesNotExist('/etc/passwd.json');

        // Should still be loadable with the original ID
        $data = $this->manager->loadById('../../../etc/passwd');
        $this->assertNotNull($data);
    }

    // ── Metadata preservation ─────────────────────────────────────

    public function testMetadataPreserved(): void
    {
        $this->manager->save('sess-meta', [
            ['role' => 'user', 'content' => 'Hello'],
        ], [
            'model' => 'claude-opus-4',
            'cwd' => '/my/project',
            'system_prompt' => 'You are helpful.',
            'total_cost_usd' => 0.42,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]);

        $data = $this->manager->loadById('sess-meta');
        $this->assertEquals('claude-opus-4', $data['model']);
        $this->assertEquals('/my/project', $data['cwd']);
        $this->assertEquals('You are helpful.', $data['system_prompt']);
        $this->assertEquals(0.42, $data['total_cost_usd']);
        $this->assertEquals(100, $data['usage']['input_tokens']);
    }

    // ── Overwrite on re-save ──────────────────────────────────────

    public function testResaveSameSessionOverwrites(): void
    {
        $this->manager->save('sess-overwrite', [
            ['role' => 'user', 'content' => 'Turn 1'],
        ]);

        $this->manager->save('sess-overwrite', [
            ['role' => 'user', 'content' => 'Turn 1'],
            ['role' => 'assistant', 'content' => 'Response 1'],
            ['role' => 'user', 'content' => 'Turn 2'],
        ]);

        $data = $this->manager->loadById('sess-overwrite');
        $this->assertCount(3, $data['messages']);
        $this->assertEquals(3, $data['message_count']);
    }

    // ── fromConfig override pattern ───────────────────────────────

    public function testFromConfigOverrideEnablesWhenConfigDisabled(): void
    {
        $manager = SessionManager::fromConfig(overrides: [
            'enabled' => true,
            'storage_path' => $this->tmpDir,
        ]);

        $this->assertNotNull($manager, 'Override enabled=true should create manager even when config disabled');
    }

    public function testFromConfigReturnsNullWhenOverrideDisabled(): void
    {
        $manager = SessionManager::fromConfig(overrides: [
            'enabled' => false,
        ]);

        $this->assertNull($manager, 'Override enabled=false should return null');
    }

    public function testFromConfigOverrideMaxSessions(): void
    {
        $manager = SessionManager::fromConfig(overrides: [
            'enabled' => true,
            'storage_path' => $this->tmpDir,
            'max_sessions' => 2,
        ]);

        $this->assertNotNull($manager);

        // Create 4 sessions, prune should keep only 2
        for ($i = 0; $i < 4; $i++) {
            $manager->save("sess-override-{$i}", [
                ['role' => 'user', 'content' => "Session {$i}"],
            ]);
            usleep(5_000);
        }

        $pruned = $manager->prune();
        $this->assertEquals(2, $pruned);
        $this->assertCount(2, $manager->listSessions(0));
    }

    // ── Storage dir ───────────────────────────────────────────────

    public function testGetStorageDir(): void
    {
        $this->assertEquals($this->tmpDir, $this->manager->getStorageDir());
    }

    // ── Helper ────────────────────────────────────────────────────

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
