<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Providers\ProviderRegistry;

/**
 * `superagent health` — reachability probe for every direct-HTTP provider.
 *
 * Wraps `ProviderRegistry::healthCheck()` (a 5s cURL GET against each
 * provider's cheapest listing endpoint) so an operator can tell auth
 * rejection, network timeout, and "no API key" apart from one table.
 *
 * Behaviour parallels SuperAICore's `api:status` command — a sibling in
 * the downstream Laravel package — with the probe hoisted into the SDK
 * itself so standalone CLI users don't have to install anything extra.
 *
 * Arguments:
 *   (none)                      Probe every provider whose API-key env var
 *                               is set OR whose OAuth credential file
 *                               exists under ~/.superagent/credentials/.
 *   --all                       Probe every known provider even without a
 *                               key — useful for spotting which env vars
 *                               are missing.
 *   --provider <a,b,c>          Comma-separated subset.
 *   --json                      Machine-readable output (array of rows).
 */
final class HealthCommand
{
    /**
     * Providers we consider first-class for `superagent health`. Bedrock
     * and Ollama are intentionally omitted — Bedrock routes through the
     * AWS SDK (no plain HTTP probe) and Ollama is a local daemon the
     * operator owns, so its dashboard entry would always be green.
     *
     * `qwen-native` is listed alongside `qwen` because 0.9.0 split the
     * registry key in two — both share `QWEN_API_KEY`, so one key gets
     * two rows in the dashboard.
     *
     * @var list<string>
     */
    private const DEFAULT_PROVIDERS = [
        'anthropic',
        'openai',
        'openrouter',
        'gemini',
        'kimi',
        'qwen',
        'qwen-native',
        'glm',
        'minimax',
    ];

    /**
     * Env var per provider, used by {@see filterToConfigured()} to prune
     * providers the operator hasn't set up from the default probe list.
     *
     * @var array<string,string>
     */
    private const ENV_KEY = [
        'anthropic'   => 'ANTHROPIC_API_KEY',
        'openai'      => 'OPENAI_API_KEY',
        'openrouter'  => 'OPENROUTER_API_KEY',
        'gemini'      => 'GEMINI_API_KEY',
        'kimi'        => 'KIMI_API_KEY',
        'qwen'        => 'QWEN_API_KEY',
        'qwen-native' => 'QWEN_API_KEY',
        'glm'         => 'GLM_API_KEY',
        'minimax'     => 'MINIMAX_API_KEY',
    ];

    /**
     * OAuth credential file name (without `.json`) per provider that
     * accepts a stored OAuth bearer in place of an API key. A file at
     * `~/.superagent/credentials/<key>.json` counts as "configured" even
     * when the matching env var is unset — so `superagent auth login
     * kimi-code` / `qwen-code` users show up in the default probe.
     *
     * @var array<string,string>
     */
    private const OAUTH_CREDENTIAL = [
        'kimi' => 'kimi-code',
        'qwen' => 'qwen-code',
    ];

    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['health_args'] ?? [];
        // The top-level arg parser in SuperAgentApplication silently
        // drops unrecognised long flags (`--all`, `--providers=…`), so
        // fall through to raw argv to pick those up. `--json` is known
        // globally and arrives via $options['json'].
        $rawArgv = array_slice($_SERVER['argv'] ?? [], 1);

        // `--json` is consumed by the top-level arg parser (shared with other
        // subcommands). Read from the normalised slot first; fall back to the
        // raw subcommand args so `superagent health --json` keeps working if
        // someone tightens the top-level parser later.
        $json = (bool) ($options['json'] ?? in_array('--json', $args, true));
        $all  = in_array('--all', $args, true) || in_array('--all', $rawArgv, true);

        // `--provider` is also a top-level flag (normally used for
        // provider *selection* on chat). Read the comma-separated
        // subset from either the top-level slot or an inline
        // `--providers` token in health_args (sibling of SuperAICore's
        // `api:status --providers=` convention).
        $providers = null;
        $topProvider = $options['provider'] ?? null;
        if (is_string($topProvider) && $topProvider !== '') {
            $providers = array_values(array_filter(array_map('trim', explode(',', $topProvider))));
        }
        foreach (array_merge($args, $rawArgv) as $i => $a) {
            if (str_starts_with($a, '--providers=') || str_starts_with($a, '--provider=')) {
                $spec = substr($a, (int) strpos($a, '=') + 1);
                if ($spec !== '') {
                    $providers = array_values(array_filter(array_map('trim', explode(',', $spec))));
                }
            }
        }

