<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperAgent\Exceptions\StreamContentError;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\StreamingHandler;

/**
 * Locks in the Phase-6 SSE parser hardening on
 * `ChatCompletionsProvider::parseSSEStream()`:
 *
 *   1. Tool-call assembly by `index` — a single tool call split
 *      across N chunks produces ONE ContentBlock with the
 *      concatenated arguments, not N fragmented entries.
 *   2. `finish_reason: "error_finish"` (DashScope compat-mode TPM
 *      throttle signal) raises StreamContentError so the retry
 *      loop picks it up instead of returning truncated content.
 *   3. Empty-string content chunks don't inflate the message.
 *   4. Malformed / truncated tool arguments get a one-shot repair
 *      before falling back to an empty arg dict.
 *
 * Exercises the parser via any ChatCompletionsProvider subclass
 * (OpenAI here) since the parser lives on the shared base.
 */
class ChatCompletionsSseParserTest extends TestCase
{
    public function test_single_tool_call_split_across_many_chunks_assembles_into_one(): void
    {
        // Mimic OpenAI's real streaming shape: id + name arrive in
        // the FIRST chunk, arguments fragments in later ones (all
        // with index=0).
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['content' => 'Thinking...']]),
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'toolu_1', 'type' => 'function',
                 'function' => ['name' => 'Read', 'arguments' => '{"file_path":"']],
            ]]]),
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '/tmp/x.md']]],
            ]]),
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '"}']],
            ]]]),
            $this->chunk(['finish_reason' => 'tool_calls']),
            'data: [DONE]',
        ]);

        $msg = $this->runParser($sse);

        // One tool_use block, not three.
        $toolBlocks = array_filter($msg->content, fn ($b) => $b->type === 'tool_use');
        $this->assertCount(1, $toolBlocks);
        $block = reset($toolBlocks);
        $this->assertSame('toolu_1', $block->toolUseId);
        $this->assertSame('Read', $block->toolName);
        $this->assertSame(['file_path' => '/tmp/x.md'], $block->toolInput);
    }

    public function test_two_tool_calls_at_different_indexes_stay_separate(): void
    {
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 't0', 'function' => ['name' => 'Read', 'arguments' => '{"path":"/a"}']],
                ['index' => 1, 'id' => 't1', 'function' => ['name' => 'Grep', 'arguments' => '{"pat":"foo"}']],
            ]]]),
            $this->chunk(['finish_reason' => 'tool_calls']),
            'data: [DONE]',
        ]);

        $msg = $this->runParser($sse);
        $tools = array_values(array_filter($msg->content, fn ($b) => $b->type === 'tool_use'));
        $this->assertCount(2, $tools);
        $this->assertSame('t0', $tools[0]->toolUseId);
        $this->assertSame('Read', $tools[0]->toolName);
        $this->assertSame(['path' => '/a'], $tools[0]->toolInput);
        $this->assertSame('t1', $tools[1]->toolUseId);
        $this->assertSame('Grep', $tools[1]->toolName);
        $this->assertSame(['pat' => 'foo'], $tools[1]->toolInput);
    }

    public function test_id_and_name_from_first_chunk_survive_later_empty_chunks(): void
    {
        // Some servers emit later chunks with id: "" / name: "" —
        // they must NOT clobber the values we captured from chunk 1.
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'real-id', 'function' => ['name' => 'Read', 'arguments' => '{"k":']],
            ]]]),
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => '', 'function' => ['name' => '', 'arguments' => '"v"}']],
            ]]]),
            $this->chunk(['finish_reason' => 'tool_calls']),
            'data: [DONE]',
        ]);

        $msg = $this->runParser($sse);
        $tools = array_values(array_filter($msg->content, fn ($b) => $b->type === 'tool_use'));
        $this->assertSame('real-id', $tools[0]->toolUseId);
        $this->assertSame('Read', $tools[0]->toolName);
        $this->assertSame(['k' => 'v'], $tools[0]->toolInput);
    }

    public function test_empty_content_chunks_do_not_inflate_message(): void
    {
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['content' => '']]),
            $this->chunk(['delta' => ['content' => 'hello']]),
            $this->chunk(['delta' => ['content' => '']]),
            $this->chunk(['delta' => ['content' => ' world']]),
            $this->chunk(['finish_reason' => 'stop']),
            'data: [DONE]',
        ]);

        $msg = $this->runParser($sse);
        $textBlocks = array_values(array_filter($msg->content, fn ($b) => $b->type === 'text'));
        $this->assertSame('hello world', $textBlocks[0]->text);
    }

    public function test_error_finish_raises_StreamContentError_with_partial_content(): void
    {
        // DashScope compat-mode throttle signal. The error text lives
        // in delta.content of the terminating chunk.
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['content' => 'Partial response before error']]),
            $this->chunk([
                'delta' => ['content' => 'TPM limit exceeded'],
                'finish_reason' => 'error_finish',
            ]),
            'data: [DONE]',
        ]);

        try {
            $this->runParser($sse);
            $this->fail('Expected StreamContentError');
        } catch (StreamContentError $e) {
            $this->assertStringContainsString('TPM limit exceeded', $e->errorMessage);
            $this->assertSame('Partial response before error', $e->partialContent);
            $this->assertTrue($e->retryable, 'error_finish should be retryable');
        }
    }

    public function test_truncated_tool_arguments_get_cheap_repair(): void
    {
        // Max-tokens hit mid-tool-call → unclosed object. The parser
        // applies one repair attempt (append missing `}`) before
        // falling back to an empty arg dict.
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'trunc', 'function' => ['name' => 'Edit', 'arguments' => '{"file":"/a"']],
            ]]]),
            $this->chunk(['finish_reason' => 'length']),
            'data: [DONE]',
        ]);
        $msg = $this->runParser($sse);
        $tools = array_values(array_filter($msg->content, fn ($b) => $b->type === 'tool_use'));
        $this->assertSame(['file' => '/a'], $tools[0]->toolInput);
    }

    public function test_unrepairable_junk_becomes_empty_dict(): void
    {
        // Garbage arguments → [], not an exception. The agent loop
        // sees "tool with no args" which is a clearer failure signal
        // than an unhandled JSON parse error.
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'junk', 'function' => ['name' => 'X', 'arguments' => '!!not-json!!']],
            ]]]),
            $this->chunk(['finish_reason' => 'tool_calls']),
            'data: [DONE]',
        ]);
        $msg = $this->runParser($sse);
        $tools = array_values(array_filter($msg->content, fn ($b) => $b->type === 'tool_use'));
        $this->assertSame([], $tools[0]->toolInput);
    }

    public function test_on_tool_use_fires_once_per_tool_not_once_per_chunk(): void
    {
        // Guardrail for the pre-Phase-6 bug: the old parser called
        // `$handler->onToolUse()` for EVERY delta chunk carrying a
        // tool_calls field, producing a cascade of mostly-empty
        // events. We emit exactly once per assembled tool.
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 't0', 'function' => ['name' => 'Read', 'arguments' => '{"f"']],
            ]]]),
            $this->chunk(['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => ':"/a"}']],
            ]]]),
            $this->chunk(['finish_reason' => 'tool_calls']),
            'data: [DONE]',
        ]);
        $calls = [];
        $handler = new StreamingHandler(
            onToolUse: function (ContentBlock $block) use (&$calls) {
                $calls[] = [
                    'id' => $block->toolUseId,
                    'name' => $block->toolName,
                    'input' => $block->toolInput,
                ];
            },
        );
        $this->runParser($sse, $handler);

        $this->assertCount(1, $calls, 'onToolUse fires once per tool, not once per chunk');
        $this->assertSame('t0', $calls[0]['id']);
        $this->assertSame('Read', $calls[0]['name']);
        $this->assertSame(['f' => '/a'], $calls[0]['input']);
    }

    public function test_on_text_fires_per_chunk_with_full_accumulated_text(): void
    {
        $events = [];
        $handler = new StreamingHandler(
            onText: function (string $delta, string $full) use (&$events) {
                $events[] = ['delta' => $delta, 'full' => $full];
            },
        );
        $sse = $this->sseOf([
            $this->chunk(['delta' => ['content' => 'hello']]),
            $this->chunk(['delta' => ['content' => ' ']]),
            $this->chunk(['delta' => ['content' => 'world']]),
            $this->chunk(['finish_reason' => 'stop']),
            'data: [DONE]',
        ]);
        $this->runParser($sse, $handler);

        $this->assertCount(3, $events);
        $this->assertSame(['delta' => 'hello', 'full' => 'hello'], $events[0]);
        $this->assertSame(['delta' => ' ', 'full' => 'hello '], $events[1]);
        $this->assertSame(['delta' => 'world', 'full' => 'hello world'], $events[2]);
    }

    // ── helpers ───────────────────────────────────────────────────

    /**
     * Build an `data: {...}` SSE frame (no leading/trailing newlines
     * — the parser splits on `\n`).
     *
     * @param array<string, mixed> $choice Merged into `{choices:[0]: $choice}`.
     */
    private function chunk(array $choice): string
    {
        return 'data: ' . json_encode(['choices' => [$choice]]);
    }

    /**
     * @param array<int, string> $frames
     */
    private function sseOf(array $frames): string
    {
        return implode("\n", $frames) . "\n";
    }

    private function runParser(string $sseText, ?StreamingHandler $handler = null): AssistantMessage
    {
        $stream = Utils::streamFor($sseText);
        $provider = new OpenAIProvider(['api_key' => 'sk-test']);

        $rc = new ReflectionClass($provider);
        while ($rc && ! $rc->hasMethod('parseSSEStream')) {
            $rc = $rc->getParentClass();
        }
        $m = $rc->getMethod('parseSSEStream');
        $m->setAccessible(true);
        $gen = $m->invoke($provider, $stream, $handler);

        // parseSSEStream yields exactly one AssistantMessage at the end.
        $messages = iterator_to_array($gen);
        $this->assertNotEmpty($messages);
        return $messages[array_key_first($messages)];
    }
}
