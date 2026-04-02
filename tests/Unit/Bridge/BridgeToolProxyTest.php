<?php

namespace SuperAgent\Tests\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\BridgeToolProxy;

class BridgeToolProxyTest extends TestCase
{
    public function test_basic_properties(): void
    {
        $proxy = new BridgeToolProxy(
            'bash',
            'Execute a bash command',
            ['type' => 'object', 'properties' => ['command' => ['type' => 'string']]],
        );

        $this->assertSame('bash', $proxy->name());
        $this->assertSame('Execute a bash command', $proxy->description());
        $this->assertSame('object', $proxy->inputSchema()['type']);
        $this->assertTrue($proxy->isReadOnly());
    }

    public function test_execute_throws(): void
    {
        $proxy = new BridgeToolProxy('test', 'test', []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('should never be called');
        $proxy->execute([]);
    }
}
