<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Features;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\Features\AgentTeamsAdapter;
use SuperAgent\Providers\Features\CodeInterpreterAdapter;
use SuperAgent\Providers\Features\FeatureAdapter;
use SuperAgent\Providers\Features\FeatureDispatcher;
use SuperAgent\Providers\Features\ThinkingAdapter;

/**
 * Improvement #19 — FeatureDispatcher surfaces misspelled spec keys
 * under `SUPERAGENT_DEBUG=1`, without ever blocking the call.
 *
 * The tests use PHP's `error_log` intercepted via a custom handler so we
 * can assert against the emitted message without spamming the real
 * error log.
 */
class FeatureSpecValidationTest extends TestCase
{
    private string $logFile;
    private ?string $origErrorLog;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/superagent_feature_validation_' . bin2hex(random_bytes(4)) . '.log';
        $this->origErrorLog = ini_get('error_log') ?: null;
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->origErrorLog);
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        putenv('SUPERAGENT_DEBUG');
    }

    public function test_thinking_adapter_declares_valid_keys(): void
    {
        $this->assertSame(['enabled', 'required', 'budget'], ThinkingAdapter::validSpecKeys());
    }

    public function test_agent_teams_adapter_declares_valid_keys(): void
    {
        $this->assertSame(
            ['enabled', 'required', 'roles', 'objective', 'protocol'],
            AgentTeamsAdapter::validSpecKeys(),
        );
    }

    public function test_code_interpreter_adapter_declares_valid_keys(): void
    {
        $this->assertSame(
            ['enabled', 'required', 'timeout_seconds'],
            CodeInterpreterAdapter::validSpecKeys(),
        );
    }

    public function test_base_default_is_enabled_required_only(): void
    {
        $this->assertSame(['enabled', 'required'], FeatureAdapter::validSpecKeys());
    }

    public function test_debug_mode_warns_on_unknown_keys(): void
    {
        putenv('SUPERAGENT_DEBUG=1');

        $provider = new FakeNoCapProvider();
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        FeatureDispatcher::apply($provider, [
            'features' => [
                'thinking' => ['budget' => 4000, 'budjet' => 3000],  // typo
            ],
        ], $body);

        $log = is_file($this->logFile) ? file_get_contents($this->logFile) : '';
        $this->assertStringContainsString("features.thinking", $log);
        $this->assertStringContainsString("'budjet'", $log);
    }

    public function test_no_warning_when_debug_disabled(): void
    {
        // Explicitly leave SUPERAGENT_DEBUG unset.
        $provider = new FakeNoCapProvider();
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        FeatureDispatcher::apply($provider, [
            'features' => [
                'thinking' => ['budjet' => 3000],  // typo — silent in prod
            ],
        ], $body);

        $log = is_file($this->logFile) ? file_get_contents($this->logFile) : '';
        $this->assertStringNotContainsString('unknown spec key', $log);
    }

    public function test_valid_keys_do_not_warn(): void
    {
        putenv('SUPERAGENT_DEBUG=1');

        $provider = new FakeNoCapProvider();
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        FeatureDispatcher::apply($provider, [
            'features' => [
                'thinking' => ['budget' => 4000, 'required' => false],
                'code_interpreter' => ['timeout_seconds' => 30],
            ],
        ], $body);

        $log = is_file($this->logFile) ? file_get_contents($this->logFile) : '';
        $this->assertStringNotContainsString('unknown spec key', $log);
    }
}

class FakeNoCapProvider implements LLMProvider
{
    public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): Generator
    {
        yield from [];
    }
    public function formatMessages(array $messages): array { return []; }
    public function formatTools(array $tools): array { return []; }
    public function getModel(): string { return 'fake'; }
    public function setModel(string $model): void {}
    public function name(): string { return 'fake'; }
}
