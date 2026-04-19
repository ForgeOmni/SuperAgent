<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\Auth\ClaudeCodeCredentials;
use SuperAgent\Auth\CodexCredentials;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\Auth\GeminiCliCredentials;
use SuperAgent\CLI\Terminal\Renderer;

/**
 * Manage OAuth credentials imported from other local CLIs.
 *
 *   superagent auth login claude-code   Import + store Claude Code OAuth token
 *   superagent auth login codex          Import + store Codex OAuth token / API key
 *   superagent auth status               Show what is currently stored
 *   superagent auth logout <provider>    Remove stored credentials
 */
class AuthCommand
{
    private Renderer $renderer;
    private CredentialStore $store;

    public function __construct(?Renderer $renderer = null, ?CredentialStore $store = null)
    {
        $this->renderer = $renderer ?? new Renderer();
        $this->store = $store ?? new CredentialStore();
    }

    public function execute(array $options): int
    {
        $args = $options['auth_args'] ?? [];
        $sub = $args[0] ?? 'status';

        return match ($sub) {
            'login' => $this->login($args[1] ?? ''),
            'logout' => $this->logout($args[1] ?? ''),
            'status' => $this->status(),
            default => $this->usage(),
        };
    }

    private function login(string $provider): int
    {
        return match ($provider) {
            'claude', 'claude-code', 'anthropic' => $this->loginClaudeCode(),
            'codex', 'openai' => $this->loginCodex(),
            'gemini', 'gemini-cli', 'google' => $this->loginGeminiCli(),
            '' => $this->usage(),
            default => $this->usage("Unknown provider: {$provider}"),
        };
    }

    private function loginClaudeCode(): int
    {
        $r = $this->renderer;
        $reader = ClaudeCodeCredentials::default();

        if (! $reader->exists()) {
            $r->error("Claude Code credentials not found at: {$reader->path()}");
            $r->hint('Install Claude Code and run `claude login` first.');
            return 1;
        }

        $creds = $reader->read();
        if ($creds === null) {
            $r->error("Could not parse Claude Code credentials at: {$reader->path()}");
            return 1;
        }

        if ($reader->isExpired($creds)) {
            $r->info('Token expired, refreshing...');
            $refreshed = $reader->refresh($creds);
            if ($refreshed !== null) {
                $creds = $refreshed;
            } else {
                $r->warning('Refresh failed; stored token may be expired.');
            }
        }

        $this->store->store('anthropic', 'access_token', $creds['access_token']);
        if (! empty($creds['refresh_token'])) {
            $this->store->store('anthropic', 'refresh_token', $creds['refresh_token']);
        }
        if (! empty($creds['expires_at'])) {
            $this->store->store('anthropic', 'expires_at', (string) $creds['expires_at']);
        }
        $this->store->store('anthropic', 'auth_mode', 'oauth');
        $this->store->store('anthropic', 'source', 'claude-code');
        if (! empty($creds['subscription'])) {
            $this->store->store('anthropic', 'subscription', $creds['subscription']);
        }

        $r->success('Imported Claude Code OAuth token.');
        if (! empty($creds['subscription'])) {
            $r->hint("Subscription: {$creds['subscription']}");
        }
        return 0;
    }

    private function loginCodex(): int
    {
        $r = $this->renderer;
        $reader = CodexCredentials::default();

        if (! $reader->exists()) {
            $r->error("Codex credentials not found at: {$reader->path()}");
            $r->hint('Install Codex CLI and run `codex login` first.');
            return 1;
        }

        $creds = $reader->read();
        if ($creds === null) {
            $r->error("Could not parse Codex credentials at: {$reader->path()}");
            return 1;
        }

        if ($creds['mode'] === 'oauth' && $reader->isExpired($creds)) {
            $r->info('Token expired, refreshing...');
            $refreshed = $reader->refresh($creds);
            if ($refreshed !== null) {
                $creds = $refreshed;
            } else {
                $r->warning('Refresh failed; stored token may be expired.');
            }
        }

        if ($creds['mode'] === 'oauth') {
            $this->store->store('openai', 'access_token', $creds['access_token']);
            if (! empty($creds['refresh_token'])) {
                $this->store->store('openai', 'refresh_token', $creds['refresh_token']);
            }
            if (! empty($creds['id_token'])) {
                $this->store->store('openai', 'id_token', $creds['id_token']);
            }
            if (! empty($creds['account_id'])) {
                $this->store->store('openai', 'account_id', $creds['account_id']);
            }
            $this->store->store('openai', 'auth_mode', 'oauth');
        } else {
            $this->store->store('openai', 'api_key', $creds['api_key']);
            $this->store->store('openai', 'auth_mode', 'api_key');
        }
        $this->store->store('openai', 'source', 'codex');

        $r->success(sprintf('Imported Codex credentials (%s).', $creds['mode']));
        return 0;
    }

