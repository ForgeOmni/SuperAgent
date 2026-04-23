<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Tools\Providers\Kimi\KimiMoonshotWebSearchTool;
use SuperAgent\Tools\Providers\Kimi\KimiServerBuiltinTool;

/**
 * Locks in Moonshot's `$`-prefix server-hosted builtin tool convention:
 *
 *   1. Tools whose name starts with `$` are serialized to the special
 *      `{"type": "builtin_function", "function": {"name": "$xxx"}}`
 *      shape only when routed through `KimiProvider`.
 *   2. Non-`$` tools keep the standard `{"type": "function", ...}` shape.
 *   3. A server builtin invoked client-side (via `execute()`) fails
 *      loudly so misrouted tools don't silently no-op.
 *   4. The `builtinName()` guard rejects names that don't start with `$`.
 */
class KimiServerBuiltinToolTest extends TestCase
{
    public function test_web_search_serializes_as_builtin_function_through_kimi(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $tool = new KimiMoonshotWebSearchTool();
        $wire = $this->invokeConvertTools($p, [$tool]);

        $this->assertCount(1, $wire);
        $this->assertSame('builtin_function', $wire[0]['type']);
        $this->assertSame('$web_search', $wire[0]['function']['name']);
        // Must NOT carry description / parameters — the server owns
        // both. Sending them would at best be ignored, at worst trigger
        // a 400 for "unexpected fields".
        $this->assertArrayNotHasKey('description', $wire[0]['function']);
        $this->assertArrayNotHasKey('parameters', $wire[0]['function']);
    }

    public function test_non_dollar_tool_uses_standard_function_shape(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $tool = new class extends \SuperAgent\Tools\Tool {
            public function name(): string { return 'normal_tool'; }
            public function description(): string { return 'A regular tool'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => (object) []]; }
            public function execute(array $input): \SuperAgent\Tools\ToolResult {
                return \SuperAgent\Tools\ToolResult::success(['echo' => $input]);
            }
        };
        $wire = $this->invokeConvertTools($p, [$tool]);

        $this->assertSame('function', $wire[0]['type']);
        $this->assertSame('normal_tool', $wire[0]['function']['name']);
        $this->assertArrayHasKey('description', $wire[0]['function']);
        $this->assertArrayHasKey('parameters', $wire[0]['function']);
    }

    public function test_other_providers_do_not_understand_dollar_prefix(): void
    {
        // If a user wires $web_search into OpenAI by accident, OpenAI's
        // convertTools() will happily emit it as a normal function
        // call — at which point the OpenAI server will 400. That's the
        // correct failure mode: we want the mistake loud, not silent.
        // This test documents the behavior; we don't try to prevent
        // the mistake at our layer because some users legitimately
        // want to route builtins to non-Kimi providers via proxies.
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $wire = $this->invokeConvertTools($p, [new KimiMoonshotWebSearchTool()]);
        $this->assertSame('function', $wire[0]['type']);
        $this->assertSame('$web_search', $wire[0]['function']['name']);
    }

    public function test_execute_fails_with_informative_message(): void
    {
        $tool = new KimiMoonshotWebSearchTool();
        $result = $tool->execute(['query' => 'hi']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('server-hosted', $result->contentAsString());
    }

    public function test_builtin_name_must_start_with_dollar(): void
    {
        $bad = new class extends KimiServerBuiltinTool {
            protected function builtinName(): string { return 'no_dollar'; }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must start with `\$`/');
        $bad->name();
    }

    public function test_network_attribute_declared(): void
    {
        $tool = new KimiMoonshotWebSearchTool();
        $this->assertContains('network', $tool->attributes());
    }

    public function test_web_fetch_serializes_as_builtin_function(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $tool = new \SuperAgent\Tools\Providers\Kimi\KimiMoonshotWebFetchTool();
        $wire = $this->invokeConvertTools($p, [$tool]);

        $this->assertSame('builtin_function', $wire[0]['type']);
        $this->assertSame('$web_fetch', $wire[0]['function']['name']);
        $this->assertArrayNotHasKey('description', $wire[0]['function']);
        $this->assertArrayNotHasKey('parameters', $wire[0]['function']);
    }

    public function test_code_interpreter_serializes_as_builtin_function(): void
    {
        $p = new KimiProvider(['api_key' => 'sk-x']);
        $tool = new \SuperAgent\Tools\Providers\Kimi\KimiMoonshotCodeInterpreterTool();
        $wire = $this->invokeConvertTools($p, [$tool]);

        $this->assertSame('builtin_function', $wire[0]['type']);
        $this->assertSame('$code_interpreter', $wire[0]['function']['name']);
    }

    public function test_code_interpreter_declares_cost_and_sensitive(): void
    {
        $tool = new \SuperAgent\Tools\Providers\Kimi\KimiMoonshotCodeInterpreterTool();
        $attrs = $tool->attributes();
        $this->assertContains('network',   $attrs);
        $this->assertContains('cost',      $attrs, 'code interpreter consumes quota per run');
        $this->assertContains('sensitive', $attrs, 'code interpreter runs arbitrary code in a sandbox we do not operate');
    }

    // ── helpers ───────────────────────────────────────────────────

    private function invokeConvertTools(object $provider, array $tools): array
    {
        $r = new \ReflectionObject($provider);
        while ($r && !$r->hasMethod('convertTools')) {
            $r = $r->getParentClass();
        }
        $m = $r->getMethod('convertTools');
        $m->setAccessible(true);
        return $m->invoke($provider, $tools);
    }
}
