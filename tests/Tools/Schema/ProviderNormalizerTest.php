<?php

namespace SuperAgent\Tests\Tools\Schema;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\Schema\ProviderNormalizer;
use SuperAgent\Tools\Schema\Schema;

class ProviderNormalizerTest extends TestCase
{
    public function test_string_enum_passes_through_for_anthropic(): void
    {
        $schema = Schema::stringEnum(['pending', 'in_progress', 'completed'], 'Status');
        $normalized = ProviderNormalizer::forAnthropic($schema);

        $this->assertSame('string', $normalized['type']);
        $this->assertSame(['pending', 'in_progress', 'completed'], $normalized['enum']);
        $this->assertArrayNotHasKey('x-superagent-kind', $normalized);
    }

    public function test_one_of_string_literals_flattens_to_enum_for_gemini(): void
    {
        $schema = Schema::oneOf([
            ['type' => 'string', 'const' => 'low'],
            ['type' => 'string', 'const' => 'medium'],
            ['type' => 'string', 'const' => 'high'],
        ]);
        $normalized = ProviderNormalizer::forGemini($schema);

        $this->assertSame('string', $normalized['type']);
        $this->assertSame(['low', 'medium', 'high'], $normalized['enum']);
        $this->assertArrayNotHasKey('oneOf', $normalized);
    }

    public function test_refs_are_dropped(): void
    {
        $schema = [
            'type' => 'object',
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'properties' => [
                'foo' => ['$ref' => '#/definitions/Foo'],
            ],
            'definitions' => ['Foo' => ['type' => 'string']],
        ];
        $normalized = ProviderNormalizer::forGemini($schema);

        $this->assertArrayNotHasKey('$schema', $normalized);
        $this->assertArrayNotHasKey('definitions', $normalized);
        $this->assertArrayNotHasKey('$ref', $normalized['properties']['foo']);
    }

    public function test_unsupported_string_formats_dropped_for_gemini(): void
    {
        $schema = ['type' => 'string', 'format' => 'uri'];
        $normalized = ProviderNormalizer::forGemini($schema);
        $this->assertArrayNotHasKey('format', $normalized);

        $kept = ['type' => 'string', 'format' => 'date-time'];
        $this->assertSame('date-time', ProviderNormalizer::forGemini($kept)['format']);
    }
}
