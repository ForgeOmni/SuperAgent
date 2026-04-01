<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Exceptions\SuperAgentException;
use SuperAgent\Exceptions\ToolException;

class ExceptionTest extends TestCase
{
    public function test_provider_exception(): void
    {
        $e = new ProviderException('rate limit', 'anthropic', 429, ['error' => 'too fast']);

        $this->assertInstanceOf(SuperAgentException::class, $e);
        $this->assertStringContainsString('anthropic', $e->getMessage());
        $this->assertStringContainsString('rate limit', $e->getMessage());
        $this->assertSame('anthropic', $e->provider);
        $this->assertSame(429, $e->statusCode);
        $this->assertSame(['error' => 'too fast'], $e->responseBody);
    }

    public function test_tool_exception(): void
    {
        $e = new ToolException('timeout', 'bash');

        $this->assertInstanceOf(SuperAgentException::class, $e);
        $this->assertStringContainsString('bash', $e->getMessage());
        $this->assertStringContainsString('timeout', $e->getMessage());
        $this->assertSame('bash', $e->toolName);
    }
}
