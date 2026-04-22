<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\QwenProvider;

class QwenProviderTest extends TestCase
{
    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(ProviderException::class);
        new QwenProvider([]);
    }

    public function test_default_region_is_intl_singapore(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $this->assertSame('intl', $p->getRegion());
        $this->assertSame('dashscope-intl.aliyuncs.com', $this->host($p));
    }

    public function test_us_region_maps_to_virginia(): void
    {
        $p = new QwenProvider(['api_key' => 'k', 'region' => 'us']);
        $this->assertSame('dashscope-us.aliyuncs.com', $this->host($p));
    }

    public function test_cn_region_maps_to_beijing(): void
    {
        $p = new QwenProvider(['api_key' => 'k', 'region' => 'cn']);
        $this->assertSame('dashscope.aliyuncs.com', $this->host($p));
    }

    public function test_hk_region_maps_to_hong_kong(): void
    {
        $p = new QwenProvider(['api_key' => 'k', 'region' => 'hk']);
        $this->assertSame('cn-hongkong.dashscope.aliyuncs.com', $this->host($p));
    }

    public function test_unknown_region_throws(): void
    {
        $this->expectException(ProviderException::class);
        new QwenProvider(['api_key' => 'k', 'region' => 'eu']);
    }

    public function test_name_is_qwen(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $this->assertSame('qwen', $p->name());
    }

    public function test_default_model_is_qwen3_6_max_preview(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $this->assertSame('qwen3.6-max-preview', $p->getModel());
    }

    public function test_request_body_shape_is_dashscope_native(): void
    {
        // DashScope native format has top-level `input.messages` and `parameters`,
        // not flat `messages` + OpenAI-style top-level fields.
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], 'you are helpful', []);

        $this->assertArrayHasKey('model', $body);
        $this->assertArrayHasKey('input', $body);
        $this->assertArrayHasKey('parameters', $body);
        $this->assertSame('qwen3.6-max-preview', $body['model']);
        $this->assertArrayHasKey('messages', $body['input']);
        $this->assertArrayNotHasKey('messages', $body);  // NOT at top level
        // Role can be the Role enum (from UserMessage) or string (from system
        // prompt literal) — normalise for the assertion.
        $roleToStr = fn ($r) => is_string($r) ? $r : $r->value;
        $this->assertSame('system', $roleToStr($body['input']['messages'][0]['role']));
        $this->assertSame('user', $roleToStr($body['input']['messages'][1]['role']));
    }

    public function test_parameters_has_result_format_message_and_incremental(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, []);
        $this->assertSame('message', $body['parameters']['result_format']);
        $this->assertTrue($body['parameters']['incremental_output']);
    }

    public function test_enable_thinking_option_adds_parameter(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, [
            'enable_thinking' => true,
            'thinking_budget' => 3000,
        ]);
        $this->assertTrue($body['parameters']['enable_thinking']);
        $this->assertSame(3000, $body['parameters']['thinking_budget']);
    }

    public function test_enable_code_interpreter_adds_parameter(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, [
            'enable_code_interpreter' => true,
        ]);
        $this->assertTrue($body['parameters']['enable_code_interpreter']);
    }

    public function test_thinking_and_code_interpreter_absent_by_default(): void
    {
        $p = new QwenProvider(['api_key' => 'k']);
        $body = $this->buildBody($p, [new UserMessage('hi')], [], null, []);
        $this->assertArrayNotHasKey('enable_thinking', $body['parameters']);
        $this->assertArrayNotHasKey('enable_code_interpreter', $body['parameters']);
    }

    private function buildBody(
        QwenProvider $p,
        array $messages,
        array $tools,
        ?string $system,
        array $options,
    ): array {
        $m = (new \ReflectionObject($p))->getMethod('buildRequestBody');
        $m->setAccessible(true);
        return $m->invoke($p, $messages, $tools, $system, $options);
    }

    private function host(QwenProvider $p): string
    {
        $r = new \ReflectionObject($p);
        $prop = $r->getProperty('client');
        $prop->setAccessible(true);
        $client = $prop->getValue($p);
        return parse_url((string) $client->getConfig('base_uri'), PHP_URL_HOST);
    }
}
