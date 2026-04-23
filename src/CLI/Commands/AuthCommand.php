<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\Auth\AuthenticationException;
use SuperAgent\Auth\ClaudeCodeCredentials;
use SuperAgent\Auth\CodexCredentials;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\Auth\DeviceCodeFlow;
use SuperAgent\Auth\GeminiCliCredentials;
use SuperAgent\Auth\KimiCodeCredentials;
use SuperAgent\Auth\QwenCodeCredentials;
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
            'kimi-code', 'kimi' => $this->loginKimiCode(),
            'qwen-code', 'qwen' => $this->loginQwenCode(),
            '' => $this->usage(),
            default => $this->usage("Unknown provider: {$provider}"),
        };
    }

    /**
     * Kimi Code is different from the other `login` paths — there's no
     * sibling CLI on the user's machine to import from. We run the
     * full RFC 8628 device-code flow ourselves: display the code +
     * verification URL, poll the token endpoint, persist on success.
     *
     * `DeviceCodeFlow` already encodes the polling + retry semantics
     * (and already honours `SUPERAGENT_NO_BROWSER` / CI env guards so
     * tests don't launch real browsers). We just point it at Kimi's
     * endpoints and translate the returned TokenResponse into
     * `KimiCodeCredentials`'s storage shape.
     */
    protected function loginKimiCode(?KimiCodeCredentials $credsOverride = null, ?DeviceCodeFlow $flowOverride = null): int
    {
        $r = $this->renderer;
        $creds = $credsOverride ?? new KimiCodeCredentials($this->store);
        $host = rtrim($creds->host(), '/');

        $flow = $flowOverride ?? new DeviceCodeFlow(
            clientId:      KimiCodeCredentials::CLIENT_ID,
            deviceCodeUrl: $host . KimiCodeCredentials::DEVICE_AUTH_PATH,
            tokenUrl:      $host . KimiCodeCredentials::TOKEN_PATH,
            scopes:        [],
            outputCallback: static fn (string $msg) => $r->line($msg),
        );

        $r->info('Starting Kimi Code OAuth device flow...');

        try {
            $token = $flow->authenticate();
        } catch (AuthenticationException $e) {
            $r->error('Login failed: ' . $e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            $r->error('Unexpected error during login: ' . $e->getMessage());
            return 1;
        }

        $creds->save([
            'access_token'  => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at'    => $token->expiresIn !== null ? time() + $token->expiresIn : null,
            'scopes'        => $token->scope !== '' ? explode(' ', $token->scope) : [],
        ]);

        $r->success('Logged in to Kimi Code.');
        $r->hint('Use this account by setting KIMI_REGION=code when running SuperAgent.');
        return 0;
    }

    /**
     * Qwen Code OAuth device flow with PKCE S256. Mirrors Kimi Code
     * except:
     *   - `chat.qwen.ai/api/v1/oauth2/device/code` endpoints
     *   - PKCE verifier + S256 challenge (Alibaba requires them)
     *   - Token response includes `resource_url`, a per-account
     *     DashScope base URL that `QwenProvider` picks up at
     *     construction time when `region=code`.
     */
    protected function loginQwenCode(?QwenCodeCredentials $credsOverride = null, ?DeviceCodeFlow $flowOverride = null): int
    {
        $r = $this->renderer;
        $creds = $credsOverride ?? new QwenCodeCredentials($this->store);
        $host = rtrim($creds->host(), '/');

        // RFC 7636 PKCE — Alibaba requires S256; omit entirely and the
        // device-code request fails with a confusing error. Generate
        // once, include in both the device-code request and the
        // token poll via DeviceCodeFlow constructor args.
        $pkce = DeviceCodeFlow::generatePkcePair();

        $flow = $flowOverride ?? new DeviceCodeFlow(
            clientId:            QwenCodeCredentials::CLIENT_ID,
            deviceCodeUrl:       $host . QwenCodeCredentials::DEVICE_AUTH_PATH,
            tokenUrl:            $host . QwenCodeCredentials::TOKEN_PATH,
            scopes:              explode(' ', QwenCodeCredentials::DEFAULT_SCOPE),
            outputCallback:      static fn (string $msg) => $r->line($msg),
            pkceCodeVerifier:    $pkce['code_verifier'],
            pkceCodeChallenge:   $pkce['code_challenge'],
            pkceChallengeMethod: $pkce['code_challenge_method'],
        );

        $r->info('Starting Qwen Code OAuth device flow...');

        try {
            $token = $flow->authenticate();
        } catch (AuthenticationException $e) {
            $r->error('Login failed: ' . $e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            $r->error('Unexpected error during login: ' . $e->getMessage());
            return 1;
        }

        // `resource_url` lives in the extras map — Alibaba's token
        // response spec carries it alongside the standard fields.
        $resourceUrl = is_array($token->extra ?? null)
            ? ($token->extra['resource_url'] ?? null)
            : null;

        $creds->save([
            'access_token'  => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at'    => $token->expiresIn !== null ? time() + $token->expiresIn : null,
            'resource_url'  => is_string($resourceUrl) ? $resourceUrl : null,
            'scopes'        => $token->scope !== '' ? explode(' ', $token->scope) : [],
        ]);

        $r->success('Logged in to Qwen Code.');
        $r->hint('Use this account by setting QWEN_REGION=code when running SuperAgent.');
        if (is_string($resourceUrl) && $resourceUrl !== '') {
            $r->hint("Account-specific DashScope base URL: {$resourceUrl}");
        }
        return 0;
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
            'kimi-code', 'kimi' => KimiCodeCredentials::CREDENTIAL_NAME,  // = 'kimi-code'
            'qwen-code', 'qwen' => QwenCodeCredentials::CREDENTIAL_NAME,  // = 'qwen-code'
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
        $this->renderer->line('  superagent auth login kimi-code');
        $this->renderer->line('  superagent auth login qwen-code');
        $this->renderer->line('  superagent auth status');
        $this->renderer->line('  superagent auth logout <claude-code|codex|gemini|kimi-code|qwen-code>');
        return $error !== null ? 1 : 0;
    }
}
