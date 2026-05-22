<?php

namespace SuperAgent\Tests\Conversation\Encoder;

use PHPUnit\Framework\TestCase;
use SuperAgent\Conversation\Encoder\VertexEncoder;

class VertexEncoderTest extends TestCase
{
    public function test_dialect_for_model_detects_anthropic_paths(): void
    {
        $this->assertSame('anthropic', VertexEncoder::dialectForModel('publishers/anthropic/models/claude-opus-4-7'));
        $this->assertSame('anthropic', VertexEncoder::dialectForModel('claude-opus-4-7'));
        $this->assertSame('gemini',    VertexEncoder::dialectForModel('publishers/google/models/gemini-2.5-pro'));
        $this->assertSame('gemini',    VertexEncoder::dialectForModel('gemini-2.5-pro'));
        // Unknown defaults to gemini (Google's flagship on Vertex)
        $this->assertSame('gemini',    VertexEncoder::dialectForModel('mistral-7b'));
    }

    public function test_encode_routes_to_gemini_by_default(): void
    {
        $enc = new VertexEncoder(VertexEncoder::DIALECT_GEMINI);
        // Empty messages → empty output (smoke test — delegating encoders
        // are covered by their own tests).
        $this->assertSame([], $enc->encode([]));
    }

    public function test_encode_routes_to_anthropic_when_selected(): void
    {
        $enc = new VertexEncoder(VertexEncoder::DIALECT_ANTHROPIC);
        $this->assertSame([], $enc->encode([]));
    }
}
