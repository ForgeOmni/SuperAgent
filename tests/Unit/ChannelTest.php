<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Channels\BaseChannel;
use SuperAgent\Channels\ChannelInterface;
use SuperAgent\Channels\ChannelManager;
use SuperAgent\Channels\InboundMessage;
use SuperAgent\Channels\MessageBus;
use SuperAgent\Channels\OutboundMessage;
use SuperAgent\Channels\WebhookChannel;

class ChannelTest extends TestCase
{
    // ── InboundMessage ───────────────────────────────────────────

    public function testInboundMessageConstruction(): void
    {
        $msg = new InboundMessage(
            channel: 'slack',
            senderId: 'U123',
            chatId: 'C456',
            content: 'hello',
            timestamp: '2026-01-01T00:00:00+00:00',
        );

        $this->assertSame('slack', $msg->channel);
        $this->assertSame('U123', $msg->senderId);
        $this->assertSame('C456', $msg->chatId);
        $this->assertSame('hello', $msg->content);
        $this->assertSame('2026-01-01T00:00:00+00:00', $msg->timestamp);
        $this->assertNull($msg->sessionKey);
        $this->assertSame([], $msg->media);
        $this->assertSame([], $msg->metadata);
    }

    public function testInboundMessageGetSessionKeyDefault(): void
    {
        $msg = new InboundMessage(
            channel: 'telegram',
            senderId: 'user1',
            chatId: 'chat99',
            content: 'hi',
            timestamp: '2026-01-01T00:00:00+00:00',
        );

        $this->assertSame('telegram:chat99', $msg->getSessionKey());
    }

    public function testInboundMessageGetSessionKeyCustom(): void
    {
        $msg = new InboundMessage(
            channel: 'slack',
            senderId: 'U1',
            chatId: 'C2',
            content: 'x',
            timestamp: '2026-01-01T00:00:00+00:00',
            sessionKey: 'custom-key-abc',
        );

        $this->assertSame('custom-key-abc', $msg->getSessionKey());
    }

    public function testInboundMessageWithMediaAndMetadata(): void
    {
        $msg = new InboundMessage(
            channel: 'discord',
            senderId: 'u1',
            chatId: 'c1',
            content: 'look at this',
            timestamp: '2026-01-01T00:00:00+00:00',
            media: ['image.png'],
            metadata: ['thread_id' => 'T1'],
        );

        $this->assertSame(['image.png'], $msg->media);
        $this->assertSame(['thread_id' => 'T1'], $msg->metadata);
    }

    // ── OutboundMessage ──────────────────────────────────────────

    public function testOutboundMessageConstruction(): void
    {
        $msg = new OutboundMessage(
            channel: 'slack',
            chatId: 'C789',
            content: 'response text',
        );

        $this->assertSame('slack', $msg->channel);
        $this->assertSame('C789', $msg->chatId);
        $this->assertSame('response text', $msg->content);
        $this->assertNull($msg->replyToMessageId);
        $this->assertSame([], $msg->media);
        $this->assertSame([], $msg->metadata);
    }

    public function testOutboundMessageWithReplyAndMetadata(): void
    {
        $msg = new OutboundMessage(
            channel: 'telegram',
            chatId: 'chat1',
            content: 'reply here',
            replyToMessageId: 'msg42',
            media: ['file.pdf'],
            metadata: ['format' => 'markdown'],
        );

        $this->assertSame('msg42', $msg->replyToMessageId);
        $this->assertSame(['file.pdf'], $msg->media);
        $this->assertSame(['format' => 'markdown'], $msg->metadata);
    }

    // ── MessageBus ───────────────────────────────────────────────

    public function testMessageBusInboundPublishConsume(): void
    {
        $bus = new MessageBus();
        $msg = new InboundMessage('ch', 's', 'c', 'text', 'ts');

        $this->assertNull($bus->consumeInbound());

        $bus->publishInbound($msg);
        $this->assertSame(1, $bus->inboundSize());

        $consumed = $bus->consumeInbound();
        $this->assertSame($msg, $consumed);
        $this->assertSame(0, $bus->inboundSize());
    }

