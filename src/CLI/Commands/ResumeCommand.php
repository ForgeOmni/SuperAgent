<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Conversation\HarnessImporter;
use SuperAgent\Conversation\Importers\ClaudeCodeImporter;
use SuperAgent\Conversation\Importers\CodexImporter;

/**
 * `superagent resume` — pick up a session that another harness wrote.
 *
 * Subcommands:
 *   list  --from <harness>                   Print recent sessions from `harness`.
 *   show  --from <harness> --session <id>    Pretty-print the imported message trail.
 *   load  --from <harness> --session <id>    Emit the message trail as JSON on stdout
 *                                            (suitable for piping into `Agent::loadMessages`
 *                                            from a host script).
 *
 * Borrowed from jcode's session-resume capability. Importers are
 * pluggable via `HarnessImporter`; the bundled set covers Claude Code
 * and Codex CLI today. To resume on a different provider afterwards,
 * pipe `load` through `superagent chat --provider <new> --resume-stdin`
 * (handled by ChatCommand when given `--resume-stdin`).
 *
 * Example:
 *
 *   # See what's around.
 *   superagent resume list --from claude
 *
 *   # Inspect a session by id.
 *   superagent resume show --from claude --session 8e2c-...
 *
 *   # Continue the conversation in SuperAgent against Kimi.
 *   superagent resume load --from claude --session 8e2c-... \
 *     | superagent chat --provider kimi --resume-stdin
 */
final class ResumeCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        // Subcommand from the parsed options (positional after `resume`),
        // flags re-parsed from the raw argv because the global parser
        // strips unknown flags. Reading both means we tolerate any
        // future global option additions without touching this command.
        $resumeArgs = $options['resume_args'] ?? [];
        $sub = strtolower((string) ($resumeArgs[0] ?? 'list'));
        $flags = $this->parseFlagsFromArgv();

        $from = (string) ($flags['from'] ?? 'claude');
        $importer = $this->resolveImporter($from);
        if ($importer === null) {
            $renderer->error("Unknown harness: {$from}. Supported: claude, codex.");
            return 1;
        }

        return match ($sub) {
            'list', 'ls'      => $this->list($renderer, $importer, $flags),
            'show'            => $this->show($renderer, $importer, $flags),
            'load', 'export'  => $this->load($renderer, $importer, $flags),
            default           => $this->usage($renderer, $sub),
        };
    }

    private function list(Renderer $renderer, HarnessImporter $importer, array $flags): int
    {
        $limit = (int) ($flags['limit'] ?? 25);
        $rows = $importer->listSessions($limit);
        if ($rows === []) {
            $renderer->info("No {$importer->harness()} sessions found on this machine.");
            return 0;
        }

        if (!empty($flags['json'])) {
            fwrite(STDOUT, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return 0;
        }

        $renderer->info(sprintf('%d %s sessions:', count($rows), $importer->harness()));
        foreach ($rows as $r) {
            $when = $r['started_at'] ?? '?';
            $proj = $r['project'] ?? '-';
            $count = $r['message_count'] ?? '?';
            $first = $r['first_user_message'] ?? '';
            fwrite(STDOUT, sprintf("  %s  [%s msgs]  %s\n    └─ %s\n",
                $r['id'],
                str_pad((string) $count, 4, ' ', STR_PAD_LEFT),
                $proj,
                $first ?: '(no user message captured)',
            ));
            fwrite(STDOUT, "    " . $when . "\n");
        }
        return 0;
    }

    private function show(Renderer $renderer, HarnessImporter $importer, array $flags): int
    {
        $session = (string) ($flags['session'] ?? $flags['id'] ?? '');
        if ($session === '') {
            $renderer->error('--session <id> is required.');
            return 1;
        }
        try {
            $messages = $importer->load($session);
        } catch (\Throwable $e) {
            $renderer->error('Load failed: ' . $e->getMessage());
            return 1;
        }
        if ($messages === []) {
            $renderer->info('Session loaded but contained no replayable messages.');
            return 0;
        }
        $renderer->info(sprintf('Loaded %d messages from %s session %s', count($messages), $importer->harness(), $session));
        foreach ($messages as $i => $msg) {
            $arr = method_exists($msg, 'toArray') ? $msg->toArray() : (array) $msg;
            $role = $arr['role'] ?? '?';
            $content = $arr['content'] ?? '';
            if (is_array($content)) {
                $content = json_encode($content, JSON_UNESCAPED_UNICODE);
            }
            fwrite(STDOUT, sprintf("  [%03d] %s: %s\n", $i + 1, $role, mb_substr((string) $content, 0, 200)));
        }
        return 0;
    }

    private function load(Renderer $renderer, HarnessImporter $importer, array $flags): int
    {
        $session = (string) ($flags['session'] ?? $flags['id'] ?? '');
        if ($session === '') {
            $renderer->error('--session <id> is required.');
            return 1;
        }
        try {
            $messages = $importer->load($session);
        } catch (\Throwable $e) {
            $renderer->error('Load failed: ' . $e->getMessage());
            return 1;
        }
        $shape = [
            'harness'   => $importer->harness(),
            'session'   => $session,
            'messages'  => array_map(static fn ($m) => method_exists($m, 'toArray') ? $m->toArray() : (array) $m, $messages),
        ];
        fwrite(STDOUT, json_encode($shape, JSON_UNESCAPED_UNICODE) . "\n");
        return 0;
    }

    private function usage(Renderer $renderer, string $sub): int
    {
        $renderer->error("Unknown subcommand: resume {$sub}");
        fwrite(STDERR, "Usage:\n");
        fwrite(STDERR, "  superagent resume list  --from <claude|codex> [--limit N] [--json]\n");
        fwrite(STDERR, "  superagent resume show  --from <claude|codex> --session <id>\n");
        fwrite(STDERR, "  superagent resume load  --from <claude|codex> --session <id>\n");
        return 1;
    }

    /**
     * Pull our own flags off the raw process argv. The global parser
     * drops unknown flags into the void, so we re-scan here. We start
     * from the position of the literal `resume` token to avoid mistaking
     * a global flag (e.g. `--provider` from a piped call) for one of ours.
     *
     * @return array<string,string|true>
     */
    private function parseFlagsFromArgv(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $start = 0;
        foreach ($argv as $idx => $token) {
            if ($token === 'resume') { $start = $idx + 1; break; }
        }
        $tokens = array_slice($argv, $start);

        $flags = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $arg = $tokens[$i];
            if (!is_string($arg) || !str_starts_with($arg, '--')) continue;
            $name = substr($arg, 2);
            $eq = strpos($name, '=');
            if ($eq !== false) {
                $flags[substr($name, 0, $eq)] = substr($name, $eq + 1);
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if (is_string($next) && !str_starts_with($next, '--')) {
                $flags[$name] = $next;
                $i++;
            } else {
                $flags[$name] = true;
            }
        }
        return $flags;
    }

    private function resolveImporter(string $harness): ?HarnessImporter
    {
        return match ($harness) {
            'claude', 'claude-code', 'cc' => new ClaudeCodeImporter(),
            'codex'                       => new CodexImporter(),
            default                       => null,
        };
    }
}
