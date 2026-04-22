<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Capabilities;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\FeatureNotSupportedException;
use SuperAgent\Exceptions\ProviderException;

class FeatureNotSupportedExceptionTest extends TestCase
{
    public function test_extends_provider_exception_for_backward_compat(): void
    {
        // Backward compat: existing `catch (ProviderException $e)` in caller
        // code must keep catching this new exception without modification.
        $e = new FeatureNotSupportedException('thinking', 'openai');
        $this->assertInstanceOf(ProviderException::class, $e);
    }

    public function test_carries_feature_provider_and_message(): void
    {
        $e = new FeatureNotSupportedException('swarm', 'openai', 'gpt-4o');
        $this->assertSame('swarm', $e->feature);
        $this->assertSame('openai', $e->provider);
        $this->assertStringContainsString('swarm', $e->getMessage());
        $this->assertStringContainsString('openai', $e->getMessage());
        $this->assertStringContainsString('gpt-4o', $e->getMessage());
    }

    public function test_model_is_optional(): void
    {
        $e = new FeatureNotSupportedException('tts', 'anthropic');
        $this->assertStringContainsString('tts', $e->getMessage());
        $this->assertStringNotContainsString('model:', $e->getMessage());
    }

    public function test_is_not_retryable(): void
    {
        $e = new FeatureNotSupportedException('video', 'gemini');
        $this->assertFalse($e->isRetryable());
    }

    public function test_preserves_previous_exception(): void
    {
        $prev = new \RuntimeException('upstream reason');
        $e = new FeatureNotSupportedException('ocr', 'ollama', null, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }
}
