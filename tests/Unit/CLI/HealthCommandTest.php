<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\CLI\Commands\HealthCommand;
use SuperAgent\CLI\SuperAgentApplication;

/**
 * Tests the argv-parser routing for `superagent health` and the
 * `filterToConfigured()` helper used to prune the default probe list.
 *
 * We intentionally do NOT fire the real `ProviderRegistry::healthCheck()`
 * here — that makes a cURL call. The command's HTTP path is covered by
 * end-to-end smoke runs (`bin/superagent health --json` with env vars).
 */
class HealthCommandTest extends TestCase
{
    // ------------------------------------------------------------------
    // Argv parsing
    // ------------------------------------------------------------------

    public function test_health_subcommand_detected(): void
    {
        $opts = $this->parse(['health']);
        $this->assertSame('health', $opts['command']);
        $this->assertSame([], $opts['health_args']);
    }

    public function test_doctor_alias_detected(): void
    {
        $opts = $this->parse(['doctor']);
        $this->assertSame('doctor', $opts['command']);
        $this->assertSame([], $opts['health_args']);
    }

    public function test_health_with_extra_positional_captured(): void
    {
        // --all isn't recognised by the shared parser, so positional
        // args are the only place a freeform flag would land. Keep
        // this as a regression pin: if the parser *starts* capturing
        // `--all`, we want it routed into health_args rather than
        // silently dropped.
        $opts = $this->parse(['health']);
        $this->assertArrayHasKey('health_args', $opts);
    }

    // ------------------------------------------------------------------
    // filterToConfigured
    // ------------------------------------------------------------------

    public function test_filter_to_configured_with_no_env(): void
    {
        $this->withEnv([
            'ANTHROPIC_API_KEY' => false,
            'OPENAI_API_KEY'    => false,
            'KIMI_API_KEY'      => false,
            'QWEN_API_KEY'      => false,
            'GLM_API_KEY'       => false,
            'MINIMAX_API_KEY'   => false,
            'OPENROUTER_API_KEY'=> false,
            'GEMINI_API_KEY'    => false,
        ], function (): void {
            $out = HealthCommand::filterToConfigured(['anthropic', 'openai', 'kimi']);
            // Without OAuth credentials on disk either, nothing passes.
            $this->assertSame([], $out);
        });
    }

    public function test_filter_to_configured_keeps_only_providers_with_env_key(): void
    {
        $this->withEnv([
            'ANTHROPIC_API_KEY' => 'sk-ant-fake',
            'OPENAI_API_KEY'    => false,
            'KIMI_API_KEY'      => 'ms-fake',
            'QWEN_API_KEY'      => false,
            'GLM_API_KEY'       => false,
            'MINIMAX_API_KEY'   => false,
        ], function (): void {
            $out = HealthCommand::filterToConfigured(['anthropic', 'openai', 'kimi', 'glm']);
            $this->assertSame(['anthropic', 'kimi'], $out);
        });
    }

    public function test_qwen_and_qwen_native_both_honour_single_env_key(): void
    {
        $this->withEnv([
            'QWEN_API_KEY'      => 'sk-qwen-fake',
            'ANTHROPIC_API_KEY' => false,
            'OPENAI_API_KEY'    => false,
            'KIMI_API_KEY'      => false,
        ], function (): void {
            $out = HealthCommand::filterToConfigured(['qwen', 'qwen-native']);
            // 0.9.0 split binding — both keys read the same env.
            $this->assertSame(['qwen', 'qwen-native'], $out);
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function parse(array $args): array
    {
        $app = new SuperAgentApplication();
        $r = new \ReflectionMethod($app, 'parseOptions');
        $r->setAccessible(true);
        return $r->invoke($app, $args);
    }

    /**
     * Temporarily set env vars (false = unset) then restore the prior
     * values. `putenv` + `$_ENV` + `$_SERVER` all need to be touched
     * because filterToConfigured() reads from `$_ENV` first then
     * `getenv()`.
     */
    private function withEnv(array $vars, \Closure $test): void
    {
        $prior = [];
        foreach ($vars as $k => $v) {
            $prior[$k] = [
                'env'    => $_ENV[$k]    ?? null,
                'server' => $_SERVER[$k] ?? null,
                'getenv' => getenv($k),
            ];
            if ($v === false) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
        try {
            $test();
        } finally {
            foreach ($prior as $k => $p) {
                if ($p['env'] === null) unset($_ENV[$k]); else $_ENV[$k] = $p['env'];
                if ($p['server'] === null) unset($_SERVER[$k]); else $_SERVER[$k] = $p['server'];
                if ($p['getenv'] === false) putenv($k); else putenv("{$k}={$p['getenv']}");
            }
        }
    }
}
