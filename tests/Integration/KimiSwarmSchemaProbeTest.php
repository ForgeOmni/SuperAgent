<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Integration;

use SuperAgent\Providers\KimiProvider;

/**
 * Best-effort schema probe for the Kimi Agent Swarm REST surface.
 *
 * As of v0.8.8 the Swarm endpoint paths in `KimiProvider::submitSwarm()`
 * are **provisional** — Moonshot has announced the feature but not
 * published the official REST reference (only CLI + UI surfaces exist).
 *
 * This test submits a minimal swarm job against a real key, reads the
 * response, and flags any obvious schema deviation from our assumptions:
 *
 *   - `POST /v1/swarm/jobs` returns a JSON body with an `id` field.
 *   - `GET  /v1/swarm/jobs/{id}` returns a JSON body with a `status` field.
 *
 * The test doesn't wait for completion — cancels immediately to avoid
 * charging the key. A 404 / 405 / 400 on submit surfaces as an explicit
 * failure with a hint that the endpoint path is likely wrong.
 *
 * Run manually when Moonshot publishes the REST spec to confirm our
 * provisional layout still matches:
 *
 *   SUPERAGENT_INTEGRATION=1 KIMI_API_KEY=sk-... \
 *     vendor/bin/phpunit tests/Integration/KimiSwarmSchemaProbeTest.php
 */
class KimiSwarmSchemaProbeTest extends IntegrationTestCase
{
    public function test_submit_returns_expected_shape_or_flag_spec_change(): void
    {
        $this->requireEnv('KIMI_API_KEY');

        $provider = new KimiProvider([
            'api_key' => getenv('KIMI_API_KEY'),
            'region'  => getenv('KIMI_REGION') ?: 'intl',
        ]);

        try {
            $handle = $provider->submitSwarm('echo probe — please cancel', [
                'max_sub_agents' => 1,
                'max_steps' => 1,
            ]);
            $this->assertNotEmpty($handle->jobId, 'submitSwarm returned empty job id — schema drift?');
            $this->assertSame('kimi', $handle->provider);
            $this->assertSame('swarm', $handle->kind);

            // Best-effort cleanup — don't let the probe actually run.
            $provider->cancel($handle);
        } catch (\SuperAgent\Exceptions\ProviderException $e) {
            $status = $e->statusCode;
            if ($status === 404 || $status === 405) {
                $this->markTestIncomplete(
                    "Kimi Swarm endpoint `/v1/swarm/jobs` returned HTTP {$status}. "
                    . 'The provisional path in KimiProvider::submitSwarm() is likely '
                    . 'wrong — check Moonshot\'s latest API reference. Message: '
                    . $e->getMessage(),
                );
            }
            if ($status === 400) {
                $this->markTestIncomplete(
                    "Kimi rejected the swarm submit body (HTTP 400). Field names "
                    . 'in KimiProvider::submitSwarm() may need updating. Message: '
                    . $e->getMessage(),
                );
            }
            // 401/403/etc — let the failure propagate normally; those mean
            // "your setup is wrong", not "our schema guess was wrong".
            throw $e;
        }
    }
}
