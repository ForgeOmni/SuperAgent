<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\ContextManager;
use SuperAgent\Context\Message;
use SuperAgent\Context\MessageRole;
use SuperAgent\Context\MessageType;
use SuperAgent\Context\Strategies\CompressionResult;
use SuperAgent\Context\Strategies\ConversationCompressor;
use SuperAgent\Context\Strategies\MicroCompressor;
use SuperAgent\Context\TokenEstimator;
use SuperAgent\LLM\ProviderInterface;
use SuperAgent\LLM\Response;

class Phase5ContextTest extends TestCase
{
    public function testTokenEstimator(): void
    {
        $estimator = new TokenEstimator();
        
        // Test basic string estimation
        $text = "Hello, this is a test message.";
        $tokens = $estimator->estimateTokens($text);
        $this->assertGreaterThan(0, $tokens);
        $this->assertLessThan(20, $tokens); // Should be around 7-8 tokens
        
        // Test empty string
        $this->assertEquals(0, $estimator->estimateTokens(''));
        
        // Test with special characters
        $textWithNewlines = "Line 1\nLine 2\nLine 3";
        $tokensWithNewlines = $estimator->estimateTokens($textWithNewlines);
        $this->assertGreaterThan(5, $tokensWithNewlines);
    }
    
    public function testTokenEstimatorForMessages(): void
    {
        $estimator = new TokenEstimator();
        
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there! How can I help you?'],
            ['role' => 'user', 'content' => 'What is the weather?'],
        ];
        
