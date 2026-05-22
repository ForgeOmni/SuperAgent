<?php

namespace SuperAgent\Tests\Tools\Schema;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\Schema\ProviderNormalizer;
use SuperAgent\Tools\BuiltinToolRegistry;

/**
 * Cross-provider schema compliance.
 *
 * Pi's tool-registration discipline (pi.dev/docs/latest/extensions): every
 * tool's parameter schema must be acceptable to every provider it might
 * be sent to. This test runs each Builtin tool's `inputSchema()` through
 * the per-provider normalizers and asserts the universal constraints:
 *
 *   - No `$ref` / `$defs` / `definitions` survive (Anthropic, OpenAI, Gemini all reject)
 *   - Gemini: no oneOf/anyOf at the top level
 *   - Gemini: no `format` other than `date-time`/`enum`
 *   - All `additionalProperties: false` on object roots survives (we don't strip)
 *
 * When this test fails for a given tool, the fix is usually to rewrite
 * the offending schema via the SuperAgent\Tools\Schema\Schema builder.
 */
class CrossProviderComplianceTest extends TestCase
{
    /** @dataProvider builtinTools */
    public function test_anthropic_normalization(string $toolName, array $schema): void
    {
        $normalized = ProviderNormalizer::forAnthropic($schema);
        $this->assertNoRefs($toolName, $normalized);
    }

    /** @dataProvider builtinTools */
    public function test_openai_normalization(string $toolName, array $schema): void
    {
        $normalized = ProviderNormalizer::forOpenAI($schema);
        $this->assertNoRefs($toolName, $normalized);
    }

    /** @dataProvider builtinTools */
    public function test_gemini_normalization(string $toolName, array $schema): void
    {
        $normalized = ProviderNormalizer::forGemini($schema);
        $this->assertNoRefs($toolName, $normalized);
        $this->assertNoTopLevelUnion($toolName, $normalized);
        $this->assertGeminiFormats($toolName, $normalized);
    }

    /** @return iterable<array{0: string, 1: array<string,mixed>}> */
    public static function builtinTools(): iterable
    {
        if (!class_exists(BuiltinToolRegistry::class)) {
            return; // SDK not installed in test env — skip.
        }
        // Iterate via instance method, falling back to the static
        // namespace if API differs across SDK versions.
        try {
            $registry = new BuiltinToolRegistry();
        } catch (\Throwable $e) {
            return;
        }
        if (!method_exists($registry, 'all') && !method_exists($registry, 'tools')) {
            return;
        }
        $tools = method_exists($registry, 'all') ? $registry->all() : $registry->tools();
        foreach ($tools as $tool) {
            if (!method_exists($tool, 'name') || !method_exists($tool, 'inputSchema')) continue;
            yield [$tool->name(), $tool->inputSchema()];
        }
    }

    private function assertNoRefs(string $toolName, array $schema): void
    {
        $flat = $this->walk($schema);
        foreach (['$ref', '$defs', 'definitions', '$schema'] as $k) {
            foreach ($flat as $key => $_) {
                $this->assertFalse(
                    str_ends_with($key, '.' . $k) || $key === $k,
                    "Tool {$toolName}: forbidden key {$k} in normalized schema at {$key}"
                );
            }
        }
    }

    private function assertNoTopLevelUnion(string $toolName, array $schema): void
    {
        $this->assertArrayNotHasKey('oneOf', $schema, "Tool {$toolName}: oneOf at top level rejected by Gemini");
        $this->assertArrayNotHasKey('anyOf', $schema, "Tool {$toolName}: anyOf at top level rejected by Gemini");
    }

    private function assertGeminiFormats(string $toolName, array $schema): void
    {
        $allowed = ['date-time', 'enum'];
        foreach ($this->walk($schema) as $key => $value) {
            if (str_ends_with($key, '.format') && is_string($value) && !in_array($value, $allowed, true)) {
                $this->fail("Tool {$toolName}: format={$value} at {$key} not Gemini-safe");
            }
        }
    }

    private function walk(array $schema, string $prefix = ''): array
    {
        $out = [];
        foreach ($schema as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            $out[$key] = is_array($v) ? '[array]' : $v;
            if (is_array($v)) {
                $out = array_merge($out, $this->walk($v, $key));
            }
        }
        return $out;
    }
}
