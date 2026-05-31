<?php

declare(strict_types=1);

namespace SuperAgent\Format;

/**
 * Provider-compatibility normalizer for JSON Schema tool parameters.
 *
 * Two independent passes, each useful on its own:
 *
 *   - deref()           — inline every local `$ref` (`#/$defs/...`,
 *                         `#/definitions/...`, `#`) so providers whose
 *                         tool validators don't follow JSON pointers still
 *                         get a complete schema. MCP servers and codegen'd
 *                         schemas lean on `$ref`/`$defs` heavily; strict
 *                         backends (Moonshot Kimi, Google Gemini) reject
 *                         them outright.
 *   - fillMissingTypes()— give every nested property schema an explicit
 *                         `type`. Moonshot's validator rejects typeless
 *                         property schemas (a very common MCP shape:
 *                         enum-only or const-only properties). Inference is
 *                         conservative: enum/const value types, then
 *                         structural keywords, then a `string` fallback.
 *
 * This is a compatibility shim, not a full JSON-Schema compiler. The root
 * schema object is treated as a container and is never itself type-filled.
 *
 * Ported from Moonshot kimi-code's `packages/kosong/src/providers/
 * kimi-schema.ts`. Kept provider-agnostic so any provider can opt into the
 * passes it needs — Kimi uses both (`normalizeForKimi`), others can call
 * `deref()` alone.
 */
final class JsonSchemaNormalizer
{
    /** Child-schema positions this normalizer knows how to walk. */
    private const CHILD_SCHEMA_SLOTS = [
        ['key' => '$defs', 'kind' => 'map'],
        ['key' => 'definitions', 'kind' => 'map'],
        ['key' => 'dependencies', 'kind' => 'map'],
        ['key' => 'dependentSchemas', 'kind' => 'map'],
        ['key' => 'patternProperties', 'kind' => 'map'],
        ['key' => 'properties', 'kind' => 'map'],
        ['key' => 'additionalItems', 'kind' => 'single'],
        ['key' => 'additionalProperties', 'kind' => 'single'],
        ['key' => 'contains', 'kind' => 'single'],
        ['key' => 'contentSchema', 'kind' => 'single'],
        ['key' => 'else', 'kind' => 'single'],
        ['key' => 'if', 'kind' => 'single'],
        ['key' => 'not', 'kind' => 'single'],
        ['key' => 'propertyNames', 'kind' => 'single'],
        ['key' => 'then', 'kind' => 'single'],
        ['key' => 'unevaluatedItems', 'kind' => 'single'],
        ['key' => 'unevaluatedProperties', 'kind' => 'single'],
        ['key' => 'allOf', 'kind' => 'array'],
        ['key' => 'anyOf', 'kind' => 'array'],
        ['key' => 'oneOf', 'kind' => 'array'],
        ['key' => 'prefixItems', 'kind' => 'array'],
        ['key' => 'items', 'kind' => 'schema-or-array'],
    ];

    /** Keywords whose presence means "don't try to complete the type here". */
    private const TYPE_COMPLETION_SKIP_KEYS = [
        '$ref', 'allOf', 'anyOf', 'else', 'if', 'not', 'oneOf', 'then',
    ];

    private const OBJECT_STRUCTURE_KEYS = [
        'properties', 'patternProperties', 'additionalProperties', 'propertyNames',
        'dependentSchemas', 'dependencies', 'unevaluatedProperties',
        'dependentRequired', 'maxProperties', 'minProperties', 'required',
    ];

    private const ARRAY_STRUCTURE_KEYS = [
        'items', 'prefixItems', 'contains', 'additionalItems', 'unevaluatedItems',
        'maxContains', 'maxItems', 'minContains', 'minItems', 'uniqueItems',
    ];

    private const STRING_STRUCTURE_KEYS = [
        'contentSchema', 'contentEncoding', 'contentMediaType', 'format',
        'maxLength', 'minLength', 'pattern',
    ];

