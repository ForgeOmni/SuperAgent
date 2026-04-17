<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\CommandRouter;

/**
 * Regression tests for the v0.8.6 interactive `/model` picker and a handful of
 * other slash commands that shipped with the CLI.
 */
class CommandRouterModelPickerTest extends TestCase
{
    public function test_model_lists_anthropic_catalog_by_default(): void
    {
        $r = new CommandRouter();
        $result = $r->dispatch('/model', ['model' => 'claude-sonnet-4-5']);
        $output = $result->output;

        $this->assertStringContainsString('Current model: claude-sonnet-4-5', $output);
        $this->assertStringContainsString('claude-opus-4-5', $output);
        $this->assertStringContainsString('claude-sonnet-4-5', $output);
        $this->assertStringContainsString('claude-haiku-4-5', $output);
        $this->assertStringContainsString('Usage: /model <id|number|alias>', $output);
    }

    public function test_active_model_marked_with_star(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/model list', ['model' => 'claude-sonnet-4-5'])->output;
        $this->assertMatchesRegularExpression('/claude-sonnet-4-5[^\n]*\*/', $out);
    }

    public function test_numeric_selection_returns_model_signal(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/model 1', ['model' => 'claude-sonnet-4-5'])->output;
        $this->assertSame('__MODEL__:claude-opus-4-5', $out);
    }

    public function test_out_of_range_numeric_returns_error(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/model 99', ['model' => 'claude-sonnet-4-5'])->output;
        $this->assertStringContainsString('Invalid selection', $out);
    }

    public function test_model_id_passthrough(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/model claude-haiku-4-5', ['model' => 'claude-sonnet-4-5'])->output;
        $this->assertSame('__MODEL__:claude-haiku-4-5', $out);
    }

    public function test_provider_inferred_from_model_prefix_for_openai(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/model', ['model' => 'gpt-5'])->output;
        $this->assertStringContainsString('gpt-5', $out);
        $this->assertStringContainsString('gpt-4o', $out);
        $this->assertStringContainsString('o4-mini', $out);
    }

    public function test_explicit_provider_wins_over_model_prefix(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/model', ['model' => 'claude-sonnet-4-5', 'provider' => 'openai'])->output;
        $this->assertStringContainsString('gpt-5', $out);
    }

    public function test_ollama_catalog_returned(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/model', ['provider' => 'ollama', 'model' => 'llama3.1'])->output;
        $this->assertStringContainsString('llama3.1', $out);
        $this->assertStringContainsString('qwen2.5-coder', $out);
    }

    public function test_openrouter_catalog_returned(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/model', ['provider' => 'openrouter', 'model' => 'anthropic/claude-opus-4-5'])->output;
        $this->assertStringContainsString('anthropic/claude-opus-4-5', $out);
    }

    public function test_help_lists_model(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/help', [])->output;
        $this->assertStringContainsString('/model', $out);
    }

    public function test_unknown_command_returns_error(): void
    {
        $r = new CommandRouter();
        $result = $r->dispatch('/nonexistent', []);
        $this->assertStringContainsString('Unknown command', $result->output);
    }

    public function test_status_outputs_model_and_cost(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/status', [
            'model' => 'claude-opus-4-5',
            'turn_count' => 3,
            'total_cost_usd' => 0.1234,
            'message_count' => 7,
        ])->output;
        $this->assertStringContainsString('claude-opus-4-5', $out);
        $this->assertStringContainsString('3', $out);
        $this->assertStringContainsString('0.1234', $out);
    }

    public function test_cost_includes_average_per_turn(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/cost', [
            'total_cost_usd' => 1.0,
            'turn_count' => 4,
        ])->output;
        $this->assertStringContainsString('1.0000', $out);
        $this->assertStringContainsString('0.2500', $out); // 1.0 / 4
    }

    public function test_quit_returns_control_signal(): void
    {
        $r = new CommandRouter();
        $result = $r->dispatch('/quit', []);
        $this->assertTrue($result->isSignal('__QUIT__'));
    }

    public function test_clear_returns_control_signal(): void
    {
        $r = new CommandRouter();
        $result = $r->dispatch('/clear', []);
        $this->assertTrue($result->isSignal('__CLEAR__'));
    }

    public function test_register_custom_command(): void
    {
        $r = new CommandRouter();
        $r->register('deploy', 'deploy to prod', fn() => 'deployed');
        $result = $r->dispatch('/deploy', []);
        $this->assertSame('deployed', $result->output);
    }

    public function test_is_command_detects_slash(): void
    {
        $r = new CommandRouter();
        $this->assertTrue($r->isCommand('/help'));
        $this->assertTrue($r->isCommand('  /help'));
        $this->assertFalse($r->isCommand('help'));
        $this->assertFalse($r->isCommand(''));
    }
}
