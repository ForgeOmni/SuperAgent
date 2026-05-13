<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\CLI\Commands\SmartCommand;
use SuperAgent\CLI\SuperAgentApplication;

/**
 * Argv parsing + dispatch behaviour for `superagent smart`.
 *
 * We don't fire the real SmartOrchestrator (that needs a model + network).
 * Coverage focuses on:
 *   - the global parser routing `smart` and capturing post-subcommand flags,
 *   - the command's own `parseArgs()` (task extraction + flag normalisation),
 *   - `usage()` exit code,
 *   - `show` with empty / non-empty run-log dirs.
 */
class SmartCommandTest extends TestCase
{
    // ------------------------------------------------------------------
    // Global parser routes `smart` correctly
    // ------------------------------------------------------------------

    public function test_smart_subcommand_detected(): void
    {
        $opts = $this->parse(['smart', 'compute', '2+2']);
        $this->assertSame('smart', $opts['command']);
        $this->assertSame(['compute', '2+2'], $opts['smart_args']);
    }

    public function test_smart_captures_long_flag_with_value(): void
    {
        // The shared parser flips into `subcommandRaw` mode once it sees `smart`,
        // so flags after that go into `smart_args` as-is rather than being silently dropped.
        $opts = $this->parse(['smart', 'task', '--brain', 'claude-opus-4-7', '--threshold', '0.8']);
        $this->assertSame('smart', $opts['command']);
        $this->assertSame(
            ['task', '--brain', 'claude-opus-4-7', '--threshold', '0.8'],
            $opts['smart_args']
        );
    }

    public function test_smart_show_subcommand_routed(): void
    {
        $opts = $this->parse(['smart', 'show']);
        $this->assertSame('smart', $opts['command']);
        $this->assertSame(['show'], $opts['smart_args']);
    }

    public function test_smart_replay_subcommand_routed(): void
    {
        $opts = $this->parse(['smart', 'replay', '--last']);
        $this->assertSame('smart', $opts['command']);
        $this->assertSame(['replay', '--last'], $opts['smart_args']);
    }

    // ------------------------------------------------------------------
    // SmartCommand::parseArgs() — flag normalisation
    // ------------------------------------------------------------------

    public function test_parse_args_collects_task_words_into_single_string(): void
    {
        [$task, $opts] = $this->parseArgs(['fix', 'the', 'login', 'bug']);
        $this->assertSame('fix the login bug', $task);
        $this->assertNull($opts['brain']);
        $this->assertNull($opts['threshold']);
    }

    public function test_parse_args_clamps_threshold_to_unit_interval(): void
    {
        [, $opts] = $this->parseArgs(['t', '--threshold', '2.5']);
        $this->assertSame(1.0, $opts['threshold']);

        [, $opts2] = $this->parseArgs(['t', '--threshold', '-0.5']);
        $this->assertSame(0.0, $opts2['threshold']);
    }

    public function test_parse_args_max_cost_zero_or_negative_becomes_null(): void
    {
        // 0 (and negative) → null means "no cap" rather than instant abort.
        [, $opts] = $this->parseArgs(['t', '--max-cost', '0']);
        $this->assertNull($opts['max_cost']);

        [, $opts2] = $this->parseArgs(['t', '--max-cost', '-1']);
        $this->assertNull($opts2['max_cost']);

        [, $opts3] = $this->parseArgs(['t', '--max-cost', '0.25']);
        $this->assertSame(0.25, $opts3['max_cost']);
    }

    public function test_parse_args_max_parallel_clamps_negative_to_zero(): void
    {
        [, $opts] = $this->parseArgs(['t', '--max-parallel', '-3']);
        $this->assertSame(0, $opts['max_parallel']);

        [, $opts2] = $this->parseArgs(['t', '--max-parallel', '8']);
        $this->assertSame(8, $opts2['max_parallel']);
    }

    public function test_parse_args_boolean_flags(): void
    {
        [, $opts] = $this->parseArgs(['t', '--dry-run', '--yes', '--json']);
        $this->assertTrue($opts['dry_run']);
        $this->assertTrue($opts['yes']);
        $this->assertTrue($opts['json']);
    }

    public function test_parse_args_short_yes_alias(): void
    {
        [, $opts] = $this->parseArgs(['t', '-y']);
        $this->assertTrue($opts['yes']);
    }

    public function test_parse_args_unknown_long_flags_are_dropped(): void
    {
        [$task, $opts] = $this->parseArgs(['hello', '--bogus-flag']);
        $this->assertSame('hello', $task);
        // The parser drops unknown leading-dash tokens; they don't pollute the task.
        $this->assertNull($opts['brain']);
    }

    // ------------------------------------------------------------------
    // execute() — usage + show with empty/non-empty dir
    // ------------------------------------------------------------------

    public function test_execute_prints_usage_for_empty_args(): void
    {
        $cmd = new SmartCommand();
        ob_start();
        $code = $cmd->execute(['smart_args' => []]);
        ob_end_clean();
        $this->assertSame(2, $code);
    }

    public function test_execute_prints_usage_when_first_arg_is_flag(): void
    {
        $cmd = new SmartCommand();
        ob_start();
        $code = $cmd->execute(['smart_args' => ['--dry-run']]);
        ob_end_clean();
        $this->assertSame(2, $code);
    }

