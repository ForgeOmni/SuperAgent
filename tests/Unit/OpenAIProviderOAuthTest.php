<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\OpenAIProvider;

/**
 * Tests the v0.8.6 OAuth bearer mode on OpenAIProvider (Codex ChatGPT
 * subscription path).
 */
class OpenAIProviderOAuthTest extends TestCase
{
    public function test_oauth_mode_sends_bearer_with_access_token(): void
    {
        $p = new OpenAIProvider([
            'auth_mode' => 'oauth',
            'access_token' => 'eyJ.JWT.ACCESS',
            'model' => 'gpt-5',
        ]);
        $headers = $this->clientHeaders($p);
        $this->assertSame('Bearer eyJ.JWT.ACCESS', $headers['authorization']);
    }

    public function test_oauth_mode_adds_chatgpt_account_id_header(): void
    {
        $p = new OpenAIProvider([
            'auth_mode' => 'oauth',
            'access_token' => 'eyJ.X',
            'account_id' => 'acct_abc123',
        ]);
        $headers = $this->clientHeaders($p);
        $this->assertSame('acct_abc123', $headers['chatgpt-account-id']);
    }

    public function test_oauth_without_account_id_omits_header(): void
    {
        $p = new OpenAIProvider([
            'auth_mode' => 'oauth',
            'access_token' => 'eyJ.X',
        ]);
        $headers = $this->clientHeaders($p);
        $this->assertArrayNotHasKey('chatgpt-account-id', $headers);
    }

    public function test_api_key_mode_sends_bearer_with_api_key(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-plain']);
        $headers = $this->clientHeaders($p);
        $this->assertSame('Bearer sk-plain', $headers['authorization']);
        $this->assertArrayNotHasKey('chatgpt-account-id', $headers);
    }

    public function test_oauth_requires_access_token(): void
    {
        $this->expectException(\SuperAgent\Exceptions\ProviderException::class);
        $this->expectExceptionMessageMatches('/access_token/');
        new OpenAIProvider(['auth_mode' => 'oauth']);
    }

    public function test_api_key_mode_requires_api_key(): void
    {
        $this->expectException(\SuperAgent\Exceptions\ProviderException::class);
        new OpenAIProvider([]);
    }

    public function test_auth_mode_inferred_from_access_token(): void
    {
        $p = new OpenAIProvider(['access_token' => 'eyJ.X']);
        $headers = $this->clientHeaders($p);
        $this->assertSame('Bearer eyJ.X', $headers['authorization']);
    }

    public function test_organization_header_still_applied_under_oauth(): void
    {
        $p = new OpenAIProvider([
            'auth_mode' => 'oauth',
            'access_token' => 't',
            'organization' => 'org_xyz',
        ]);
        $headers = $this->clientHeaders($p);
        $this->assertSame('org_xyz', $headers['openai-organization']);
    }

    private function clientHeaders(OpenAIProvider $p): array
    {
        $r = new \ReflectionObject($p);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');
        $prop->setAccessible(true);
        $client = $prop->getValue($p);

        $opts = method_exists($client, 'getConfig')
            ? $client->getConfig()
            : (function () use ($client) {
                $r = new \ReflectionObject($client);
                $p = $r->getProperty('config');
                $p->setAccessible(true);
                return $p->getValue($client);
            })();
        $headers = $opts['headers'] ?? [];
        return array_change_key_case($headers, CASE_LOWER);
    }
}
