<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\AnthropicProvider;

/**
 * Tests the v0.8.6 OAuth bearer mode on AnthropicProvider:
 *   - Header swap (Bearer + anthropic-beta, no x-api-key)
 *   - System prompt identity-block injection
 *   - Legacy model-id rewrite under OAuth
 *
 * These tests don't hit the API — they exercise construction + request-body
 * assembly via reflection on the Guzzle client config and the `buildRequestBody`
 * method.
 */
class AnthropicProviderOAuthTest extends TestCase
{
    public function test_oauth_mode_sets_bearer_and_beta_headers(): void
    {
        $p = new AnthropicProvider([
            'auth_mode' => 'oauth',
            'access_token' => 'sk-ant-oat01-TOKEN',
            'model' => 'claude-sonnet-4-5',
        ]);

        $headers = $this->clientHeaders($p);
        $this->assertArrayHasKey('authorization', $headers);
        $this->assertSame('Bearer sk-ant-oat01-TOKEN', $headers['authorization']);
        $this->assertSame('oauth-2025-04-20', $headers['anthropic-beta']);
        $this->assertArrayNotHasKey('x-api-key', $headers);
    }

    public function test_api_key_mode_keeps_x_api_key(): void
    {
        $p = new AnthropicProvider([
            'api_key' => 'sk-ant-api03-PLAIN',
            'model' => 'claude-sonnet-4-5',
        ]);

        $headers = $this->clientHeaders($p);
        $this->assertSame('sk-ant-api03-PLAIN', $headers['x-api-key']);
        $this->assertArrayNotHasKey('authorization', $headers);
        $this->assertArrayNotHasKey('anthropic-beta', $headers);
    }

    public function test_oauth_requires_access_token(): void
    {
        $this->expectException(\SuperAgent\Exceptions\ProviderException::class);
        $this->expectExceptionMessageMatches('/access_token/');
        new AnthropicProvider(['auth_mode' => 'oauth']);
    }

    public function test_api_key_mode_requires_api_key(): void
    {
        $this->expectException(\SuperAgent\Exceptions\ProviderException::class);
        new AnthropicProvider(['auth_mode' => 'api_key']);
    }

    public function test_auth_mode_inferred_from_access_token(): void
    {
        $p = new AnthropicProvider(['access_token' => 'sk-ant-oat01-X']);
        $headers = $this->clientHeaders($p);
        $this->assertSame('Bearer sk-ant-oat01-X', $headers['authorization']);
    }

    public function test_legacy_model_rewritten_under_oauth(): void
    {
        $p = new AnthropicProvider([
            'auth_mode' => 'oauth',
            'access_token' => 't',
            'model' => 'claude-3-5-sonnet-20241022',
        ]);

        $body = $this->buildBody($p);
        $this->assertSame('claude-opus-4-5', $body['model']);
    }

    public function test_legacy_model_not_rewritten_under_api_key(): void
    {
        $p = new AnthropicProvider([
            'api_key' => 'sk',
            'model' => 'claude-3-5-sonnet-20241022',
        ]);
        $body = $this->buildBody($p);
        $this->assertSame('claude-3-5-sonnet-20241022', $body['model']);
    }

    public function test_modern_model_untouched_under_oauth(): void
    {
        $p = new AnthropicProvider([
            'auth_mode' => 'oauth',
            'access_token' => 't',
            'model' => 'claude-sonnet-4-5',
        ]);
        $this->assertSame('claude-sonnet-4-5', $this->buildBody($p)['model']);
    }

    public function test_claude_2_rewritten(): void
    {
        $p = new AnthropicProvider([
            'auth_mode' => 'oauth',
            'access_token' => 't',
            'model' => 'claude-2.1',
        ]);
        $this->assertSame('claude-opus-4-5', $this->buildBody($p)['model']);
    }

    public function test_oauth_injects_identity_system_block_when_user_prompt_provided(): void
    {
        $p = new AnthropicProvider([
            'auth_mode' => 'oauth',
            'access_token' => 't',
            'model' => 'claude-sonnet-4-5',
        ]);

        $body = $this->buildBody($p, systemPrompt: 'You are a helpful coding assistant.');
        $this->assertIsArray($body['system']);
        $this->assertCount(2, $body['system']);
        $this->assertSame(
            "You are Claude Code, Anthropic's official CLI for Claude.",
            $body['system'][0]['text'],
        );
        $this->assertSame('You are a helpful coding assistant.', $body['system'][1]['text']);
    }

    public function test_oauth_does_not_duplicate_prefix_when_already_present(): void
    {
        $p = new AnthropicProvider([
            'auth_mode' => 'oauth',
            'access_token' => 't',
            'model' => 'claude-sonnet-4-5',
        ]);

        $prefixed = "You are Claude Code, Anthropic's official CLI for Claude.\n\nAnd also helpful.";
        $body = $this->buildBody($p, systemPrompt: $prefixed);
        // Should stay a string (no wrapping) because prefix is already at start.
        $this->assertSame($prefixed, $body['system']);
    }

    public function test_oauth_injects_identity_block_even_without_user_prompt(): void
    {
        $p = new AnthropicProvider([
            'auth_mode' => 'oauth',
            'access_token' => 't',
            'model' => 'claude-sonnet-4-5',
        ]);

        $body = $this->buildBody($p, systemPrompt: null);
        $this->assertArrayHasKey('system', $body);
        // Could be string or array form; either way must start with the identity string.
        if (is_string($body['system'])) {
            $this->assertStringStartsWith("You are Claude Code", $body['system']);
        } else {
            $this->assertStringStartsWith("You are Claude Code", $body['system'][0]['text']);
        }
    }

    public function test_api_key_mode_does_not_inject_identity_block(): void
    {
        $p = new AnthropicProvider([
            'api_key' => 'sk',
            'model' => 'claude-sonnet-4-5',
        ]);
        $body = $this->buildBody($p, systemPrompt: 'Plain prompt.');
        $this->assertSame('Plain prompt.', $body['system']);
    }

    public function test_custom_anthropic_beta_override_accepted(): void
    {
        $p = new AnthropicProvider([
            'auth_mode' => 'oauth',
            'access_token' => 't',
            'anthropic_beta' => 'custom-beta',
        ]);
        $headers = $this->clientHeaders($p);
        $this->assertSame('custom-beta', $headers['anthropic-beta']);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function clientHeaders(AnthropicProvider $p): array
    {
        $client = $this->reflect($p, 'client');
        $opts = method_exists($client, 'getConfig')
            ? $client->getConfig()
            : $this->reflect($client, 'config');
        $headers = $opts['headers'] ?? [];
        // Guzzle normalises keys to lowercase via RequestOptions; keep original for assertions.
        return array_change_key_case($headers, CASE_LOWER);
    }

    private function buildBody(AnthropicProvider $p, ?string $systemPrompt = null): array
    {
        $ref = new \ReflectionMethod($p, 'buildRequestBody');
        $ref->setAccessible(true);
        return $ref->invoke($p, [], [], $systemPrompt, []);
    }

    private function reflect(object $obj, string $prop): mixed
    {
        $r = new \ReflectionObject($obj);
        while ($r && ! $r->hasProperty($prop)) {
            $r = $r->getParentClass();
        }
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        return $p->getValue($obj);
    }
}
