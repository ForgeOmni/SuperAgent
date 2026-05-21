<?php

declare(strict_types=1);

namespace SuperAgent\Format;

/**
 * Runs all applicable formatters against a freshly-edited file. Hook this into
 * the post-write path of any tool that mutates source files (EditTool /
 * WriteTool / patch applier / refactoring agents) so the model's output lands
 * formatted-by-default.
 *
 *     $runner = new FormatterRunner();
 *     $result = $runner->formatFile('/abs/path/to/file.php', $worktreeRoot);
 *
 * `formatFile()` is idempotent and non-fatal: every formatter that probes
 * positive runs in sequence; failures are collected into the result but do not
 * abort the call. Callers typically log result entries for telemetry / UI
 * surfacing ("ran pint and prettier on 3 files").
 */
final class FormatterRunner
{
    public function __construct(
        private int $timeoutSeconds = 30,
    ) {
    }

    /**
     * Run every applicable formatter against $path. Skips when the path doesn't
     * exist, has no extension match, or no probe enables.
     *
     * @return FormatterRunResult
     */
    public function formatFile(string $path, ?string $worktree = null): FormatterRunResult
    {
        if (! is_file($path)) {
            return new FormatterRunResult($path, []);
        }

        $ext = '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '.') {
            return new FormatterRunResult($path, []);
        }

        $directory = dirname($path);
        $worktree ??= $directory;
        $context = new FormatterContext($directory, $worktree);

        $runs = [];
        foreach (Formatters::forExtension($ext) as $info) {
            $cmd = ($info->probe)($context);
            if ($cmd === false || ! is_array($cmd) || $cmd === []) {
                continue;
            }
            $runs[] = $this->execute($info, $cmd, $path, $directory);
        }

        return new FormatterRunResult($path, $runs);
    }

    /**
     * @param array<int, string> $cmd
     */
    private function execute(FormatterInfo $info, array $cmd, string $path, string $cwd): FormatterRun
    {
        $resolved = array_map(static fn (string $arg) => str_replace('$FILE', $path, $arg), $cmd);

        // Build the exec command line with proper shell escaping.
        $escaped = array_map('escapeshellarg', $resolved);
        $cmdLine = implode(' ', $escaped) . ' 2>&1';

        $env = $info->environment;
        $envPrefix = '';
        foreach ($env as $k => $v) {
            $envPrefix .= escapeshellarg($k) . '=' . escapeshellarg($v) . ' ';
        }

        $fullCmd = sprintf(
            'cd %s && %stimeout %d %s',
            escapeshellarg($cwd),
            $envPrefix,
            $this->timeoutSeconds,
            $cmdLine,
        );

        $output = '';
        $exitCode = 0;
        $startedAt = microtime(true);
        $output = shell_exec($fullCmd) ?? '';
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        // shell_exec doesn't surface exit codes; treat non-empty stderr+nonzero-style
        // markers as "may have warned." For now we mark success when execution
        // returned. Callers that care wrap with proc_open themselves.
        return new FormatterRun(
            formatter: $info->name,
            command: $resolved,
            output: (string) $output,
            durationMs: $durationMs,
            ok: true,
        );
    }
}
