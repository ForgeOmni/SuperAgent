<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\LMStudioProvider;
use SuperAgent\Providers\ProviderRegistry;

class LMStudioProviderTest extends TestCase
{
    public function test_default_base_url_is_localhost_1234(): void
    {
        $p = new LMStudioProvider([]);
        $base = $this->readBaseUri($p);
        $this->assertSame('http://localhost:1234/', $base);
    }

    public function test_registry_resolves_lmstudio_key(): void
    {
        $p = ProviderRegistry::create('lmstudio', []);
        $this->assertInstanceOf(LMStudioProvider::class, $p);
    }

    public function test_synthesised_auth_header_when_no_key(): void
    {
        $p = new LMStudioProvider([]);
        $headers = $this->readHeaders($p);
        $this->assertSame('Bearer lm-studio', $headers['Authorization']);
    }

    public function test_caller_api_key_wins(): void
    {
        $p = new LMStudioProvider(['api_key' => 'custom-123']);
        $headers = $this->readHeaders($p);
        $this->assertSame('Bearer custom-123', $headers['Authorization']);
    }

    public function test_base_url_override_honored(): void
    {
        $p = new LMStudioProvider(['base_url' => 'http://10.0.0.2:9876']);
        $this->assertSame('http://10.0.0.2:9876/', $this->readBaseUri($p));
    }

    private function readBaseUri(LMStudioProvider $p): string
    {
        $rc = new \ReflectionClass(\SuperAgent\Providers\ChatCompletionsProvider::class);
        $cp = $rc->getProperty('client');
        $cp->setAccessible(true);
        $client = $cp->getValue($p);
        $cfg = (new \ReflectionClass($client))->getProperty('config');
        $cfg->setAccessible(true);
        return (string) $cfg->getValue($client)['base_uri'];
    }

    private function readHeaders(LMStudioProvider $p): array
    {
        $rc = new \ReflectionClass(\SuperAgent\Providers\ChatCompletionsProvider::class);
        $cp = $rc->getProperty('client');
        $cp->setAccessible(true);
        $client = $cp->getValue($p);
        $cfg = (new \ReflectionClass($client))->getProperty('config');
        $cfg->setAccessible(true);
        return (array) $cfg->getValue($client)['headers'];
    }
}
