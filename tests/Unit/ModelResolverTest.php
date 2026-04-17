<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ModelResolver;

class ModelResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelResolver::reset();
    }

    public function test_resolves_opus_case_insensitive(): void
    {
        $this->assertStringContains('opus', ModelResolver::resolve('OPUS'));
        $this->assertStringContains('opus', ModelResolver::resolve('opus'));
        $this->assertStringContains('opus', ModelResolver::resolve('Opus'));
    }

    public function test_resolves_sonnet_alias(): void
    {
        $resolved = ModelResolver::resolve('sonnet');
        $this->assertStringContains('sonnet', $resolved);
        // Should be the latest sonnet
        $this->assertStringContains('20250514', $resolved);
    }

    public function test_resolves_haiku_alias(): void
    {
        $resolved = ModelResolver::resolve('haiku');
        $this->assertStringContains('haiku', $resolved);
    }

    public function test_resolves_claude_opus_alias(): void
    {
        $resolved = ModelResolver::resolve('claude-opus');
        $this->assertStringContains('opus', $resolved);
        $this->assertStringContains('20250514', $resolved);
    }

    public function test_passes_through_full_model_id(): void
    {
        $fullId = 'claude-opus-4-20250514';
        // Full model IDs may also match, but should resolve correctly
        $resolved = ModelResolver::resolve($fullId);
        $this->assertStringContains('opus', $resolved);
    }

    public function test_passes_through_unknown_model(): void
    {
        $this->assertSame('some-unknown-model-xyz', ModelResolver::resolve('some-unknown-model-xyz'));
    }

    public function test_custom_alias_takes_precedence(): void
    {
        ModelResolver::registerAliases(['opus' => 'my-custom-opus']);
        $this->assertSame('my-custom-opus', ModelResolver::resolve('OPUS'));
    }

    public function test_latest_in_family_returns_newest(): void
    {
        $latest = ModelResolver::latestInFamily('opus');
        $this->assertSame('claude-opus-4-20250514', $latest);
    }

    public function test_register_new_model_updates_latest(): void
    {
        // Register a newer opus model
        ModelResolver::register('claude-opus-4-20260101', 'opus', [], 20260101);
        $latest = ModelResolver::latestInFamily('opus');
        $this->assertSame('claude-opus-4-20260101', $latest);

        // Resolve alias should now return the new one
        $this->assertSame('claude-opus-4-20260101', ModelResolver::resolve('opus'));
    }

    public function test_family_models_sorted_newest_first(): void
    {
        $models = ModelResolver::familyModels('opus');
        $this->assertNotEmpty($models);
        // First should be newest
        $this->assertSame('claude-opus-4-20250514', $models[0]);
    }

    public function test_is_alias_returns_true_for_known(): void
    {
        $this->assertTrue(ModelResolver::isAlias('opus'));
        $this->assertTrue(ModelResolver::isAlias('SONNET'));
        $this->assertTrue(ModelResolver::isAlias('claude-haiku'));
    }

    public function test_is_alias_returns_false_for_unknown(): void
    {
        $this->assertFalse(ModelResolver::isAlias('totally-random-model'));
    }

    public function test_empty_string_passthrough(): void
    {
        $this->assertSame('', ModelResolver::resolve(''));
    }

    public function test_openai_aliases(): void
    {
        $this->assertSame('gpt-4o', ModelResolver::resolve('gpt4'));
        $this->assertSame('gpt-4o', ModelResolver::resolve('gpt4o'));
        $this->assertStringContains('gpt-3.5', ModelResolver::resolve('gpt35'));
    }

    public function test_all_families_lists_latest(): void
    {
        $families = ModelResolver::allFamilies();
        $this->assertArrayHasKey('opus', $families);
        $this->assertArrayHasKey('sonnet', $families);
        $this->assertArrayHasKey('haiku', $families);
    }

    /**
     * Helper: assert that $haystack contains $needle.
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'",
        );
    }
}
