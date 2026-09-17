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
 * The warnings go out through `error_log()`, so the tests read whatever
 * file the `error_log` ini setting points at *at the moment of the call*,
 * and only the bytes appended by the call. Pointing the setting at a file
 * of our own in setUp() is not enough: PHPUnit 12 redirects `error_log`
 * to a temp file of its own per test, after setUp() has run, so a test
 * that reads its own path finds an empty string and passes or fails for
 * the wrong reason.
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

    /**
     * Runs $fn and returns only what it appended to the active error log.
     *
     * Reading the delta rather than the whole file keeps the assertions
     * honest when the destination is shared between tests in one process,
     * which is what PHPUnit 12's per-test error log is.
     */
    private function captureErrorLog(callable $fn): string
    {
        $path = (string) ini_get('error_log');
        if ($path === '') {
            $path = $this->logFile;
            ini_set('error_log', $path);
        }

        $offset = is_file($path) ? (int) filesize($path) : 0;

        $fn();

        if (! is_file($path)) {
            return '';
        }

        clearstatcache(true, $path);

        return (string) file_get_contents($path, false, null, $offset);
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

        $log = $this->captureErrorLog(function () use ($provider, &$body) {
            FeatureDispatcher::apply($provider, [
                'features' => [
                    'thinking' => ['budget' => 4000, 'budjet' => 3000],  // typo
                ],
            ], $body);
        });

        $this->assertStringContainsString("features.thinking", $log);
        $this->assertStringContainsString("'budjet'", $log);
    }

    public function test_no_warning_when_debug_disabled(): void
    {
        // Explicitly leave SUPERAGENT_DEBUG unset.
        $provider = new FakeNoCapProvider();
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        $log = $this->captureErrorLog(function () use ($provider, &$body) {
            FeatureDispatcher::apply($provider, [
                'features' => [
                    'thinking' => ['budjet' => 3000],  // typo — silent in prod
                ],
            ], $body);
        });

        $this->assertStringNotContainsString('unknown spec key', $log);
    }

    public function test_valid_keys_do_not_warn(): void
    {
        putenv('SUPERAGENT_DEBUG=1');

        $provider = new FakeNoCapProvider();
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        $log = $this->captureErrorLog(function () use ($provider, &$body) {
            FeatureDispatcher::apply($provider, [
                'features' => [
                    'thinking' => ['budget' => 4000, 'required' => false],
                    'code_interpreter' => ['timeout_seconds' => 30],
                ],
            ], $body);
        });

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
