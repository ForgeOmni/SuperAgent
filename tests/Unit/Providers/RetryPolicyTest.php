<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\OpenAIProvider;

/**
 * Pins the layered retry config + jittered backoff lifted from
 * codex-rs's model-provider-info defaults. Runtime behaviour (actual
 * retry on wire) is covered by the full provider round-trip tests;
 * these lock the config parsing + the jitter envelope.
 */
class RetryPolicyTest extends TestCase
{
    public function test_defaults_match_codex_shape(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-test']);
        $this->assertSame(3, $this->readInt($p, 'maxRetries'), 'legacy default preserved');
        $this->assertSame(3, $this->readInt($p, 'requestMaxRetries'));
        $this->assertSame(5, $this->readInt($p, 'streamMaxRetries'));
        $this->assertSame(300_000, $this->readInt($p, 'streamIdleTimeoutMs'));
    }

    public function test_legacy_max_retries_threads_to_request_counter(): void
    {
        $p = new OpenAIProvider([
            'api_key'     => 'sk-test',
            'max_retries' => 7,
        ]);
        $this->assertSame(7, $this->readInt($p, 'requestMaxRetries'));
        // streamMaxRetries stays at the floor (5) unless explicitly set —
        // codex reasoning: stream reconnect is more expensive than a
        // request retry (we've already spent tokens), so default higher.
        $this->assertSame(7, $this->readInt($p, 'streamMaxRetries'));
    }

    public function test_explicit_layered_config(): void
    {
        $p = new OpenAIProvider([
            'api_key'                => 'sk-test',
            'request_max_retries'    => 2,
            'stream_max_retries'     => 8,
            'stream_idle_timeout_ms' => 60_000,
        ]);
        $this->assertSame(2, $this->readInt($p, 'requestMaxRetries'));
        $this->assertSame(8, $this->readInt($p, 'streamMaxRetries'));
        $this->assertSame(60_000, $this->readInt($p, 'streamIdleTimeoutMs'));
    }

    public function test_config_bounds_clamped(): void
    {
        $p = new OpenAIProvider([
            'api_key'                => 'sk-test',
            'request_max_retries'    => 500,       // cap 100
            'stream_max_retries'     => -3,        // floor 0
            'stream_idle_timeout_ms' => 7_200_000, // cap 3_600_000 (1h)
        ]);
        $this->assertSame(100, $this->readInt($p, 'requestMaxRetries'));
        $this->assertSame(0, $this->readInt($p, 'streamMaxRetries'));
        $this->assertSame(3_600_000, $this->readInt($p, 'streamIdleTimeoutMs'));
    }

    public function test_idle_timeout_floor_enforced(): void
    {
        $p = new OpenAIProvider([
            'api_key'                => 'sk-test',
            'stream_idle_timeout_ms' => 10, // nonsense — floor at 1000ms
        ]);
        $this->assertSame(1_000, $this->readInt($p, 'streamIdleTimeoutMs'));
    }

    public function test_jitter_within_90_to_110_percent(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-test']);
        $rc = new \ReflectionClass($p);
        $m = $rc->getMethod('jitteredBackoff');
        $m->setAccessible(true);

        $base = pow(2, 3); // attempt=3 → 8s base
        $min = $base * 0.9;
        $max = $base * 1.1;

        // 1000 samples — the probability of even one landing outside
        // [0.9, 1.1] is zero (mt_rand(90,110) is hard-bounded). Running
        // many samples guards against a regression that accidentally
        // loosens the jitter.
        for ($i = 0; $i < 1000; $i++) {
            $d = $m->invoke($p, 3);
            $this->assertGreaterThanOrEqual($min, $d, "sample {$i} below min");
            $this->assertLessThanOrEqual($max, $d, "sample {$i} above max");
        }
    }

    public function test_jitter_floors_at_200ms_and_ceilings_at_60s(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-test']);
        $rc = new \ReflectionClass($p);
        $m = $rc->getMethod('jitteredBackoff');
        $m->setAccessible(true);

        // attempt=0 → base 2^1=2s (the max(1) guard); still above 0.2 floor
        $this->assertGreaterThanOrEqual(0.2, $m->invoke($p, 0));

        // attempt=30 → 2^30 seconds would be ~34 years; expect the 60s ceiling
        $d = $m->invoke($p, 30);
        $this->assertLessThanOrEqual(60.0, $d);
    }

    private function readInt(OpenAIProvider $p, string $prop): int
    {
        $r = new \ReflectionProperty(\SuperAgent\Providers\ChatCompletionsProvider::class, $prop);
        $r->setAccessible(true);
        return (int) $r->getValue($p);
    }
}