    private const NUMERIC_STRUCTURE_KEYS = [
        'exclusiveMaximum', 'exclusiveMinimum', 'maximum', 'minimum', 'multipleOf',
    ];

    /**
     * Full Moonshot Kimi normalization: deref then fill missing types.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function normalizeForKimi(array $schema): array
    {
        return self::fillMissingTypes(self::deref($schema));
    }

    /**
     * Inline all local `$ref` references. Circular refs are detected and
     * left as `$ref` (the referenced definition bucket is preserved so the
     * dangling pointer still resolves for a downstream validator).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function deref(array $schema): array
    {
        $visited = [];
        $result = self::resolveNode($schema, $schema, $visited);
        if (! is_array($result)) {
            return $schema;
        }
        if (! self::hasUnresolvedDefinitionRef($result, '$defs')) {
            unset($result['$defs']);
        }
        if (! self::hasUnresolvedDefinitionRef($result, 'definitions')) {
            unset($result['definitions']);
        }
        return $result;
    }

    /**
     * Give every nested property schema an explicit `type`. The root is a
     * container and is left untouched; only child schemas are completed.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function fillMissingTypes(array $schema): array
    {
        $clone = self::cloneValue($schema);
        if (! is_array($clone)) {
            return $schema;
        }
        self::recurseSchema($clone);
        return $clone;
    }

    // ── deref internals ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $root
     * @param array<string, bool>  $visited
     */
    private static function resolveNode(mixed $node, array $root, array &$visited): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if (self::isList($node)) {
            return array_map(static fn ($item) => self::resolveNode($item, $root, $visited), $node);
        }

        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $ref = $node['$ref'];
            if (self::isLocalPointer($ref)) {
                if (isset($visited[$ref])) {
                    // Circular reference — leave as-is to avoid recursion.
                    return $node;
                }
                $found = self::resolvePointer($root, $ref);
                if ($found['found']) {
                    $visited[$ref] = true;
                    $resolved = self::resolveNode($found['value'], $root, $visited);
                    unset($visited[$ref]);
                    // JSON Schema 2020-12: a node may carry `$ref` alongside
                    // sibling keywords (description, default, …). Merge them
                    // onto the resolved definition; sibling keys win.
                    if (is_array($resolved) && ! self::isList($resolved)) {
                        $merged = $resolved;
                        foreach ($node as $k => $v) {
                            if ($k === '$ref') {
                                continue;
                            }
                            $merged[$k] = self::resolveNode($v, $root, $visited);
                        }
                        return $merged;
                    }
                    return $resolved;
                }
            }
            // Unknown / external ref — return as-is.
            return $node;
        }

        $out = [];
        foreach ($node as $k => $v) {
            $out[$k] = self::resolveNode($v, $root, $visited);
        }
        return $out;
    }

    private static function isLocalPointer(string $ref): bool
    {
        return $ref === '#' || str_starts_with($ref, '#/');
    }

    /**
     * @param array<string, mixed> $root
     * @return array{found: bool, value?: mixed}
     */
    private static function resolvePointer(array $root, string $ref): array
    {
        if ($ref === '#') {
            return ['found' => true, 'value' => $root];
        }
        $current = $root;
        foreach (explode('/', substr($ref, 2)) as $rawPart) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $rawPart);
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
                continue;
            }
            return ['found' => false];
        }
        return ['found' => true, 'value' => $current];
    }

    private static function hasUnresolvedDefinitionRef(mixed $node, string $bucket): bool
    {
        if (is_array($node)) {
            if (self::isList($node)) {
                foreach ($node as $child) {
                    if (self::hasUnresolvedDefinitionRef($child, $bucket)) {
                        return true;
                    }
                }
                return false;
            }
            if (isset($node['$ref']) && is_string($node['$ref'])
                && str_starts_with($node['$ref'], "#/{$bucket}/")) {
                return true;
            }
            foreach ($node as $key => $value) {
                if ($key === $bucket) {
                    continue; // don't recurse into the bucket itself
                }
                if (self::hasUnresolvedDefinitionRef($value, $bucket)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ── fill-missing-types internals ─────────────────────────────────

    private static function recurseSchema(mixed &$node): void
    {
        if (! is_array($node) || self::isList($node)) {
            return;
        }
        self::visitChildSchemas($node, static function (&$child): void {
            self::normalizeProperty($child);
        });
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function visitChildSchemas(array &$node, callable $visit): void
    {
        foreach (self::CHILD_SCHEMA_SLOTS as $slot) {
            $key = $slot['key'];
            if (! array_key_exists($key, $node) || ! is_array($node[$key])) {
                continue;
            }
            switch ($slot['kind']) {
                case 'single':
                    if (! self::isList($node[$key])) {
                        $visit($node[$key]);
                    }
                    break;
                case 'array':
                    if (self::isList($node[$key])) {
                        foreach ($node[$key] as &$item) {
                            $visit($item);
                        }
                        unset($item);
                    }
                    break;
                case 'map':
                    foreach ($node[$key] as &$item) {
                        $visit($item);
                    }
                    unset($item);
                    break;
                case 'schema-or-array':
                    if (self::isList($node[$key])) {
                        foreach ($node[$key] as &$item) {
                            $visit($item);
                        }
                        unset($item);
                    } else {
                        $visit($node[$key]);
                    }
                    break;
            }
        }
    }

    private static function normalizeProperty(mixed &$node): void
    {
        if (! is_array($node) || self::isList($node)) {
            return;
        }
        if (! array_key_exists('type', $node) && ! self::hasAnyKey($node, self::TYPE_COMPLETION_SKIP_KEYS)) {
            if (isset($node['enum']) && is_array($node['enum']) && $node['enum'] !== []) {
                $node['type'] = self::inferTypeFromValues($node['enum']);
            } elseif (array_key_exists('const', $node)) {
                $node['type'] = self::inferTypeFromValues([$node['const']]);
            } else {
                $node['type'] = self::inferTypeFromStructure($node);
            }
        }
        self::recurseSchema($node);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function inferTypeFromStructure(array $schema): string
    {
        if (self::hasAnyKey($schema, self::OBJECT_STRUCTURE_KEYS)) {
            return 'object';
        }
        if (self::hasAnyKey($schema, self::ARRAY_STRUCTURE_KEYS)) {
            return 'array';
        }
        if (self::hasAnyKey($schema, self::STRING_STRUCTURE_KEYS)) {
            return 'string';
        }
        if (self::hasAnyKey($schema, self::NUMERIC_STRUCTURE_KEYS)) {
            return 'number';
        }
        return 'string';
    }

    /**
     * Infer a single JSON-Schema type from enum/const values. Mixed or
     * non-JSON values fall back to `string` rather than throwing — a
     * compatibility shim must never break the request.
     *
     * @param array<int, mixed> $values
     */
    private static function inferTypeFromValues(array $values): string
    {
        $types = [];
        foreach ($values as $value) {
            $t = self::inferValueType($value);
            if ($t === null) {
                return 'string';
            }
            $types[$t] = true;
        }
        if (isset($types['number'])) {
            unset($types['integer']);
        }
        $distinct = array_keys($types);
        return count($distinct) === 1 ? $distinct[0] : 'string';
    }

    private static function inferValueType(mixed $value): ?string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return self::isList($value) ? 'array' : 'object';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'number';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        return null;
    }

    // ── shared helpers ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>   $keys
     */
    private static function hasAnyKey(array $node, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $node)) {
                return true;
            }
        }
        return false;
    }

    private static function cloneValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::cloneValue($v);
        }
        return $out;
    }

    /**
     * A non-empty array whose keys are exactly 0..n-1. Empty arrays are
     * treated as schema objects (`{}`), not lists, so they survive a
     * round-trip as JSON objects rather than collapsing to `[]`.
     *
     * @param array<int|string, mixed> $a
     */
    private static function isList(array $a): bool
    {
        return $a !== [] && array_is_list($a);
    }
}
