<?php

namespace SuperAgent\Tools\Schema;

/**
 * Per-provider JSON-Schema normalizer.
 *
 * Each provider's "function-calling" / "tool-use" surface accepts a slightly
 * different subset of JSON Schema. Schemas produced via Schema::* carry
 * intent markers (`x-superagent-kind`) that this class uses to rewrite the
 * tree into a form the target provider accepts.
 *
 * - Anthropic:  Native JSON Schema; minimal rewriting needed.
 * - OpenAI:     Strict mode is fine with oneOf/anyOf but rejects $ref/$defs.
 * - Gemini:     No $ref/$defs/oneOf/anyOf at top level of parameters.
 *               additionalProperties false is enforced on objects.
 *               format must be omitted on strings other than date-time/enum.
 */
final class ProviderNormalizer
{
    public static function forAnthropic(array $schema): array
    {
        return self::stripExtensions(self::dropRefs($schema));
    }

    public static function forOpenAI(array $schema): array
    {
        $schema = self::dropRefs($schema);
        return self::stripExtensions($schema);
    }

    public static function forGemini(array $schema): array
    {
        $schema = self::dropRefs($schema);
        $schema = self::flattenStringUnions($schema);
        $schema = self::dropUnsupportedFormats($schema);
        $schema = self::stripExtensions($schema);
        return $schema;
    }

    /** Remove $ref / $defs / $schema — universally problematic. */
    private static function dropRefs(array $schema): array
    {
        unset($schema['$schema'], $schema['$ref'], $schema['$defs'], $schema['definitions']);
        foreach ($schema as $k => $v) {
            if (is_array($v)) {
                $schema[$k] = self::dropRefs($v);
            }
        }
        return $schema;
    }

    /**
     * If a oneOf/anyOf is composed entirely of string literals (Typebox-style
     * Union of Literals), collapse it to a single string-enum. This is the
     * Gemini-friendly equivalent of Pi's StringEnum helper.
     */
    private static function flattenStringUnions(array $schema): array
    {
        foreach (['oneOf', 'anyOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $literals = [];
                $allStringLiterals = true;
                foreach ($schema[$key] as $branch) {
                    if (
                        is_array($branch)
                        && ($branch['type'] ?? null) === 'string'
                        && isset($branch['const'])
                    ) {
                        $literals[] = $branch['const'];
                    } elseif (
                        is_array($branch)
                        && ($branch['type'] ?? null) === 'string'
                        && isset($branch['enum'])
                        && is_array($branch['enum'])
                    ) {
                        foreach ($branch['enum'] as $v) {
                            $literals[] = $v;
                        }
                    } else {
                        $allStringLiterals = false;
                        break;
                    }
                }
                if ($allStringLiterals && !empty($literals)) {
                    unset($schema[$key]);
                    $schema['type'] = 'string';
                    $schema['enum'] = array_values(array_unique($literals));
                }
            }
        }
        foreach ($schema as $k => $v) {
            if (is_array($v)) {
                $schema[$k] = self::flattenStringUnions($v);
            }
        }
        return $schema;
    }

    /** Gemini rejects most `format` values other than date-time / enum. */
    private static function dropUnsupportedFormats(array $schema): array
    {
        $allowed = ['date-time', 'enum'];
        if (
            ($schema['type'] ?? null) === 'string'
            && isset($schema['format'])
            && !in_array($schema['format'], $allowed, true)
        ) {
            unset($schema['format']);
        }
        foreach ($schema as $k => $v) {
            if (is_array($v)) {
                $schema[$k] = self::dropUnsupportedFormats($v);
            }
        }
        return $schema;
    }

    /** Strip internal `x-superagent-*` markers from the final wire schema. */
    private static function stripExtensions(array $schema): array
    {
        foreach (array_keys($schema) as $k) {
            if (is_string($k) && str_starts_with($k, 'x-superagent-')) {
                unset($schema[$k]);
            } elseif (is_array($schema[$k] ?? null)) {
                $schema[$k] = self::stripExtensions($schema[$k]);
            }
        }
        return $schema;
    }
}
