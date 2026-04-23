<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\KimiProvider;

class KimiProviderTest extends TestCase
{
    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/API key|KIMI_API_KEY/i');
        new KimiProvider([]);
    }

    public function test_default_region_is_intl(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $this->assertSame('intl', $p->getRegion());
        $this->assertSame('api.moonshot.ai', $this->host($p));
    }

    public function test_cn_region_maps_to_cn_host(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x', 'region' => 'cn']);
        $this->assertSame('cn', $p->getRegion());
        $this->assertSame('api.moonshot.cn', $this->host($p));
    }

    public function test_unknown_region_throws(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/region/');
        new KimiProvider(['api_key' => 'sk-x', 'region' => 'eu']);
    }

    public function test_code_region_maps_to_kimi_code_coding_v1(): void
    {
        // Code region is OAuth-only — pass an explicit access_token so
        // the provider's resolveBearer path succeeds without touching
        // the real credential store / user HOME.
        $p = new KimiProvider([
            'region' => 'code',
            'access_token' => 'oauth-tok',
        ]);
        $this->assertSame('code', $p->getRegion());
        $this->assertSame('api.kimi.com', $this->host($p));
    }

    public function test_code_region_with_no_oauth_and_no_api_key_throws(): void
    {
        // Simulate a fresh machine — no credentials, no KIMI_API_KEY.
        // The provider should surface the region-specific hint about
        // `superagent login kimi-code`, not the generic "API key is
        // required" message.
        $origHome = getenv('HOME');
        $tmp = sys_get_temp_dir() . '/superagent-kimi-bearer-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0755, true);
        putenv('HOME=' . $tmp);
        try {
            $this->expectException(ProviderException::class);
            $this->expectExceptionMessageMatches('/OAuth login|kimi-code/');
            new KimiProvider(['region' => 'code']);
        } finally {
            @rmdir($tmp);
            putenv($origHome === false ? 'HOME' : 'HOME=' . $origHome);
        }
    }

    public function test_device_identification_headers_are_sent(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $headers = $this->clientHeaders($p);
        foreach (['x-msh-platform', 'x-msh-device-id', 'x-msh-version', 'x-msh-os-version'] as $expected) {
            $this->assertArrayHasKey(
                $expected,
                $headers,
                "Kimi client must send the Moonshot identification header: {$expected}",
            );
        }
    }

    public function test_name_is_kimi(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $this->assertSame('kimi', $p->name());
    }

    public function test_default_model_is_k2_6(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $this->assertSame('kimi-k2-6', $p->getModel());
    }

    public function test_authorization_header_is_bearer_api_key(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-moon-1']);
        $headers = $this->clientHeaders($p);
        $this->assertSame('Bearer sk-moon-1', $headers['authorization']);
    }

    public function test_explicit_base_url_overrides_region_map(): void
    {
        $p = new KimiProvider([
            'api_key' => 'sk-x',
            'region' => 'intl',
            'base_url' => 'https://proxy.example.com',
        ]);
        $this->assertSame('proxy.example.com', $this->host($p));
    }

    private function host(object $provider): string
    {
        $client = $this->extractClient($provider);
        return parse_url((string) $client->getConfig('base_uri'), PHP_URL_HOST);
    }

    private function clientHeaders(object $provider): array
    {
        $client = $this->extractClient($provider);
        $headers = $client->getConfig()['headers'] ?? [];
        return array_change_key_case($headers, CASE_LOWER);
    }

    private function extractClient(object $provider): \GuzzleHttp\Client
    {
        $r = new \ReflectionObject($provider);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');
        $prop->setAccessible(true);
        return $prop->getValue($provider);
    }
}
