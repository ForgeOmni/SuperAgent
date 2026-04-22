<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ProviderRegistry;

/**
 * Structural tests for `ProviderRegistry::healthCheck()`. Real network
 * hits live in the Integration suite — these tests only verify the
 * unhappy paths that don't require a live endpoint (unknown provider,
 * missing env key, etc.).
 */
class HealthCheckTest extends TestCase
{
    public function test_unknown_provider_reports_not_ok(): void
    {
        $result = ProviderRegistry::healthCheck('does-not-exist');
        $this->assertFalse($result['ok']);
        $this->assertSame('unknown provider', $result['reason']);
    }

    public function test_missing_api_key_reported_as_config_issue(): void
    {
        // Save and clear the relevant envs.
        $vars = ['KIMI_API_KEY', 'MOONSHOT_API_KEY'];
        $saved = [];
        foreach ($vars as $v) {
            $saved[$v] = getenv($v) ?: null;
            putenv($v);
            unset($_ENV[$v]);
        }
        try {
            $result = ProviderRegistry::healthCheck('kimi');
            $this->assertFalse($result['ok']);
            $this->assertStringContainsStringIgnoringCase('api key', $result['reason']);
        } finally {
            foreach ($saved as $var => $val) {
                if ($val !== null) {
                    putenv("{$var}={$val}");
                    $_ENV[$var] = $val;
                }
            }
        }
    }

    public function test_returns_expected_keys_in_result(): void
    {
        $result = ProviderRegistry::healthCheck('unknown-xyz');
        $this->assertArrayHasKey('provider', $result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('reason', $result);
    }
}