    public function test_show_with_empty_dir_prints_no_runs_message(): void
    {
        // Point HOME at a fresh temp dir so smart_runs/ is empty.
        $tmpHome = sys_get_temp_dir() . '/smart_cmd_test_' . bin2hex(random_bytes(4));
        @mkdir($tmpHome, 0775, true);
        $oldHome = getenv('HOME');
        putenv("HOME={$tmpHome}");

        try {
            $cmd = new SmartCommand();
            ob_start();
            $code = $cmd->execute(['smart_args' => ['show']]);
            $out = ob_get_clean();
            $this->assertSame(0, $code);
            $this->assertStringContainsString('No smart runs yet', $out);
        } finally {
            putenv($oldHome === false ? 'HOME' : "HOME={$oldHome}");
            $this->cleanupDir($tmpHome);
        }
    }

    public function test_show_with_runs_lists_them_newest_first(): void
    {
        $tmpHome = sys_get_temp_dir() . '/smart_cmd_test_' . bin2hex(random_bytes(4));
        $runsDir = $tmpHome . '/.superagent/smart_runs';
        @mkdir($runsDir, 0775, true);
        // Names sort descending by string — putting a higher digit first.
        file_put_contents($runsDir . '/2026-05-12_100000_aaa111.json', '{}');
        file_put_contents($runsDir . '/2026-05-13_100000_bbb222.json', '{}');

        $oldHome = getenv('HOME');
        putenv("HOME={$tmpHome}");

        try {
            $cmd = new SmartCommand();
            ob_start();
            $code = $cmd->execute(['smart_args' => ['show']]);
            $out = ob_get_clean();
            $this->assertSame(0, $code);
            $this->assertStringContainsString('bbb222', $out);
            $this->assertStringContainsString('aaa111', $out);
            // Newer should appear first in the listing.
            $this->assertLessThan(strpos($out, 'aaa111'), strpos($out, 'bbb222'));
        } finally {
            putenv($oldHome === false ? 'HOME' : "HOME={$oldHome}");
            $this->cleanupDir($tmpHome);
        }
    }

    public function test_show_specific_id_prints_summary(): void
    {
        $tmpHome = sys_get_temp_dir() . '/smart_cmd_test_' . bin2hex(random_bytes(4));
        $runsDir = $tmpHome . '/.superagent/smart_runs';
        @mkdir($runsDir, 0775, true);
        $payload = [
            'task' => 'demo task',
            'brain' => 'fake-brain',
            'plan' => [
                'complexity' => 'simple', 'primary_dim' => 'reasoning', 'concurrency' => 'serial',
                'subtasks' => [['id' => '1', 'prompt' => 'p', 'difficulty' => 'hard', 'dim' => 'reasoning']],
            ],
            'subtask_results' => [['id' => '1', 'difficulty' => 'hard', 'dim' => 'reasoning', 'model' => 'm', 'output' => 'o', 'latency_ms' => 100, 'cost_usd' => 0.01]],
            'final' => 'final answer',
            'total_cost_usd' => 0.0123,
            'total_latency_ms' => 200,
            'ran_at' => '2026-05-12T10:00:00+00:00',
        ];
        file_put_contents($runsDir . '/2026-05-12_100000_xyz999.json', json_encode($payload));

        $oldHome = getenv('HOME');
        putenv("HOME={$tmpHome}");

        try {
            $cmd = new SmartCommand();
            ob_start();
            $code = $cmd->execute(['smart_args' => ['show', '--last']]);
            $out = ob_get_clean();
            $this->assertSame(0, $code);
            $this->assertStringContainsString('xyz999', $out);
            $this->assertStringContainsString('fake-brain', $out);
            $this->assertStringContainsString('demo task', $out);
        } finally {
            putenv($oldHome === false ? 'HOME' : "HOME={$oldHome}");
            $this->cleanupDir($tmpHome);
        }
    }

    public function test_show_unknown_id_returns_error_code(): void
    {
        $tmpHome = sys_get_temp_dir() . '/smart_cmd_test_' . bin2hex(random_bytes(4));
        $runsDir = $tmpHome . '/.superagent/smart_runs';
        @mkdir($runsDir, 0775, true);
        file_put_contents($runsDir . '/some-run.json', '{}');

        $oldHome = getenv('HOME');
        putenv("HOME={$tmpHome}");

        try {
            $cmd = new SmartCommand();
            ob_start();
            $code = $cmd->execute(['smart_args' => ['show', 'nonexistent-12345']]);
            ob_end_clean();
            $this->assertSame(2, $code);
        } finally {
            putenv($oldHome === false ? 'HOME' : "HOME={$oldHome}");
            $this->cleanupDir($tmpHome);
        }
    }

    public function test_replay_with_no_args_returns_usage_code(): void
    {
        $cmd = new SmartCommand();
        ob_start();
        $code = $cmd->execute(['smart_args' => ['replay']]);
        ob_end_clean();
        $this->assertSame(2, $code);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Drive the global argv parser the same way `bin/superagent` does.
     *
     * @return array<string, mixed>
     */
    private function parse(array $argv): array
    {
        $app = new SuperAgentApplication();
        $ref = new \ReflectionClass($app);
        $m = $ref->getMethod('parseOptions');
        $m->setAccessible(true);
        return $m->invoke($app, $argv);
    }

    /**
     * Drive SmartCommand::parseArgs() directly.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parseArgs(array $args): array
    {
        $cmd = new SmartCommand();
        $ref = new \ReflectionClass($cmd);
        $m = $ref->getMethod('parseArgs');
        $m->setAccessible(true);
        return $m->invoke($cmd, $args);
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir . '/' . $i;
            is_dir($p) ? $this->cleanupDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
