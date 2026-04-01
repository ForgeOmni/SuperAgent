<?php

declare(strict_types=1);

namespace Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use SuperAgent\Context\Message;
use SuperAgent\LLM\ProviderInterface;
use SuperAgent\LLM\Response;
use SuperAgent\Memory\AutoDreamConsolidator;
use SuperAgent\Memory\Memory;
use SuperAgent\Memory\SimpleCache;
use SuperAgent\Memory\MemoryConfig;
use SuperAgent\Memory\MemoryExtractor;
use SuperAgent\Memory\MemoryRetriever;
use SuperAgent\Memory\MemoryScope;
use SuperAgent\Memory\MemoryType;
use SuperAgent\Memory\Storage\MemoryStorage;

class Phase6MemoryTest extends TestCase
{
    private string $testPath;
    private static array $cache = [];
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testPath = sys_get_temp_dir() . '/test_memories_' . uniqid();
        if (!file_exists($this->testPath)) {
            mkdir($this->testPath, 0755, true);
        }
        self::$cache = [];
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->testPath)) {
            $this->deleteDirectory($this->testPath);
        }
        self::$cache = [];
    }
    
    private function deleteDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
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
    
    public function testMemoryTypes(): void
    {
        $this->assertEquals('user', MemoryType::USER->value);
        $this->assertEquals('feedback', MemoryType::FEEDBACK->value);
        $this->assertEquals('project', MemoryType::PROJECT->value);
        $this->assertEquals('reference', MemoryType::REFERENCE->value);
        
        $this->assertEquals(MemoryScope::PRIVATE, MemoryType::USER->getDefaultScope());
        $this->assertEquals(MemoryScope::TEAM, MemoryType::PROJECT->getDefaultScope());
        
        $this->assertTrue(MemoryType::FEEDBACK->requiresStructure());
        $this->assertTrue(MemoryType::PROJECT->requiresStructure());
        $this->assertFalse(MemoryType::USER->requiresStructure());
    }
    
    public function testMemoryCreation(): void
    {
        $memory = new Memory(
            id: 'test_memory',
            name: 'Test Memory',
            description: 'A test memory',
            type: MemoryType::USER,
            content: 'User is a developer',
        );
        
        $this->assertEquals('test_memory', $memory->id);
        $this->assertEquals('Test Memory', $memory->name);
        $this->assertEquals(MemoryType::USER, $memory->type);
        $this->assertEquals('User is a developer', $memory->content);
        $this->assertEquals(MemoryScope::PRIVATE, $memory->scope);
    }
    
    public function testMemoryMarkdown(): void
    {
        $memory = new Memory(
            id: 'test',
            name: 'Test Memory',
            description: 'Test description',
            type: MemoryType::FEEDBACK,
            content: "Don't use mocks.\n\n**Why:** Testing policy\n\n**How to apply:** Use real databases",
        );
        
        $markdown = $memory->toMarkdown();
        
        $this->assertStringContainsString('---', $markdown);
        $this->assertStringContainsString('name: Test Memory', $markdown);
        $this->assertStringContainsString('type: feedback', $markdown);
        $this->assertStringContainsString("Don't use mocks", $markdown);
        
        // Test parsing back
        $lines = explode("\n", $markdown);
        $this->assertEquals('---', $lines[0]);
    }
    
    public function testMemoryFromMarkdown(): void
    {
        $frontmatter = [
            'name' => 'User Preferences',
            'description' => 'User coding preferences',
            'type' => 'user',
            'scope' => 'private',
            'created_at' => '2024-01-01T00:00:00Z',
        ];
        
        $content = 'User prefers TypeScript over JavaScript';
        
        $memory = Memory::fromMarkdown('user_prefs', $frontmatter, $content);
        
        $this->assertEquals('user_prefs', $memory->id);
        $this->assertEquals('User Preferences', $memory->name);
        $this->assertEquals(MemoryType::USER, $memory->type);
        $this->assertEquals($content, $memory->content);
    }
    
    public function testMemoryAging(): void
    {
        Carbon::setTestNow('2024-01-15');
        
        $memory = new Memory(
            id: 'old',
            name: 'Old Memory',
            description: 'Old',
            type: MemoryType::PROJECT,
            content: 'Content',
            createdAt: Carbon::parse('2024-01-01'),
        );
        
        $this->assertEquals(14, $memory->getAgeInDays());
        $this->assertFalse($memory->isStale(30));
        $this->assertTrue($memory->isStale(10));
        
        Carbon::setTestNow(); // Reset
    }
    
    public function testMemoryStorage(): void
    {
        $storage = new MemoryStorage($this->testPath);
        
        $memory = new Memory(
            id: 'test_save',
            name: 'Test Save',
            description: 'Testing save',
            type: MemoryType::USER,
            content: 'Test content',
        );
        
        // Test save
        $storage->save($memory);
        
        $this->assertTrue(file_exists($this->testPath . '/user_test_save.md'));
        
        // Test load
        $loaded = $storage->load('test_save');
        $this->assertNotNull($loaded);
        $this->assertEquals('Test Save', $loaded->name);
        $this->assertEquals('Test content', $loaded->content);
        
        // Test loadAll
        $all = $storage->loadAll();
        $this->assertCount(1, $all);
        
        // Test delete
        $deleted = $storage->delete('test_save');
        $this->assertTrue($deleted);
        $this->assertFalse(file_exists($this->testPath . '/user_test_save.md'));
    }
    
    public function testMemoryIndex(): void
    {
        $storage = new MemoryStorage($this->testPath);
        
        // Save multiple memories
        $memories = [
            new Memory('user1', 'User Info', 'User description', MemoryType::USER, 'User content'),
            new Memory('feedback1', 'Feedback', 'Feedback desc', MemoryType::FEEDBACK, 'Feedback content'),
            new Memory('project1', 'Project', 'Project desc', MemoryType::PROJECT, 'Project content'),
        ];
        
        foreach ($memories as $memory) {
            $storage->save($memory);
        }
        
        $storage->updateIndex();
        
        $indexPath = $this->testPath . '/MEMORY.md';
        $this->assertTrue(file_exists($indexPath));
        
        $indexContent = file_get_contents($indexPath);
        $this->assertStringContainsString('# Memory Index', $indexContent);
        $this->assertStringContainsString('## Memories (3 total)', $indexContent);
        $this->assertStringContainsString('### User Memories', $indexContent);
        $this->assertStringContainsString('[User Info]', $indexContent);
    }
    
    public function testMemoryExtractor(): void
    {
        $storage = new MemoryStorage($this->testPath);
        $config = new MemoryConfig(minimumTokensBetweenUpdate: 100);
        
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('generateResponse')
            ->willReturn(new Response(
                content: "TYPE: user\nNAME: User Role\nDESCRIPTION: User is a developer\nCONTENT: User is a senior backend developer with Go experience\n---",
            ));
        
        $extractor = new MemoryExtractor($storage, $provider, $config);
        
        // Create conversation with enough tokens
        $messages = [
            Message::user(str_repeat('Hello world ', 50)),
            Message::assistant(str_repeat('Response ', 50)),
        ];
        
        $extracted = $extractor->extractFromConversation($messages);
        
        $this->assertCount(1, $extracted);
        $this->assertEquals('User Role', $extracted[0]->name);
        $this->assertEquals(MemoryType::USER, $extracted[0]->type);
    }
    
    public function testMemoryRetriever(): void
    {
        $storage = new MemoryStorage($this->testPath);
        $config = new MemoryConfig();
        
        // Save some memories
        $memories = [
            new Memory('go_expert', 'Go Expertise', 'User knows Go', MemoryType::USER, 'User has 10 years Go experience'),
            new Memory('testing_policy', 'Testing Policy', 'No mocks', MemoryType::FEEDBACK, "Don't use mocks"),
            new Memory('auth_rewrite', 'Auth Rewrite', 'OAuth migration', MemoryType::PROJECT, 'Migrating to OAuth 2.0'),
        ];
        
        foreach ($memories as $memory) {
            $storage->save($memory);
        }
        
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('generateResponse')
            ->willReturn(new Response(content: "go_expert\ntesting_policy"));
        
        $retriever = new MemoryRetriever($storage, $provider, $config);
        
        // Test relevance matching
        $relevant = $retriever->findRelevant('Tell me about Go and testing', 2);
        $this->assertCount(2, $relevant);
        
        // Test search
        $searchResults = $retriever->search('OAuth');
        $this->assertCount(1, $searchResults);
        $this->assertEquals('auth_rewrite', $searchResults[0]->id);
        
        // Test by type
        $userMemories = $retriever->getByType(MemoryType::USER);
        $this->assertCount(1, $userMemories);
        $this->assertEquals('go_expert', $userMemories[0]->id);
    }
    
    public function testMemoryConfig(): void
    {
        $config = new MemoryConfig(
            minimumTokensBetweenUpdate: 5000,
            autoDreamMinHours: 12,
            basePath: '~/custom/path',
        );
        
        $this->assertEquals(5000, $config->minimumTokensBetweenUpdate);
        $this->assertEquals(12, $config->autoDreamMinHours);
        
        // Test path expansion
        $projectPath = '/home/user/project';
        $basePath = $config->getBasePath($projectPath);
        $this->assertStringContainsString('custom/path', $basePath);
        
        // Test default path
        $defaultConfig = new MemoryConfig();
        $defaultPath = $defaultConfig->getBasePath('/home/user/my-project');
        $this->assertStringContainsString('.claude/projects', $defaultPath);
        $this->assertStringContainsString('home_user_my-project', $defaultPath);
    }
    
    public function testAutoDreamConsolidator(): void
    {
        $storage = new MemoryStorage($this->testPath);
        $config = new MemoryConfig(
            autoDreamMinHours: 0, // Allow immediate run
            autoDreamMinSessions: 1,
        );
        
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('generateResponse')
            ->willReturn(new Response(content: "TYPE: user\nNAME: Consolidated\nCONTENT: Consolidated memory"));
        
        $cache = new SimpleCache($this->testPath);
        $logger = new \Psr\Log\NullLogger();
        $consolidator = new AutoDreamConsolidator($storage, $provider, $config, $logger, $cache);
        
        // Set up conditions for run
        $consolidator->incrementSessionCount();
        
        // Test should run
        $shouldRun = $consolidator->shouldRun();
        $this->assertTrue($shouldRun);
        
        // Test run
        $result = $consolidator->run();
        $this->assertTrue($result);
        
        // Check that it won't run again immediately (locked)
        $shouldRunAgain = $consolidator->shouldRun();
        $this->assertFalse($shouldRunAgain);
    }
    
    public function testDailyLogs(): void
    {
        $storage = new MemoryStorage($this->testPath);
        
        // Write daily logs
        $storage->writeDailyLog('Morning: Started working on auth', new \DateTime('2024-01-01'));
        $storage->writeDailyLog('Evening: Completed auth module', new \DateTime('2024-01-01'));
        $storage->writeDailyLog('Started testing', new \DateTime('2024-01-02'));
        
        // Get daily logs
        $logs = $storage->getDailyLogs(30);
        
        $this->assertCount(2, $logs); // 2 days of logs
        $this->assertEquals('2024-01-02', $logs[0]['date']);
        $this->assertStringContainsString('Started testing', $logs[0]['content']);
        $this->assertStringContainsString('Morning: Started', $logs[1]['content']);
        $this->assertStringContainsString('Evening: Completed', $logs[1]['content']);
    }
    
    public function testMemoryScan(): void
    {
        $storage = new MemoryStorage($this->testPath);
        
        // Save memories
        $memories = [];
        for ($i = 1; $i <= 5; $i++) {
            $memory = new Memory(
                "memory_{$i}",
                "Memory {$i}",
                "Description {$i}",
                MemoryType::USER,
                "Content {$i}",
            );
            $storage->save($memory);
            $memories[] = $memory;
            usleep(1000); // Small delay to ensure different mtimes
        }
        
        $headers = $storage->scan();
        
        $this->assertCount(5, $headers);
        
        // Check structure
        $firstHeader = $headers[0];
        $this->assertArrayHasKey('filename', $firstHeader);
        $this->assertArrayHasKey('filePath', $firstHeader);
        $this->assertArrayHasKey('mtimeMs', $firstHeader);
        $this->assertArrayHasKey('description', $firstHeader);
        $this->assertArrayHasKey('type', $firstHeader);
        $this->assertArrayHasKey('name', $firstHeader);
        
        // Should be sorted by mtime desc
        $this->assertGreaterThanOrEqual($headers[1]['mtimeMs'], $headers[0]['mtimeMs']);
    }
}