        $tokens = $estimator->estimateMessagesTokens($messages);
        $this->assertGreaterThan(10, $tokens);
        $this->assertLessThan(50, $tokens);
    }
    
    public function testModelContextWindows(): void
    {
        $estimator = new TokenEstimator();
        
        $this->assertEquals(200_000, $estimator->getContextWindow('claude-3-opus'));
        $this->assertEquals(8_192, $estimator->getContextWindow('gpt-4'));
        $this->assertEquals(128_000, $estimator->getContextWindow('gpt-4-turbo'));
        $this->assertEquals(200_000, $estimator->getContextWindow('unknown-model')); // default
        
        // Test effective context window (with output buffer)
        $effective = $estimator->getEffectiveContextWindow('claude-3-opus');
        $this->assertLessThan(200_000, $effective);
    }
    
    public function testAutoCompactThreshold(): void
    {
        $estimator = new TokenEstimator();
        
        $threshold = $estimator->getAutoCompactThreshold('claude-3-opus');
        $this->assertGreaterThan(100_000, $threshold);
        $this->assertLessThan(200_000, $threshold);
        
        // Test with large message set
        $messages = [];
        for ($i = 0; $i < 1000; $i++) {
            $messages[] = [
                'role' => 'user',
                'content' => str_repeat('This is a long message. ', 100),
            ];
        }
        
        $shouldCompact = $estimator->shouldAutoCompact($messages, 'gpt-4');
        $this->assertTrue($shouldCompact);
    }
    
    public function testMessage(): void
    {
        $message = Message::user('Hello world');
        $this->assertEquals(MessageRole::USER, $message->role);
        $this->assertEquals('Hello world', $message->content);
        $this->assertNotEmpty($message->id);
        $this->assertNotEmpty($message->timestamp);
        
        $assistant = Message::assistant(['type' => 'text', 'text' => 'Response']);
        $this->assertEquals(MessageRole::ASSISTANT, $assistant->role);
        
        $boundary = Message::boundary('--- Boundary ---');
        $this->assertEquals(MessageType::BOUNDARY, $boundary->type);
        $this->assertTrue($boundary->metadata['is_boundary']);
        
        $summary = Message::summary('Summary content');
        $this->assertEquals(MessageType::SUMMARY, $summary->type);
    }
    
    public function testMessageToolDetection(): void
    {
        $toolUseMessage = new Message(
            role: MessageRole::ASSISTANT,
            content: [
                ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls']],
            ],
        );
        
        $this->assertTrue($toolUseMessage->isToolUse());
        $this->assertFalse($toolUseMessage->isToolResult());
        $this->assertEquals('Bash', $toolUseMessage->getToolName());
        
        $toolResultMessage = new Message(
            role: MessageRole::ASSISTANT,
            content: [
                ['type' => 'tool_result', 'tool_use' => ['name' => 'Read'], 'content' => 'File contents'],
            ],
        );
        
        $this->assertTrue($toolResultMessage->isToolResult());
        $this->assertFalse($toolResultMessage->isToolUse());
        $this->assertEquals('Read', $toolResultMessage->getToolName());
    }
    
    public function testCompressionConfig(): void
    {
        $config = new CompressionConfig();
        
        $this->assertEquals(10_000, $config->minTokens);
        $this->assertEquals(40_000, $config->maxTokens);
        $this->assertTrue($config->enableMicroCompact);
        $this->assertTrue($config->enableAutoCompact);
        
        // Test validation
        $errors = $config->validate();
        $this->assertEmpty($errors);
        
        // Test with invalid config
        $invalidConfig = new CompressionConfig(
            minTokens: -100,
            maxTokens: 50,
        );
        
        $errors = $invalidConfig->validate();
        $this->assertNotEmpty($errors);
        
        // Test tool checking
        $this->assertTrue($config->isCompactableTool('Bash'));
        $this->assertTrue($config->isCompactableTool('Read'));
        $this->assertFalse($config->isCompactableTool('UnknownTool'));
    }
    
    public function testMicroCompressor(): void
    {
        $estimator = new TokenEstimator();
        $config = new CompressionConfig(
            keepRecentMessages: 2,
            compactableTools: ['Read', 'Bash'],
        );
        
        $compressor = new MicroCompressor($estimator, $config);
        
        $this->assertEquals(1, $compressor->getPriority());
        $this->assertEquals('micro_compact', $compressor->getName());
        
        // Create messages with tool results
        $messages = [
            Message::user('Read the file'),
            new Message(
                role: MessageRole::ASSISTANT,
                content: [
                    ['type' => 'tool_result', 'tool_use' => ['name' => 'Read'], 
                     'content' => str_repeat('Long file content ', 500)],
                ],
            ),
            Message::user('What does it say?'),
            Message::assistant('The file contains...'),
            Message::user('Thanks'),
        ];
        
        $this->assertTrue($compressor->canCompress($messages));
        
        $result = $compressor->compress($messages);
        
        $this->assertInstanceOf(CompressionResult::class, $result);
        $this->assertGreaterThan(0, $result->tokensSaved);
        $this->assertNotNull($result->boundaryMessage);
        
        // Check that recent messages are preserved
        $preserved = $result->preservedMessages;
        $this->assertCount(5, $preserved);
        
        // Check that old tool result was cleared
        $clearedMessage = $preserved[1];
        $this->assertTrue($clearedMessage->metadata['content_cleared'] ?? false);
    }
    
    public function testConversationCompressor(): void
    {
        $estimator = new TokenEstimator();
        $config = new CompressionConfig(
            minMessages: 3,
            minTokens: 100, // Lower threshold for test
            keepRecentMessages: 2,
        );
        
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('generateResponse')
            ->willReturn(new Response(
                content: 'Summary: User asked about files and received information.',
                usage: ['total_tokens' => 100],
            ));
        
        $compressor = new ConversationCompressor($estimator, $config, $provider);
        
        $this->assertEquals(10, $compressor->getPriority());
        $this->assertEquals('conversation_summary', $compressor->getName());
        
        // Create longer messages to exceed token threshold
        $messages = [
            Message::user(str_repeat('First message content ', 50)),
            Message::assistant(str_repeat('First response content ', 50)),
            Message::user(str_repeat('Second message content ', 50)),
            Message::assistant(str_repeat('Second response content ', 50)),
            Message::user(str_repeat('Third message content ', 50)),
            Message::assistant(str_repeat('Third response content ', 50)),
        ];
        
        $this->assertTrue($compressor->canCompress($messages));
        
        $result = $compressor->compress($messages, ['keep_recent' => 2]);
        
        $this->assertInstanceOf(CompressionResult::class, $result);
        $this->assertCount(1, $result->compressedMessages); // Summary message
        $this->assertCount(2, $result->preservedMessages); // Last 2 messages
        $this->assertNotNull($result->boundaryMessage);
    }
    
    public function testContextManager(): void
    {
        $estimator = new TokenEstimator();
        $config = new CompressionConfig();
        
        $manager = new ContextManager($estimator, $config);
        
        // Add messages
        $manager->addMessage(Message::user('Hello'));
        $manager->addMessage(Message::assistant('Hi there!'));
        
        $this->assertCount(2, $manager->getMessages());
        
        // Test token counting
        $tokens = $manager->getTokenCount();
        $this->assertGreaterThan(0, $tokens);
        
        // Test message finding
        $userMessages = $manager->findMessages(['role' => MessageRole::USER]);
        $this->assertCount(1, $userMessages);
        
        // Test recent messages
        $recent = $manager->getRecentMessages(1);
        $this->assertCount(1, $recent);
        $this->assertEquals('Hi there!', $recent[0]->content);
        
        // Test statistics
        $stats = $manager->getStatistics();
        $this->assertEquals(2, $stats['message_count']);
        $this->assertGreaterThan(0, $stats['token_count']);
    }
    
    public function testContextManagerAutoCompact(): void
    {
        $estimator = $this->createMock(TokenEstimator::class);
        $estimator->method('estimateMessagesTokens')->willReturn(150_000);
        $estimator->method('estimateMessageTokens')->willReturn(100);
        $estimator->method('estimateTokens')->willReturn(50);
        $estimator->method('shouldAutoCompact')->willReturn(true);
        $estimator->method('getAutoCompactThreshold')->willReturn(100_000);
        
        $config = new CompressionConfig(
            minMessages: 2,
            keepRecentMessages: 1,
        );
        
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('generateResponse')
            ->willReturn(new Response(
                content: 'Summary of conversation',
                usage: ['total_tokens' => 50],
            ));
        
        $manager = new ContextManager($estimator, $config, $provider);
        
        // Add many messages
        for ($i = 0; $i < 10; $i++) {
            $manager->addMessage(Message::user("Message $i"));
            $manager->addMessage(Message::assistant("Response $i"));
        }
        
        $this->assertTrue($manager->shouldAutoCompact('claude-3-opus'));
        
        $result = $manager->autoCompact('claude-3-opus');
        $this->assertTrue($result);
        
        // Check that messages were compressed
        $stats = $manager->getStatistics();
        $this->assertNotEmpty($stats['compression_history']);
    }
    
    public function testCompressionResult(): void
    {
        $result = new CompressionResult(
            compressedMessages: [Message::summary('Summary')],
            preservedMessages: [Message::user('Recent message')],
            boundaryMessage: Message::boundary('--- Boundary ---'),
            tokensSaved: 1000,
            preCompactTokenCount: 5000,
            postCompactTokenCount: 4000,
        );
        
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(1000, $result->tokensSaved);
        
        $allMessages = $result->getAllMessages();
        $this->assertCount(3, $allMessages); // boundary + compressed + preserved
        $this->assertEquals(MessageType::BOUNDARY, $allMessages[0]->type);
        $this->assertEquals(MessageType::SUMMARY, $allMessages[1]->type);
    }
    
    public function testMessageClearingForSpace(): void
    {
        $original = new Message(
            role: MessageRole::ASSISTANT,
            content: str_repeat('Long content ', 100),
        );
        
        $cleared = $original->withClearedContent();
        
        $this->assertEquals('[Content cleared for space]', $cleared->content);
        $this->assertTrue($cleared->metadata['content_cleared']);
        $this->assertEquals($original->id, $cleared->id);
        $this->assertEquals($original->timestamp, $cleared->timestamp);
    }
}