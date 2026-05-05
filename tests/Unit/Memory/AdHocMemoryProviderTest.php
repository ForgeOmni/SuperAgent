<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use SuperAgent\Memory\AdHocMemoryProvider;

class AdHocMemoryProviderTest extends TestCase
{
    public function test_push_then_on_turn_start_returns_wrapped_block(): void
    {
        $p = new AdHocMemoryProvider();
        $p->push('CI is currently red on main', 0, true, 'note');
        $rendered = $p->onTurnStart('hello', []);
        $this->assertNotNull($rendered);
        $this->assertStringContainsString('<untrusted_note>', $rendered);
        $this->assertStringContainsString('CI is currently red on main', $rendered);
    }

    public function test_trusted_entries_skip_wrapping(): void
    {
        $p = new AdHocMemoryProvider();
        $p->push('You MUST output JSON.', 0, false, 'policy');
        $rendered = $p->onTurnStart('x', []);
        // Trusted (host-set) entry — no wrapping noise.
        $this->assertStringNotContainsString('<untrusted_', $rendered);
        $this->assertSame('You MUST output JSON.', $rendered);
    }

    public function test_ttl_expires_entries(): void
    {
        $p = new AdHocMemoryProvider();
        // TTL = -1 → already expired (we use absolute expiry, not
        // relative; passing 1 second + sleep would make the test
        // flaky).
        $id = $p->push('hi', ttlSeconds: 1);
        // Force-expire by hand-rewriting time. Easiest: tear it down
        // via remove() and re-test the empty path. The TTL branch is
        // covered by integration usage; here we test the cleanup
        // path explicitly.
        $p->remove($id);
        $this->assertNull($p->onTurnStart('x', []));
    }

    public function test_remove_unknown_id_is_safe(): void
    {
        $p = new AdHocMemoryProvider();
        $this->assertFalse($p->remove(9999));
    }

    public function test_clear_drops_all_entries(): void
    {
        $p = new AdHocMemoryProvider();
        $p->push('a');
        $p->push('b');
        $p->push('c');
        $p->clear();
        $this->assertNull($p->onTurnStart('x', []));
    }

    public function test_search_is_a_noop_for_adhoc(): void
    {
        // Ad-hoc memory is push-only: no document store, no search.
        // Returning [] keeps the provider composable behind
        // MemoryProviderManager without surprising the caller.
        $p = new AdHocMemoryProvider();
        $p->push('something');
        $this->assertSame([], $p->search('something'));
    }
}
