<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SuperAgent\Support\TraceContext;

class TraceContextTest extends TestCase
{
    public function test_fresh_matches_canonical_shape(): void
    {
        $tc = TraceContext::fresh();
        $this->assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/',
            $tc->traceparent,
        );
        $this->assertNull($tc->tracestate);
    }

    public function test_fresh_values_differ_across_calls(): void
    {
        $a = TraceContext::fresh();
        $b = TraceContext::fresh();
        $this->assertNotSame($a->traceparent, $b->traceparent, 'random trace-id + span-id guarantees uniqueness');
    }

    public function test_parse_accepts_valid_traceparent(): void
    {
        $in = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
        $tc = TraceContext::parse($in);
        $this->assertNotNull($tc);
        $this->assertSame($in, $tc->traceparent);
    }

    public function test_parse_rejects_wrong_version(): void
    {
        $this->assertNull(TraceContext::parse('01-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'));
    }

    public function test_parse_rejects_short_trace_id(): void
    {
        $this->assertNull(TraceContext::parse('00-0af76519-b7ad6b7169203331-01'));
    }

    public function test_parse_rejects_uppercase(): void
    {
        // W3C spec forbids uppercase hex.
        $this->assertNull(TraceContext::parse('00-0AF7651916CD43DD8448EB211C80319C-B7AD6B7169203331-01'));
    }

    public function test_client_metadata_projection(): void
    {
        $tc = new TraceContext(
            '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'rojo=00f067aa0ba902b7',
        );
        $meta = $tc->asClientMetadata();
        $this->assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', $meta['traceparent']);
        $this->assertSame('rojo=00f067aa0ba902b7', $meta['tracestate']);
    }

    public function test_empty_tracestate_omitted_from_metadata(): void
    {
        $tc = new TraceContext('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
        $meta = $tc->asClientMetadata();
        $this->assertArrayNotHasKey('tracestate', $meta);
    }
}
