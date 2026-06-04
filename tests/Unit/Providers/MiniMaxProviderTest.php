<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\MiniMaxProvider;

class MiniMaxProviderTest extends TestCase
{
    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(ProviderException::class);
        new MiniMaxProvider([]);
    }

    public function test_default_region_is_intl(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $this->assertSame('intl', $p->getRegion());
        $this->assertSame('api.minimax.io', $this->host($p));
    }

    public function test_cn_region_is_minimaxi(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k', 'region' => 'cn']);
        $this->assertSame('api.minimaxi.com', $this->host($p));
    }

    public function test_unknown_region_throws(): void
    {
        $this->expectException(ProviderException::class);
        new MiniMaxProvider(['api_key' => 'k', 'region' => 'eu']);
    }

    public function test_name_is_minimax(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $this->assertSame('minimax', $p->name());
    }

    public function test_default_model_is_m3(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $this->assertSame('MiniMax-M3', $p->getModel());
    }

    public function test_group_id_header_added_when_configured(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k', 'group_id' => 'gp_12345']);
        $headers = $this->clientHeaders($p);
        $this->assertSame('gp_12345', $headers['x-groupid']);
    }

    public function test_group_id_header_absent_when_not_configured(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $headers = $this->clientHeaders($p);
        $this->assertArrayNotHasKey('x-groupid', $headers);
    }

    public function test_chat_completions_path_is_text_chatcompletion_v2(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $ref = new \ReflectionObject($p);
        while ($ref && ! $ref->hasMethod('chatCompletionsPath')) {
            $ref = $ref->getParentClass();
        }
        $m = $ref->getMethod('chatCompletionsPath');
        $m->setAccessible(true);
        $this->assertSame('v1/text/chatcompletion_v2', $m->invoke($p));
    }

    public function test_thinking_request_fragment_enables_thinking(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $this->assertSame(['thinking' => ['type' => 'enabled']], $p->thinkingRequestFragment(4000));
    }

    public function test_reasoning_effort_maps_to_thinking_modes(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $this->assertSame(['thinking' => ['type' => 'disabled']], $p->reasoningEffortFragment('off'));
        $this->assertSame(['thinking' => ['type' => 'adaptive']], $p->reasoningEffortFragment('adaptive'));
        $this->assertSame(['thinking' => ['type' => 'enabled']], $p->reasoningEffortFragment('high'));
        $this->assertSame(['thinking' => ['type' => 'enabled']], $p->reasoningEffortFragment('max'));
        $this->assertSame([], $p->reasoningEffortFragment('bogus'));
    }

    public function test_customize_request_body_thinking_toggle(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $m = $this->reflectMethod($p, 'customizeRequestBody');

        $body = [];
        $m->invokeArgs($p, [&$body, ['thinking' => true]]);
        $this->assertSame(['type' => 'enabled'], $body['thinking']);

        $body = [];
        $m->invokeArgs($p, [&$body, ['thinking' => 'adaptive']]);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);

        $body = [];
        $m->invokeArgs($p, [&$body, ['thinking' => false]]);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function test_customize_request_body_reasoning_effort_overlays_thinking(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $m = $this->reflectMethod($p, 'customizeRequestBody');

        $body = [];
        $m->invokeArgs($p, [&$body, ['reasoning_effort' => 'off']]);
        $this->assertSame(['type' => 'disabled'], $body['thinking']);
    }

    private function reflectMethod(object $p, string $name): \ReflectionMethod
    {
        $ref = new \ReflectionObject($p);
        while ($ref && ! $ref->hasMethod($name)) {
            $ref = $ref->getParentClass();
        }
        $m = $ref->getMethod($name);
        $m->setAccessible(true);
        return $m;
    }

    private function host(object $p): string
    {
        $client = $this->extractClient($p);
        return parse_url((string) $client->getConfig('base_uri'), PHP_URL_HOST);
    }

    private function clientHeaders(object $p): array
    {
        $client = $this->extractClient($p);
        $headers = $client->getConfig()['headers'] ?? [];
        return array_change_key_case($headers, CASE_LOWER);
    }

    private function extractClient(object $p): \GuzzleHttp\Client
    {
        $r = new \ReflectionObject($p);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');
        $prop->setAccessible(true);
        return $prop->getValue($p);
    }
}