    private function loginGeminiCli(): int
    {
        $r = $this->renderer;
        $reader = GeminiCliCredentials::default();

        if (! $reader->exists()) {
            $r->error("Gemini CLI credentials not found at: {$reader->path()}");
            $r->hint('Install @google/gemini-cli and run `gemini login`,');
            $r->hint('  or export GEMINI_API_KEY / GOOGLE_API_KEY in your shell.');
            return 1;
        }

        $creds = $reader->read();
        if ($creds === null) {
            $r->error("Could not parse Gemini credentials at: {$reader->path()}");
            return 1;
        }

        if ($creds['mode'] === 'oauth' && $reader->isExpired($creds)) {
            $r->warning('Gemini OAuth token expired.');
            $r->hint('Run `gemini login` to refresh, then re-run this import.');
        }

        if ($creds['mode'] === 'oauth') {
            $this->store->store('gemini', 'access_token', (string) $creds['access_token']);
            if (! empty($creds['refresh_token'])) {
                $this->store->store('gemini', 'refresh_token', (string) $creds['refresh_token']);
            }
            if (! empty($creds['expires_at'])) {
                $this->store->store('gemini', 'expires_at', (string) $creds['expires_at']);
            }
            $this->store->store('gemini', 'auth_mode', 'oauth');
        } else {
            $this->store->store('gemini', 'api_key', (string) $creds['api_key']);
            $this->store->store('gemini', 'auth_mode', 'api_key');
        }
        $this->store->store('gemini', 'source', $creds['source'] === 'env' ? 'env' : 'gemini-cli');

        $r->success(sprintf('Imported Gemini credentials (%s).', $creds['mode']));
        return 0;
    }

    private function logout(string $provider): int
    {
        if ($provider === '') {
            return $this->usage();
        }
        $key = match ($provider) {
            'claude', 'claude-code', 'anthropic' => 'anthropic',
            'codex', 'openai' => 'openai',
            'gemini', 'gemini-cli', 'google' => 'gemini',
            default => null,
        };
        if ($key === null) {
            return $this->usage("Unknown provider: {$provider}");
        }
        $this->store->delete($key);
        $this->renderer->success("Removed stored credentials for {$key}.");
        return 0;
    }

    private function status(): int
    {
        $r = $this->renderer;
        $providers = $this->store->listProviders();
        if (empty($providers)) {
            $r->info('No stored credentials.');
            $r->hint('Run: superagent auth login claude-code');
            $r->hint('  or: superagent auth login codex');
            $r->hint('  or: superagent auth login gemini');
            return 0;
        }

        foreach ($providers as $p) {
            try {
                $mode = $this->store->get($p, 'auth_mode') ?? 'unknown';
                $source = $this->store->get($p, 'source') ?? '-';
                $suffix = '';
                if ($mode === 'oauth' && ($expAt = $this->store->get($p, 'expires_at'))) {
                    $expSec = (int) floor(((int) $expAt) / 1000);
                    $remaining = $expSec - time();
                    $suffix = $remaining > 0
                        ? sprintf(' (expires in %dh)', (int) floor($remaining / 3600))
                        : ' (expired)';
                }
                $r->line(sprintf('  %-12s  mode=%s  source=%s%s', $p, $mode, $source, $suffix));
            } catch (\SuperAgent\Auth\AuthenticationException $e) {
                $r->error(sprintf('  %-12s  <decrypt failed: %s>', $p, $e->getMessage()));
                $r->hint("  Re-run: superagent auth login {$p}");
            }
        }
        return 0;
    }

    private function usage(?string $error = null): int
    {
        if ($error !== null) {
            $this->renderer->error($error);
        }
        $this->renderer->line('Usage:');
        $this->renderer->line('  superagent auth login claude-code');
        $this->renderer->line('  superagent auth login codex');
        $this->renderer->line('  superagent auth login gemini');
        $this->renderer->line('  superagent auth status');
        $this->renderer->line('  superagent auth logout <claude-code|codex|gemini>');
        return $error !== null ? 1 : 0;
    }
}
