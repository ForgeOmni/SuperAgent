<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\ModelTierMap;

class ModelTierMapTest extends TestCase
{
    public function test_defaults_are_cross_vendor(): void
    {
        $map = new ModelTierMap();
        $providers = array_unique(array_column($map->toArray(), 'provider'));

        // Whole point of the default map is to mix vendors across bands.
        $this->assertGreaterThanOrEqual(2, count($providers));
    }

    public function test_resolve_returns_provider_and_model(): void
    {
        $resolved = (new ModelTierMap())->resolve(DifficultyClass::HARD);

        $this->assertArrayHasKey('provider', $resolved);
        $this->assertArrayHasKey('model', $resolved);
        $this->assertNotSame('', $resolved['provider']);
        $this->assertNotSame('', $resolved['model']);
    }

    public function test_with_returns_a_new_immutable_copy(): void
    {
        $original = new ModelTierMap();
        $modified = $original->with(DifficultyClass::EASY, 'kimi', 'k2.6-flash');

        $this->assertSame('kimi',      $modified->resolve(DifficultyClass::EASY)['provider']);
        $this->assertNotSame('kimi',   $original->resolve(DifficultyClass::EASY)['provider']);
    }

    public function test_partial_constructor_override_keeps_defaults_for_other_bands(): void
    {
        $map = new ModelTierMap([
            DifficultyClass::EXPERT->value => ['provider' => 'openai', 'model' => 'gpt-5-pro'],
        ]);

        $this->assertSame('openai', $map->resolve(DifficultyClass::EXPERT)['provider']);
        // Other bands fall back to defaults
        $this->assertSame(
            ModelTierMap::defaults()[DifficultyClass::TRIVIAL->value]['provider'],
            $map->resolve(DifficultyClass::TRIVIAL)['provider'],
        );
    }
}
