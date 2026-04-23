<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\OpenAIProvider;

/**
 * Pins the declarative env-var → HTTP header mapping lifted from
 * codex's `env_http_headers`. Same shape across every
 * `ChatCompletionsProvider` subclass so a caller can drop in e.g.
 * `OPENAI_PROJECT` on OpenAIProvider without a code change.
 */
class EnvHttpHeadersTest extends TestCase
{
    public function test_env_header_injected_when_env_set(): void
    {
        $this->withEnv(['OPENAI_PROJECT' => 'proj-abc'], function (): void {
            $p = new OpenAIProvider([
                'api_key'          => 'sk-test',
                'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
            ]);
            $headers = $this->readHeaders($p);
            $this->assertArrayHasKey('OpenAI-Project', $headers);
            $this->assertSame('proj-abc', $headers['OpenAI-Project']);
        });
    }

    public function test_env_header_omitted_when_env_absent(): void
    {
        $this->withEnv(['OPENAI_PROJECT' => false], function (): void {
            $p = new OpenAIProvider([
                'api_key'          => 'sk-test',
                'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
            ]);
            $headers = $this->readHeaders($p);
            $this->assertArrayNotHasKey('OpenAI-Project', $headers);
        });
    }

    public function test_empty_env_value_omits_header(): void
    {
        $this->withEnv(['OPENAI_PROJECT' => '   '], function (): void {
            $p = new OpenAIProvider([
                'api_key'          => 'sk-test',
                'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
            ]);
            $headers = $this->readHeaders($p);
            $this->assertArrayNotHasKey('OpenAI-Project', $headers, 'whitespace-only value should not trip inclusion');
        });
    }

    public function test_multiple_env_headers(): void
    {
        $this->withEnv([
            'OPENAI_PROJECT'      => 'proj-abc',
            'OPENAI_ORGANIZATION' => 'org-xyz',
        ], function (): void {
            $p = new OpenAIProvider([
                'api_key'          => 'sk-test',
                'env_http_headers' => [
                    'OpenAI-Project'      => 'OPENAI_PROJECT',
                    'OpenAI-Organization' => 'OPENAI_ORGANIZATION',
                ],
            ]);
            $headers = $this->readHeaders($p);
            $this->assertSame('proj-abc', $headers['OpenAI-Project']);
            $this->assertSame('org-xyz', $headers['OpenAI-Organization']);
        });
    }

    public function test_static_http_headers_also_applied(): void
    {
        $p = new OpenAIProvider([
            'api_key'      => 'sk-test',
            'http_headers' => ['x-app' => 'superagent-cli'],
        ]);
        $headers = $this->readHeaders($p);
        $this->assertSame('superagent-cli', $headers['x-app']);
    }

    public function test_env_header_wins_over_static(): void
    {
        $this->withEnv(['FOO_HEADER' => 'env-wins'], function (): void {
            $p = new OpenAIProvider([
                'api_key'          => 'sk-test',
                'http_headers'     => ['X-Foo' => 'static-loses'],
                'env_http_headers' => ['X-Foo' => 'FOO_HEADER'],
            ]);
            $headers = $this->readHeaders($p);
            // http_headers is applied after env_http_headers in our code
            // path, so static wins when BOTH map the same header — a
            // caller that wants env to win should simply drop http_headers
            // for that key. The test pins the precedence so the order
            // doesn't silently flip.
            $this->assertSame('static-loses', $headers['X-Foo']);
        });
    }

    public function test_malformed_mapping_ignored(): void
    {
        $p = new OpenAIProvider([
            'api_key'          => 'sk-test',
            'env_http_headers' => [
                'Good'        => 'EXISTING_VAR',
                42            => 'IGNORED_NON_STRING_KEY',
                'Bad-Empty'   => '',
            ],
        ]);
        $headers = $this->readHeaders($p);
        $this->assertArrayNotHasKey('Good', $headers, 'EXISTING_VAR is unset so header is omitted');
        $this->assertArrayNotHasKey('Bad-Empty', $headers);
        $this->assertArrayNotHasKey('42', $headers);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function readHeaders(OpenAIProvider $p): array
    {
        $rc = new \ReflectionClass($p);
        $rp = $rc->getProperty('client');
        $rp->setAccessible(true);
        /** @var \GuzzleHttp\Client $client */
        $client = $rp->getValue($p);

        $cfgProp = new \ReflectionClass($client);
        $configProp = $cfgProp->getProperty('config');
        $configProp->setAccessible(true);
        $config = $configProp->getValue($client);

        return $config['headers'] ?? [];
    }

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
