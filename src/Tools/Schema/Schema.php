<?php

namespace SuperAgent\Tools\Schema;

/**
 * JSON-Schema builder for tool input declarations.
 *
 * Borrowed from the pi project's `StringEnum` Typebox helper: encode the
 * intent (this is a string-enum, this is a uniform-typed array, etc.) so the
 * downstream provider normalizer can rewrite incompatible shapes per
 * provider (Gemini rejects `$ref`/`$defs`, some Vertex variants reject
 * `oneOf` at the top level of `function_declarations.parameters`, etc.).
 *
 * Output is plain associative arrays — caller assigns to inputSchema().
 */
final class Schema
{
    public static function object(array $properties = [], array $required = [], ?string $description = null): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];
        if (!empty($required)) {
            $schema['required'] = array_values($required);
        }
        if ($description !== null) {
            $schema['description'] = $description;
        }
        return $schema;
    }

    public static function string(?string $description = null): array
    {
        return self::with(['type' => 'string'], $description);
    }

    public static function stringEnum(array $values, ?string $description = null): array
    {
        return self::with([
            'type' => 'string',
            'enum' => array_values($values),
            'x-superagent-kind' => 'string-enum',
        ], $description);
    }

    public static function integer(?string $description = null, ?int $min = null, ?int $max = null): array
    {
        $schema = ['type' => 'integer'];
        if ($min !== null) $schema['minimum'] = $min;
        if ($max !== null) $schema['maximum'] = $max;
        return self::with($schema, $description);
    }

    public static function number(?string $description = null): array
    {
        return self::with(['type' => 'number'], $description);
    }

    public static function boolean(?string $description = null): array
    {
        return self::with(['type' => 'boolean'], $description);
    }

    public static function array(array $items, ?string $description = null, ?int $minItems = null, ?int $maxItems = null): array
    {
        $schema = ['type' => 'array', 'items' => $items];
        if ($minItems !== null) $schema['minItems'] = $minItems;
        if ($maxItems !== null) $schema['maxItems'] = $maxItems;
        return self::with($schema, $description);
    }

    /**
     * Tagged union — normalizer may flatten to `enum` if all branches are
     * string-literals, or strip the discriminator entirely for Gemini.
     */
    public static function oneOf(array $branches, ?string $description = null): array
    {
        return self::with([
            'oneOf' => array_values($branches),
            'x-superagent-kind' => 'one-of',
        ], $description);
    }

    public static function anyOf(array $branches, ?string $description = null): array
    {
        return self::with([
            'anyOf' => array_values($branches),
            'x-superagent-kind' => 'any-of',
        ], $description);
    }

    private static function with(array $schema, ?string $description): array
    {
        if ($description !== null && $description !== '') {
            $schema['description'] = $description;
        }
        return $schema;
    }
}