        if ($providers === null) {
            $providers = $all
                ? self::DEFAULT_PROVIDERS
                : self::filterToConfigured(self::DEFAULT_PROVIDERS);
        }

        if ($providers === []) {
            if ($json) {
                echo json_encode([], JSON_UNESCAPED_SLASHES) . PHP_EOL;
                return 0;
            }
            $renderer->warning('No configured providers. Set an API key env var (e.g. ANTHROPIC_API_KEY) or run `superagent auth login <provider>` — then re-run `superagent health`.');
            $renderer->hint('Pass --all to probe every known provider regardless of configuration.');
            return 0;
        }

        $results = [];
        foreach ($providers as $p) {
            $results[] = self::normalise(ProviderRegistry::healthCheck($p), $p);
        }

        if ($json) {
            echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            // Exit non-zero iff any provider failed, so CI can `|| exit`
            // on a dead key.
            foreach ($results as $r) {
                if (! ($r['ok'] ?? false)) return 1;
            }
            return 0;
        }

        $this->renderTable($renderer, $results);

        $failed = 0;
        foreach ($results as $r) {
            if (! ($r['ok'] ?? false)) $failed++;
        }
        return $failed > 0 ? 1 : 0;
    }

    /**
     * Normalise `ProviderRegistry::healthCheck()` output so `latency_ms`
     * and `reason` are always present — makes the table / JSON layer
     * simpler (no null-coalesce at the call site).
     *
     * @param  array<string,mixed> $raw
     * @return array{provider:string,ok:bool,latency_ms:?int,reason:?string}
     */
    private static function normalise(array $raw, string $fallbackName): array
    {
        return [
            'provider'   => (string) ($raw['provider'] ?? $fallbackName),
            'ok'         => (bool)   ($raw['ok']       ?? false),
            'latency_ms' => isset($raw['latency_ms']) ? (int) $raw['latency_ms'] : null,
            'reason'     => isset($raw['reason']) ? (string) $raw['reason'] : null,
        ];
    }

    /**
     * Keep only providers that have either an API-key env var set or an
     * SDK OAuth credential on disk.
     *
     * @param  list<string> $providers
     * @return list<string>
     */
    public static function filterToConfigured(array $providers): array
    {
        return array_values(array_filter($providers, static function (string $p): bool {
            $envName = self::ENV_KEY[$p] ?? null;
            if ($envName !== null) {
                $val = $_ENV[$envName] ?? getenv($envName);
                if ($val !== false && $val !== '') {
                    return true;
                }
            }
            return self::hasOauthCredential($p);
        }));
    }

    private static function hasOauthCredential(string $provider): bool
    {
        $key = self::OAUTH_CREDENTIAL[$provider] ?? null;
        if ($key === null) return false;

        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');
        if (! is_string($home) || $home === '') return false;

        return is_file($home . '/.superagent/credentials/' . $key . '.json');
    }

    /**
     * @param list<array{provider:string,ok:bool,latency_ms:?int,reason:?string}> $rows
     */
    private function renderTable(Renderer $renderer, array $rows): void
    {
        $pad = 12;
        foreach ($rows as $r) {
            $pad = max($pad, strlen($r['provider']));
        }

        $renderer->line(str_pad('Provider', $pad + 2) . str_pad('Status', 10) . str_pad('Latency', 12) . 'Reason');
        $renderer->line(str_repeat('─', $pad + 2 + 10 + 12 + 40));

        foreach ($rows as $r) {
            $badge = $r['ok'] ? "\033[32m✓ ok\033[0m" : "\033[31m✗ fail\033[0m";
            $latency = $r['latency_ms'] !== null ? ($r['latency_ms'] . 'ms') : '—';
            $reason  = (string) ($r['reason'] ?? '');
            $renderer->line(
                str_pad($r['provider'], $pad + 2)
                . str_pad($badge, 10 + 9)  // +9 for ANSI escape bytes
                . str_pad($latency, 12)
                . $reason
            );
        }
    }
}
