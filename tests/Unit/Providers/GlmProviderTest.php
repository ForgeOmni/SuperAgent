<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\GlmProvider;

class GlmProviderTest extends TestCase
{
    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(ProviderException::class);
        new GlmProvider([]);
    }

    public function test_default_region_is_intl_z_ai(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $this->assertSame('intl', $p->getRegion());
        $this->assertSame('api.z.ai', $this->host($p));
    }

    public function test_cn_region_is_bigmodel(): void
    {
        $p = new GlmProvider(['api_key' => 'k', 'region' => 'cn']);
        $this->assertSame('open.bigmodel.cn', $this->host($p));
    }

    public function test_unknown_region_throws(): void
    {
        $this->expectException(ProviderException::class);
        new GlmProvider(['api_key' => 'k', 'region' => 'us']);
    }

    public function test_name_is_glm(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $this->assertSame('glm', $p->name());
    }

    public function test_default_model_is_glm_5_2(): void
    {
        // glm-5.3 (2026-08-14) is reachable via the glm5.3 alias but stays
        // off the default until z.ai publishes standalone API pricing and
        // finishes the staged rollout.
        $p = new GlmProvider(['api_key' => 'k']);
        $this->assertSame('glm-5.2', $p->getModel());
    }

    public function test_thinking_option_injects_body_field(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, ['thinking' => true]);
        $this->assertArrayHasKey('thinking', $body);
        $this->assertSame(['type' => 'enabled'], $body['thinking']);
    }

    public function test_thinking_accepts_explicit_shape(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, [
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 4000],
        ]);
        $this->assertSame(
            ['type' => 'enabled', 'budget_tokens' => 4000],
            $body['thinking'],
        );
    }

    public function test_thinking_absent_by_default(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, []);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function test_reasoning_effort_option_merges_into_body(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, ['reasoning_effort' => 'max']);
        $this->assertSame('max', $body['reasoning_effort']);
        $this->assertSame(['type' => 'enabled'], $body['thinking']);

        $off = $this->buildBody($p, [new UserMessage('hi')], [], null, ['reasoning_effort' => 'off']);
        $this->assertSame(['type' => 'disabled'], $off['thinking']);
        $this->assertArrayNotHasKey('reasoning_effort', $off);
    }

    public function test_reasoning_effort_fragment_tiers(): void
    {
        $p = new GlmProvider(['api_key' => 'k']);
        $this->assertSame(['thinking' => ['type' => 'disabled']], $p->reasoningEffortFragment('off'));
        $this->assertSame(
            ['reasoning_effort' => 'high', 'thinking' => ['type' => 'enabled']],
            $p->reasoningEffortFragment('high'),
        );
        $this->assertSame(
            ['reasoning_effort' => 'max', 'thinking' => ['type' => 'enabled']],
            $p->reasoningEffortFragment('max'),
        );
        // Unknown values yield an empty fragment so a misconfigured caller
        // never poisons the request body.
        $this->assertSame([], $p->reasoningEffortFragment('bogus'));

        // Pre-5.3 dial has no genuine low tier — low collapses onto high.
        $this->assertSame(
            ['reasoning_effort' => 'high', 'thinking' => ['type' => 'enabled']],
            $p->reasoningEffortFragment('low'),
        );
    }

    public function test_glm_5_3_reasoning_effort_dial(): void
    {
        // GLM-5.3 widens the dial to low|high|max and makes thinking
        // mandatory: "off" degrades to the low tier instead of disabling,
        // matching Z.AI's Coding Plan adapters. Applies to the [1m] route too.
        foreach (['glm-5.3', 'glm-5.3[1m]'] as $id) {
            $p = new GlmProvider(['api_key' => 'k', 'model' => $id]);

            $this->assertSame(
                ['reasoning_effort' => 'low', 'thinking' => ['type' => 'enabled']],
                $p->reasoningEffortFragment('low'),
                "{$id}: low must stay low (genuine tier on 5.3)",
            );
            $this->assertSame(
                ['reasoning_effort' => 'low', 'thinking' => ['type' => 'enabled']],
                $p->reasoningEffortFragment('off'),
                "{$id}: off degrades to low — thinking cannot be disabled",
            );
            $this->assertSame(
                ['reasoning_effort' => 'high', 'thinking' => ['type' => 'enabled']],
                $p->reasoningEffortFragment('high'),
            );
            $this->assertSame(
                ['reasoning_effort' => 'max', 'thinking' => ['type' => 'enabled']],
                $p->reasoningEffortFragment('max'),
            );
            $this->assertSame([], $p->reasoningEffortFragment('bogus'));
        }
    }

    public function test_base_path_is_api_paas_v4(): void
    {
        // GLM's base URL already contains /api/paas/v4/, so chat endpoint is
        // relative to it — reflected through the client's base_uri.
        $p = new GlmProvider(['api_key' => 'k']);
        $client = $this->extractClient($p);
        $uri = (string) $client->getConfig('base_uri');
        $this->assertStringContainsString('/api/paas/v4/', $uri);
    }

    private function buildBody(
        GlmProvider $p,
        array $messages,
        array $tools,
        ?string $system,
        array $options,
    ): array {
        $ref = new \ReflectionObject($p);
        while ($ref && ! $ref->hasMethod('buildRequestBody')) {
            $ref = $ref->getParentClass();
        }
        $m = $ref->getMethod('buildRequestBody');
        $m->setAccessible(true);
        return $m->invoke($p, $messages, $tools, $system, $options);
    }

    private function host(object $p): string
    {
        $client = $this->extractClient($p);
        return parse_url((string) $client->getConfig('base_uri'), PHP_URL_HOST);
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
