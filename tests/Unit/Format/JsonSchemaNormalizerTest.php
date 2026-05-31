<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Format;

use PHPUnit\Framework\TestCase;
use SuperAgent\Format\JsonSchemaNormalizer;

/**
 * Locks in the Moonshot-compatibility normalizer ported from kimi-code's
 * kimi-schema.ts: $ref inlining + missing-type completion.
 */
class JsonSchemaNormalizerTest extends TestCase
{
    public function test_inlines_local_defs_ref(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'pet' => ['$ref' => '#/$defs/Pet'],
            ],
            '$defs' => [
                'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ],
        ];

        $out = JsonSchemaNormalizer::deref($schema);

        $this->assertArrayNotHasKey('$defs', $out, '$defs bucket removed once fully inlined');
        $this->assertSame('object', $out['properties']['pet']['type']);
        $this->assertSame('string', $out['properties']['pet']['properties']['name']['type']);
    }

    public function test_inlines_draft7_definitions_ref(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['$ref' => '#/definitions/A']],
            'definitions' => ['A' => ['type' => 'integer']],
        ];

        $out = JsonSchemaNormalizer::deref($schema);

        $this->assertArrayNotHasKey('definitions', $out);
        $this->assertSame('integer', $out['properties']['a']['type']);
    }

    public function test_ref_sibling_keywords_win(): void
    {
        // A node may carry `$ref` alongside `description`; the sibling key
        // must survive and override the resolved definition.
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['$ref' => '#/$defs/Id', 'description' => 'override'],
            ],
            '$defs' => ['Id' => ['type' => 'string', 'description' => 'base']],
        ];

        $out = JsonSchemaNormalizer::deref($schema);

        $this->assertSame('string', $out['properties']['id']['type']);
        $this->assertSame('override', $out['properties']['id']['description']);
    }

    public function test_circular_ref_does_not_recurse_forever(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['node' => ['$ref' => '#/$defs/Node']],
            '$defs' => [
                'Node' => [
                    'type' => 'object',
                    'properties' => ['child' => ['$ref' => '#/$defs/Node']],
                ],
            ],
        ];

        $out = JsonSchemaNormalizer::deref($schema);

        // Cyclic ref is preserved (not expanded) and its bucket kept.
        $this->assertArrayHasKey('$defs', $out);
        $this->assertSame('object', $out['properties']['node']['type']);
    }

    public function test_fills_type_for_enum_only_property(): void
    {
        // The canonical MCP shape that Moonshot rejects: an enum with no type.
        $schema = [
            'type' => 'object',
            'properties' => [
                'color' => ['enum' => ['red', 'green', 'blue']],
            ],
        ];

        $out = JsonSchemaNormalizer::fillMissingTypes($schema);

        $this->assertSame('string', $out['properties']['color']['type']);
    }

    public function test_fills_type_from_const(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['version' => ['const' => 2]],
        ];

        $out = JsonSchemaNormalizer::fillMissingTypes($schema);

        $this->assertSame('integer', $out['properties']['version']['type']);
    }

    public function test_fills_type_from_structure(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'nested' => ['properties' => ['x' => ['type' => 'string']]],
                'list'   => ['items' => ['type' => 'string']],
            ],
        ];

        $out = JsonSchemaNormalizer::fillMissingTypes($schema);

        $this->assertSame('object', $out['properties']['nested']['type']);
        $this->assertSame('array', $out['properties']['list']['type']);
    }

    public function test_mixed_enum_falls_back_to_string_not_throw(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['v' => ['enum' => ['a', 1, true]]],
        ];

        $out = JsonSchemaNormalizer::fillMissingTypes($schema);

        $this->assertSame('string', $out['properties']['v']['type']);
    }

    public function test_root_is_not_type_filled_but_children_are(): void
    {
        // Root deliberately left as the caller gave it; nested typeless
        // property gets completed.
        $schema = [
            'type' => 'object',
            'properties' => [
                'mode' => ['enum' => ['x', 'y']],
            ],
        ];

        $out = JsonSchemaNormalizer::normalizeForKimi($schema);

        $this->assertSame('object', $out['type']);
        $this->assertSame('string', $out['properties']['mode']['type']);
    }

    public function test_anyof_branches_get_types(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'val' => [
                    'anyOf' => [
                        ['enum' => ['on', 'off']],
                        ['minimum' => 0],
                    ],
                ],
            ],
        ];

        $out = JsonSchemaNormalizer::fillMissingTypes($schema);

        $branches = $out['properties']['val']['anyOf'];
        $this->assertSame('string', $branches[0]['type']);
        $this->assertSame('number', $branches[1]['type']);
    }
}
