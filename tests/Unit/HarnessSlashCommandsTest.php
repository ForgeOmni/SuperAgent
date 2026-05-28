<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\CommandRouter;

/**
 * REPL slash commands added for Opus 4.8: /workflows, /ultraplan, /ultrareview.
 * Verifies they register, generate dynamic workflows, and honor caller-selected
 * run modes (--run / --plan) regardless of environment.
 */
class HarnessSlashCommandsTest extends TestCase
{
    public function test_commands_are_registered(): void
    {
        $r = new CommandRouter();
        $this->assertTrue($r->has('workflows'));
        $this->assertTrue($r->has('ultraplan'));
        $this->assertTrue($r->has('ultrareview'));
    }

    public function test_ultraplan_creates_dynamic_workflow_visible_to_workflows(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/ultraplan Build the parser, then write tests for it', [])->output;
        $this->assertStringContainsString('Ultraplan', $out);
        $this->assertStringContainsString('dynamic workflow #1', $out);

        $list = $r->dispatch('/workflows', [])->output;
        $this->assertStringContainsString('#1', $list);
        $this->assertStringContainsString('dynamic/sequential', $list);
    }

    public function test_ultraplan_requires_a_task(): void
    {
        $r = new CommandRouter();
        $this->assertStringContainsString('Usage: /ultraplan', $r->dispatch('/ultraplan', [])->output);
    }

    public function test_ultrareview_builds_parallel_fanout_plus_synthesis(): void
    {
        $r = new CommandRouter();
        $out = $r->dispatch('/ultrareview src/Foo.php', [])->output;
        $this->assertStringContainsString('Wave 1 (parallel)', $out);
        $this->assertStringContainsString('synthesize', $out);
        $this->assertStringContainsString('dynamic workflow', $out);
    }

    public function test_workflows_help_lists_dynamic_strategies(): void
    {
        $r = new CommandRouter();
        $this->assertStringContainsString('Dynamic strategies', $r->dispatch('/workflows help', [])->output);
    }

    public function test_run_mode_is_caller_selectable(): void
    {
        $r = new CommandRouter();
        $r->dispatch('/ultraplan do a thing then another thing', []);

        // --run forces live execution intent; with no runner wired it says so plainly.
        $forcedRun = $r->dispatch('/workflows run 1 --run', [])->output;
        $this->assertStringContainsString('no agent runner', $forcedRun);

        // --plan forces the dry-run plan even if a runner were configured.
        $forcedPlan = $r->dispatch('/workflows run 1 --plan', [])->output;
        $this->assertStringContainsString('strategy', $forcedPlan);
    }

    public function test_workflows_create_dynamic_via_json(): void
    {
        $r = new CommandRouter();
        $json = '{"name":"crawl","type":"dynamic","strategy":"loop_until","guards":{"max_iterations":3,"until":"empty"},"steps":[{"agent":"general","prompt":"fetch"}]}';
        $out = $r->dispatch('/workflows create ' . $json, [])->output;
        $this->assertStringContainsString('Workflow created', $out);

        $plan = $r->dispatch('/workflows plan 1', [])->output;
        $this->assertStringContainsString('loop_until', $plan);
    }
}
