<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ModelResolver;

/**
 * The catalog flags `deepseek-chat` and `deepseek-reasoner` with
 * `deprecated_until = 2026-07-24` and a `replaced_by` pointer to V4
 * because DeepSeek announced both ids will stop responding after
 * that date. ModelResolver emits a one-shot warning so users on the
 * old ids get a deadline rather than a surprise outage.
 */
class DeepSeekDeprecationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelCatalog::clearOverrides();
        ModelResolver::reset();
    }

    protected function tearDown(): void
    {
        ModelCatalog::clearOverrides();
        ModelResolver::reset();
    }

    public function test_deepseek_chat_carries_deprecation_metadata(): void
    {
        $info = ModelCatalog::deprecation('deepseek-chat');
        $this->assertNotNull($info);
        $this->assertSame('2026-07-24', $info['deprecated_until']);
        $this->assertSame('deepseek-v4-flash', $info['replaced_by']);
    }

    public function test_deepseek_reasoner_carries_deprecation_metadata(): void
    {
        $info = ModelCatalog::deprecation('deepseek-reasoner');
        $this->assertNotNull($info);
        $this->assertSame('2026-07-24', $info['deprecated_until']);
        $this->assertSame('deepseek-v4-pro', $info['replaced_by']);
    }

    public function test_current_models_have_no_deprecation(): void
    {
        $this->assertNull(ModelCatalog::deprecation('deepseek-v4-flash'));
        $this->assertNull(ModelCatalog::deprecation('deepseek-v4-pro'));
        $this->assertNull(ModelCatalog::deprecation('claude-opus-4-7'));
    }

    public function test_unknown_model_returns_null_deprecation(): void
    {
        $this->assertNull(ModelCatalog::deprecation('totally-fake-model-xyz'));
    }

    public function test_resolver_emits_warning_for_deprecated_id(): void
    {
        // error_log defaults to stderr unless redirected. Capture by
        // redirecting via ini_set('error_log') to a temp file.
        $log = tempnam(sys_get_temp_dir(), 'deepseek_dep_');
        $prev = ini_set('error_log', $log);

        try {
            ModelResolver::resolve('deepseek-chat');
            $contents = file_get_contents($log);
            $this->assertNotFalse($contents);
            $this->assertStringContainsString('deepseek-chat', $contents);
            $this->assertStringContainsString('deprecated', $contents);
            $this->assertStringContainsString('deepseek-v4-flash', $contents);
        } finally {
            ini_set('error_log', $prev !== false ? $prev : '');
            @unlink($log);
        }
    }

    public function test_resolver_warns_only_once_per_model(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'deepseek_dep_');
        $prev = ini_set('error_log', $log);

        try {
            ModelResolver::resolve('deepseek-chat');
            ModelResolver::resolve('deepseek-chat');
            ModelResolver::resolve('deepseek-chat');

            $contents = file_get_contents($log);
            $this->assertSame(
                1,
                substr_count($contents, "model 'deepseek-chat' is deprecated"),
                'Deprecation must fire exactly once per process per model id',
            );
        } finally {
            ini_set('error_log', $prev !== false ? $prev : '');
            @unlink($log);
        }
    }

    public function test_resolver_does_not_warn_for_current_model(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'deepseek_dep_');
        $prev = ini_set('error_log', $log);

        try {
            ModelResolver::resolve('deepseek-v4-flash');
            ModelResolver::resolve('deepseek-v4-pro');

            $contents = (string) file_get_contents($log);
            $this->assertStringNotContainsString('deprecated', $contents);
        } finally {
            ini_set('error_log', $prev !== false ? $prev : '');
            @unlink($log);
        }
    }

    public function test_suppress_env_silences_warning(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'deepseek_dep_');
        $prev = ini_set('error_log', $log);
        $prevEnv = getenv('SUPERAGENT_SUPPRESS_DEPRECATION');
        putenv('SUPERAGENT_SUPPRESS_DEPRECATION=1');

        try {
            ModelResolver::resolve('deepseek-chat');
            $contents = (string) file_get_contents($log);
            $this->assertStringNotContainsString('deprecated', $contents);
        } finally {
            putenv('SUPERAGENT_SUPPRESS_DEPRECATION' . ($prevEnv === false ? '' : '=' . $prevEnv));
            ini_set('error_log', $prev !== false ? $prev : '');
            @unlink($log);
        }
    }
}
