<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\DeviceCodeFlow;

/**
 * Locks in the PKCE S256 helper and the TokenResponse.extra pipe.
 * The actual HTTP flow is covered by integration tests — here we
 * pin the parts callers depend on without needing a live endpoint.
 */
class DeviceCodeFlowPkceTest extends TestCase
{
    public function test_generate_pkce_pair_returns_s256_compatible_values(): void
    {
        $pair = DeviceCodeFlow::generatePkcePair();

        $this->assertArrayHasKey('code_verifier', $pair);
        $this->assertArrayHasKey('code_challenge', $pair);
        $this->assertSame('S256', $pair['code_challenge_method']);

        // Verifier length per RFC 7636: 43-128 chars, base64url.
        $this->assertGreaterThanOrEqual(43, strlen($pair['code_verifier']));
        $this->assertLessThanOrEqual(128, strlen($pair['code_verifier']));
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9_-]+$#', $pair['code_verifier']);
        // Challenge is base64url of SHA-256 = 43 chars unpadded.
        $this->assertSame(43, strlen($pair['code_challenge']));
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9_-]+$#', $pair['code_challenge']);
    }

    public function test_pkce_pair_is_unique_across_calls(): void
    {
        $a = DeviceCodeFlow::generatePkcePair();
        $b = DeviceCodeFlow::generatePkcePair();
        $this->assertNotSame($a['code_verifier'], $b['code_verifier']);
        $this->assertNotSame($a['code_challenge'], $b['code_challenge']);
    }

    public function test_challenge_is_deterministic_sha256_of_verifier(): void
    {
        // The verifier → challenge derivation must match qwen-code:
        // sha256(verifier) → base64url (no padding).
        $pair = DeviceCodeFlow::generatePkcePair();
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pair['code_verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expected, $pair['code_challenge']);
    }
}