    public function testMessageBusOutboundPublishConsume(): void
    {
        $bus = new MessageBus();
        $msg = new OutboundMessage('ch', 'c', 'text');

        $this->assertNull($bus->consumeOutbound());

        $bus->publishOutbound($msg);
        $this->assertSame(1, $bus->outboundSize());

        $consumed = $bus->consumeOutbound();
        $this->assertSame($msg, $consumed);
        $this->assertSame(0, $bus->outboundSize());
    }

    public function testMessageBusDrainInbound(): void
    {
        $bus = new MessageBus();
        $msg1 = new InboundMessage('a', 's', 'c', '1', 'ts');
        $msg2 = new InboundMessage('b', 's', 'c', '2', 'ts');

        $bus->publishInbound($msg1);
        $bus->publishInbound($msg2);

        $drained = $bus->drainInbound();
        $this->assertCount(2, $drained);
        $this->assertSame($msg1, $drained[0]);
        $this->assertSame($msg2, $drained[1]);
        $this->assertSame(0, $bus->inboundSize());
    }

    public function testMessageBusDrainOutbound(): void
    {
        $bus = new MessageBus();
        $msg1 = new OutboundMessage('a', 'c', '1');
        $msg2 = new OutboundMessage('b', 'c', '2');

        $bus->publishOutbound($msg1);
        $bus->publishOutbound($msg2);

        $drained = $bus->drainOutbound();
        $this->assertCount(2, $drained);
        $this->assertSame($msg1, $drained[0]);
        $this->assertSame($msg2, $drained[1]);
        $this->assertSame(0, $bus->outboundSize());
    }

    public function testMessageBusSizeTracking(): void
    {
        $bus = new MessageBus();
        $this->assertSame(0, $bus->inboundSize());
        $this->assertSame(0, $bus->outboundSize());

        $bus->publishInbound(new InboundMessage('ch', 's', 'c', 't', 'ts'));
        $bus->publishInbound(new InboundMessage('ch', 's', 'c', 't', 'ts'));
        $bus->publishOutbound(new OutboundMessage('ch', 'c', 't'));

        $this->assertSame(2, $bus->inboundSize());
        $this->assertSame(1, $bus->outboundSize());
    }

    public function testMessageBusDrainEmptyReturnsEmptyArray(): void
    {
        $bus = new MessageBus();
        $this->assertSame([], $bus->drainInbound());
        $this->assertSame([], $bus->drainOutbound());
    }

    public function testMessageBusFifoOrder(): void
    {
        $bus = new MessageBus();
        $bus->publishInbound(new InboundMessage('ch', 's', 'c', 'first', 'ts'));
        $bus->publishInbound(new InboundMessage('ch', 's', 'c', 'second', 'ts'));

        $this->assertSame('first', $bus->consumeInbound()->content);
        $this->assertSame('second', $bus->consumeInbound()->content);
    }

    // ── BaseChannel.isAllowed ────────────────────────────────────

    public function testBaseChannelIsAllowedEmptyListDeniesAll(): void
    {
        $channel = $this->createConcreteChannel('test', true, []);
        $this->assertFalse($channel->isAllowed('anyone'));
    }

    public function testBaseChannelIsAllowedWildcardAllowsAll(): void
    {
        $channel = $this->createConcreteChannel('test', true, ['*']);
        $this->assertTrue($channel->isAllowed('anyone'));
        $this->assertTrue($channel->isAllowed('user123'));
    }

    public function testBaseChannelIsAllowedSpecificIds(): void
    {
        $channel = $this->createConcreteChannel('test', true, ['alice', 'bob']);
        $this->assertTrue($channel->isAllowed('alice'));
        $this->assertTrue($channel->isAllowed('bob'));
        $this->assertFalse($channel->isAllowed('charlie'));
    }

    public function testBaseChannelGetNameAndEnabled(): void
    {
        $channel = $this->createConcreteChannel('myChannel', false, []);
        $this->assertSame('myChannel', $channel->getName());
        $this->assertFalse($channel->isEnabled());
    }

    public function testBaseChannelHandleMessageDeniedSenderDoesNotPublish(): void
    {
        $bus = new MessageBus();
        $channel = $this->createConcreteChannel('test', true, ['allowed-user'], $bus);

        // Call handleMessage via the test helper (denied sender)
        $channel->testHandleMessage('denied-user', 'chat1', 'hello');

        $this->assertSame(0, $bus->inboundSize());
    }

