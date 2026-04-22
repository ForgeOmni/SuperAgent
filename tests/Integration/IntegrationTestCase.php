<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that hit **real** vendor endpoints.
 *
 * These tests do not run by default — they are gated behind
 * `SUPERAGENT_INTEGRATION=1` so CI and everyday contributors don't spend
 * anyone else's API budget. Maintainers flip the flag before cutting a
 * release (see `.github/workflows/test.yml` for the release path).
 *
 * Per-provider tests additionally self-skip when their vendor's key is
 * not in the environment, so you can run just the providers you have
 * keys for without tripping unrelated failures.
 *
 * Why hand-roll instead of extending `TestCase` directly: the
 * `requireIntegrationMode()` + `requireEnv()` helpers below belong in a
 * shared base to keep individual integration test files short and
 * focused on the actual wire-contract assertions.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('SUPERAGENT_INTEGRATION') !== '1') {
            $this->markTestSkipped(
                'Integration tests gated behind SUPERAGENT_INTEGRATION=1 (they hit real vendor endpoints).',
            );
        }
    }

    /**
     * Skip the current test if any of the listed env vars is missing /
     * empty. Use this at the top of a test to declare its credential
     * prerequisites cleanly — the helper reports exactly which variable
     * was missing so contributors can see what they need to set.
     */
    protected function requireEnv(string ...$envVars): void
    {
        $missing = [];
        foreach ($envVars as $var) {
            $value = getenv($var);
            if ($value === false || $value === '') {
                $missing[] = $var;
            }
        }
        if ($missing !== []) {
            $this->markTestSkipped(
                'Missing env vars: ' . implode(', ', $missing),
            );
        }
    }
}
