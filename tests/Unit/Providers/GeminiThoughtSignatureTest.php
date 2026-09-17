<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Checkpoint\MessageSerializer as CheckpointSerializer;
use SuperAgent\Messages\MessageSerializer;
use SuperAgent\Conversation\Transcoder;
use SuperAgent\Conversation\WireFamily;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Providers\GeminiProvider;

/**
 * Thought signatures, in and out again.
 *
 * Gemini 3 signs the part a function call arrives on and refuses the call
 * when it is replayed without that signature — which is every tool round
 * after the first. So the signature has to survive parsing, storage and
 * encoding, untouched.
 */
class GeminiThoughtSignatureTest extends TestCase
{
    public function test_a_signed_function_call_keeps_its_signature(): void
    {
        $message = $this->parseStream([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'get_order', 'args' => ['order_id' => 7]],
                    'thoughtSignature' => 'sig-abc',
                ]]],
                'finishReason' => 'STOP',
            ]],
        ]);

        $block = $message->content[0];
        $this->assertSame('tool_use', $block->type);
        $this->assertSame('sig-abc', $block->meta('gemini_thought_signature'));
    }

    public function test_a_signed_text_part_keeps_its_signature(): void
    {
        $message = $this->parseStream([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'text' => 'looking that up',
                    'thoughtSignature' => 'sig-text',
                ]]],
                'finishReason' => 'STOP',
            ]],
        ]);

        $this->assertSame('sig-text', $message->content[0]->meta('gemini_thought_signature'));
    }

    public function test_an_unsigned_call_carries_no_metadata(): void
    {
        $message = $this->parseStream([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'get_order', 'args' => []],
                ]]],
                'finishReason' => 'STOP',
            ]],
        ]);

        $this->assertNull($message->content[0]->meta);
    }

    public function test_the_encoder_sends_the_signature_back_on_the_same_part(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::toolUse('gemini_1', 'get_order', ['order_id' => 7], ['gemini_thought_signature' => 'sig-abc']),
        ];

        $wire = (new Transcoder())->encode([$assistant], WireFamily::Gemini);

        $this->assertSame('sig-abc', $wire[0]['parts'][0]['thoughtSignature']);
        $this->assertSame('get_order', $wire[0]['parts'][0]['functionCall']['name']);
    }

    public function test_an_unsigned_call_is_encoded_without_the_key(): void
    {
        // Not an empty string: Gemini reads a present-but-empty signature as a
        // signature, and rejects it.
        $assistant = new AssistantMessage();
        $assistant->content = [ContentBlock::toolUse('gemini_1', 'get_order', [])];

        $wire = (new Transcoder())->encode([$assistant], WireFamily::Gemini);

        $this->assertArrayNotHasKey('thoughtSignature', $wire[0]['parts'][0]);
    }

    public function test_the_signature_survives_a_checkpoint(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::toolUse('gemini_1', 'get_order', ['order_id' => 7], ['gemini_thought_signature' => 'sig-abc']),
        ];

        $restored = CheckpointSerializer::deserialize(CheckpointSerializer::serialize($assistant));

        $this->assertSame('sig-abc', $restored->content[0]->meta('gemini_thought_signature'));
    }

    public function test_the_signature_survives_being_saved_and_loaded(): void
    {
        // A resumed conversation replays its tool rounds; a signature lost in
        // storage fails on the next request instead of this one.
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::toolUse('gemini_1', 'get_order', ['order_id' => 7], ['gemini_thought_signature' => 'sig-abc']),
        ];

        $restored = MessageSerializer::decode(MessageSerializer::encode($assistant));

        $this->assertSame('sig-abc', $restored->content[0]->meta('gemini_thought_signature'));
    }

    private function parseStream(array $event): AssistantMessage
    {
        $provider = new GeminiProvider(['api_key' => 'AIzaSyTEST']);
        $stream = \GuzzleHttp\Psr7\Utils::streamFor('data: ' . json_encode($event) . "\n\n");
        $method = new \ReflectionMethod($provider, 'parseSSEStream');

        $last = null;
        foreach ($method->invoke($provider, $stream, null) as $message) {
            $last = $message;
        }

        return $last;
    }
}