    public function testBaseChannelHandleMessageAllowedSenderPublishes(): void
    {
        $bus = new MessageBus();
        $channel = $this->createConcreteChannel('test', true, ['allowed-user'], $bus);

        $channel->testHandleMessage('allowed-user', 'chat1', 'hello');

        $this->assertSame(1, $bus->inboundSize());
        $msg = $bus->consumeInbound();
        $this->assertSame('test', $msg->channel);
        $this->assertSame('allowed-user', $msg->senderId);
        $this->assertSame('hello', $msg->content);
    }

    // ── ChannelManager ───────────────────────────────────────────

    public function testChannelManagerRegisterAndGet(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $channel = $this->createConcreteChannel('slack', true, ['*']);
        $manager->register($channel);

        $this->assertSame($channel, $manager->getChannel('slack'));
        $this->assertNull($manager->getChannel('nonexistent'));
    }

    public function testChannelManagerGetEnabledChannels(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $enabled = $this->createConcreteChannel('slack', true, ['*']);
        $disabled = $this->createConcreteChannel('telegram', false, ['*']);

        $manager->register($enabled);
        $manager->register($disabled);

        $result = $manager->getEnabledChannels();
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('slack', $result);
    }

    public function testChannelManagerGetRegisteredNames(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $manager->register($this->createConcreteChannel('a', true, []));
        $manager->register($this->createConcreteChannel('b', false, []));

        $names = $manager->getRegisteredNames();
        $this->assertSame(['a', 'b'], $names);
    }

    public function testChannelManagerGetStatus(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $manager->register($this->createConcreteChannel('slack', true, []));
        $manager->register($this->createConcreteChannel('telegram', false, []));

        $status = $manager->getStatus();
        $this->assertSame(['enabled' => true], $status['slack']);
        $this->assertSame(['enabled' => false], $status['telegram']);
    }

    public function testChannelManagerStartAllOnlyStartsEnabled(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $enabled = $this->createMock(ChannelInterface::class);
        $enabled->method('getName')->willReturn('enabled');
        $enabled->method('isEnabled')->willReturn(true);
        $enabled->expects($this->once())->method('start');

        $disabled = $this->createMock(ChannelInterface::class);
        $disabled->method('getName')->willReturn('disabled');
        $disabled->method('isEnabled')->willReturn(false);
        $disabled->expects($this->never())->method('start');

        $manager->register($enabled);
        $manager->register($disabled);
        $manager->startAll();
    }

    public function testChannelManagerStopAllStopsAllChannels(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $ch1 = $this->createMock(ChannelInterface::class);
        $ch1->method('getName')->willReturn('ch1');
        $ch1->method('isEnabled')->willReturn(true);
        $ch1->expects($this->once())->method('stop');

        $ch2 = $this->createMock(ChannelInterface::class);
        $ch2->method('getName')->willReturn('ch2');
        $ch2->method('isEnabled')->willReturn(false);
        $ch2->expects($this->once())->method('stop');

        $manager->register($ch1);
        $manager->register($ch2);
        $manager->stopAll();
    }

    public function testChannelManagerDispatchOutbound(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getName')->willReturn('slack');
        $channel->method('isEnabled')->willReturn(true);
        $channel->expects($this->once())->method('send')->willReturn(true);

        $manager->register($channel);

        $bus->publishOutbound(new OutboundMessage('slack', 'C1', 'reply'));
        $count = $manager->dispatchOutbound();

        $this->assertSame(1, $count);
        $this->assertSame(0, $bus->outboundSize());
    }

    public function testChannelManagerDispatchOutboundSkipsDisabledChannel(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getName')->willReturn('slack');
        $channel->method('isEnabled')->willReturn(false);
        $channel->expects($this->never())->method('send');

        $manager->register($channel);

        $bus->publishOutbound(new OutboundMessage('slack', 'C1', 'reply'));
        $count = $manager->dispatchOutbound();

        $this->assertSame(0, $count);
    }

