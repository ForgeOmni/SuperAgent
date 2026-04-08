<?php

namespace SuperAgent\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use SuperAgent\Session\SqliteSessionStorage;

class SqliteSessionStorageTest extends TestCase
{
    private string $dbPath;
    private SqliteSessionStorage $storage;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite extension not available.');
        }

        $this->dbPath = sys_get_temp_dir() . '/superagent_test_' . uniqid() . '.db';
        $this->storage = new SqliteSessionStorage($this->dbPath);
    }

    protected function tearDown(): void
    {
        unset($this->storage);
        @unlink($this->dbPath);
        @unlink($this->dbPath . '-wal');
        @unlink($this->dbPath . '-shm');
    }

    public function test_save_and_load(): void
    {
        $this->storage->save('sess-1', [
            'cwd' => '/home/user/project',
            'model' => 'claude-sonnet-4-6',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
            ],
            'summary' => 'Greeting exchange',
        ]);

        $loaded = $this->storage->load('sess-1');
        $this->assertNotNull($loaded);
        $this->assertEquals('sess-1', $loaded['session_id']);
        $this->assertEquals('/home/user/project', $loaded['cwd']);
        $this->assertEquals('claude-sonnet-4-6', $loaded['model']);
        $this->assertCount(2, $loaded['messages']);
        $this->assertEquals('Greeting exchange', $loaded['summary']);
    }

    public function test_upsert_updates_existing(): void
    {
        $this->storage->save('sess-1', [
            'messages' => [['role' => 'user', 'content' => 'v1']],
            'summary' => 'version 1',
        ]);

        $this->storage->save('sess-1', [
            'messages' => [['role' => 'user', 'content' => 'v2']],
            'summary' => 'version 2',
        ]);

        $loaded = $this->storage->load('sess-1');
        $this->assertEquals('version 2', $loaded['summary']);
        $this->assertEquals(1, $this->storage->count());
    }

    public function test_load_returns_null_for_missing(): void
    {
        $this->assertNull($this->storage->load('nonexistent'));
    }

    public function test_load_latest(): void
    {
        $this->storage->save('sess-old', [
            'cwd' => '/project',
            'messages' => [['role' => 'user', 'content' => 'old']],
        ]);

        usleep(10000); // Small delay for updated_at ordering

        $this->storage->save('sess-new', [
            'cwd' => '/project',
            'messages' => [['role' => 'user', 'content' => 'new']],
        ]);

        $latest = $this->storage->loadLatest();
        $this->assertEquals('sess-new', $latest['session_id']);
    }

    public function test_load_latest_by_cwd(): void
    {
        $this->storage->save('sess-a', [
            'cwd' => '/project-a',
            'messages' => [['role' => 'user', 'content' => 'a']],
        ]);

        $this->storage->save('sess-b', [
            'cwd' => '/project-b',
            'messages' => [['role' => 'user', 'content' => 'b']],
        ]);

        $latest = $this->storage->loadLatest('/project-a');
        $this->assertEquals('sess-a', $latest['session_id']);
    }

    public function test_list_sessions(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->save("sess-{$i}", [
                'messages' => [['role' => 'user', 'content' => "msg {$i}"]],
            ]);
        }

        $list = $this->storage->listSessions(3);
        $this->assertCount(3, $list);
    }

    public function test_fts5_search(): void
    {
        $this->storage->save('sess-1', [
            'messages' => [
                ['role' => 'user', 'content' => 'How do I fix the authentication bug?'],
                ['role' => 'assistant', 'content' => 'Check the login controller.'],
            ],
        ]);

        $this->storage->save('sess-2', [
            'messages' => [
                ['role' => 'user', 'content' => 'Deploy to production'],
                ['role' => 'assistant', 'content' => 'Running deployment pipeline.'],
            ],
        ]);

        $results = $this->storage->search('authentication bug');
        $this->assertNotEmpty($results);
        $this->assertEquals('sess-1', $results[0]['session_id']);
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $this->storage->save('sess-1', [
            'messages' => [['role' => 'user', 'content' => 'hello world']],
        ]);

        $results = $this->storage->search('xyznonexistent');
        $this->assertEmpty($results);
    }

    public function test_delete_session(): void
    {
        $this->storage->save('sess-1', [
            'messages' => [['role' => 'user', 'content' => 'test']],
        ]);

        $this->assertTrue($this->storage->delete('sess-1'));
        $this->assertNull($this->storage->load('sess-1'));
        $this->assertEquals(0, $this->storage->count());
    }

    public function test_prune_by_count(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->storage->save("sess-{$i}", [
                'messages' => [['role' => 'user', 'content' => "msg {$i}"]],
            ]);
            usleep(1000);
        }

        $pruned = $this->storage->prune(maxSessions: 5, pruneAfterDays: 0);
        $this->assertEquals(5, $pruned);
        $this->assertEquals(5, $this->storage->count());
    }

    public function test_count(): void
    {
        $this->assertEquals(0, $this->storage->count());

        $this->storage->save('sess-1', ['messages' => []]);
        $this->storage->save('sess-2', ['messages' => []]);

        $this->assertEquals(2, $this->storage->count());
    }

    public function test_count_with_cwd_filter(): void
    {
        $this->storage->save('sess-1', ['cwd' => '/a', 'messages' => []]);
        $this->storage->save('sess-2', ['cwd' => '/b', 'messages' => []]);

        $this->assertEquals(1, $this->storage->count('/a'));
        $this->assertEquals(1, $this->storage->count('/b'));
    }
}