    public function testChannelManagerDispatchOutboundSkipsUnknownChannel(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $bus->publishOutbound(new OutboundMessage('nonexistent', 'C1', 'reply'));
        $count = $manager->dispatchOutbound();

        $this->assertSame(0, $count);
        $this->assertSame(0, $bus->outboundSize());
    }

    public function testChannelManagerFromConfig(): void
    {
        $bus = new MessageBus();
        $manager = ChannelManager::fromConfig(['channels' => []], $bus);

        $this->assertInstanceOf(ChannelManager::class, $manager);
        $this->assertSame([], $manager->getRegisteredNames());
    }

    public function testChannelManagerStartAllHandlesException(): void
    {
        $bus = new MessageBus();
        $manager = new ChannelManager($bus);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getName')->willReturn('broken');
        $channel->method('isEnabled')->willReturn(true);
        $channel->method('start')->willThrowException(new \RuntimeException('fail'));

        $manager->register($channel);

        // Should not throw — error is logged
        $manager->startAll();
        $this->assertTrue(true);
    }

    // ── WebhookChannel ───────────────────────────────────────────

    public function testWebhookChannelStartSetsRunning(): void
    {
        $channel = new WebhookChannel(
            'webhook', true, ['*'], null, 'https://example.com/hook'
        );

        $channel->start();
        // No public way to check running, but start should not throw
        $this->assertSame('webhook', $channel->getName());
    }

    public function testWebhookChannelSendToInvalidUrlReturnsFalse(): void
    {
        $channel = new WebhookChannel(
            'webhook', true, ['*'], null, 'http://0.0.0.0:1/nonexistent', [], 1,
        );

        $msg = new OutboundMessage('webhook', 'chat1', 'hello');
        $result = $channel->send($msg);
        $this->assertFalse($result);
    }

    public function testWebhookChannelHandleIncomingPublishesToBus(): void
    {
        $bus = new MessageBus();
        $channel = new WebhookChannel(
            'webhook', true, ['*'], $bus, 'https://example.com/hook'
        );

        $channel->handleIncoming([
            'sender_id' => 'user1',
            'chat_id' => 'chat1',
            'content' => 'hello from webhook',
        ]);

        $this->assertSame(1, $bus->inboundSize());
        $msg = $bus->consumeInbound();
        $this->assertSame('webhook', $msg->channel);
        $this->assertSame('user1', $msg->senderId);
        $this->assertSame('chat1', $msg->chatId);
        $this->assertSame('hello from webhook', $msg->content);
    }

    public function testWebhookChannelHandleIncomingDefaultValues(): void
    {
        $bus = new MessageBus();
        $channel = new WebhookChannel(
            'wh', true, ['*'], $bus, 'https://example.com/hook'
        );

        $channel->handleIncoming([]);

        $this->assertSame(1, $bus->inboundSize());
        $msg = $bus->consumeInbound();
        $this->assertSame('unknown', $msg->senderId);
        $this->assertSame('default', $msg->chatId);
        $this->assertSame('', $msg->content);
    }

    public function testWebhookChannelHandleIncomingDeniedSender(): void
    {
        $bus = new MessageBus();
        $channel = new WebhookChannel(
            'wh', true, ['allowed-only'], $bus, 'https://example.com/hook'
        );

        $channel->handleIncoming([
            'sender_id' => 'intruder',
            'chat_id' => 'c1',
            'content' => 'hack',
        ]);

        $this->assertSame(0, $bus->inboundSize());
    }

    // ── Helper ───────────────────────────────────────────────────

    /**
     * Create a concrete BaseChannel subclass for testing.
     */
    private function createConcreteChannel(
        string $name,
        bool $enabled,
        array $allowFrom,
        ?MessageBus $bus = null,
    ): TestableChannel {
        return new TestableChannel($name, $enabled, $allowFrom, $bus);
    }
}

/**
 * Minimal concrete implementation of BaseChannel for testing.
 */
class TestableChannel extends BaseChannel
{
    public function start(): void
    {
        $this->running = true;
    }

    public function send(OutboundMessage $message): bool
    {
        return true;
    }

    /**
     * Expose protected handleMessage for testing.
     */
    public function testHandleMessage(string $senderId, string $chatId, string $content): void
    {
        $this->handleMessage($senderId, $chatId, $content);
    }
}
