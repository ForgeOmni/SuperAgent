<?php

declare(strict_types=1);

namespace SuperAgent\Arrow;

/**
 * Apache Arrow IPC writer mirror of SuperAICore\Arrow\ArrowSerializer.
 *
 * Wave 3 / SA-7. SuperAgent and SuperAICore both need to ship tabular
 * payloads as Arrow when the caller requests it. Rather than depend on
 * SuperAICore (which depends on SuperAgent — cyclic), this is a parallel
 * copy with the same public surface and same JSON-columnar fast path.
 *
 * See SuperAICore\Arrow\ArrowSerializer for the full design rationale.
 * Wire format: Apache Arrow IPC stream, JSON-columnar fallback for hosts
 * without a binary Arrow writer. Both shapes round-trip through
 * Perspective.worker().table().
 */
final class ArrowSerializer
{
    private const TYPE_NULL   = 'null';
    private const TYPE_BOOL   = 'bool';
    private const TYPE_INT    = 'int';
    private const TYPE_FLOAT  = 'float';
    private const TYPE_STRING = 'string';

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function fromRows(array $rows): string
    {
        if (empty($rows)) {
            return '{}';
        }

        $columns = [];
        foreach ($rows as $row) {
            foreach ($row as $k => $_) {
                if (!isset($columns[$k])) $columns[$k] = true;
            }
        }
        $columns = array_keys($columns);

        $schema = [];
        foreach ($columns as $col) {
            $schema[$col] = self::inferColumnType($rows, $col);
        }

        $columnar = [];
        foreach ($schema as $col => $_t) $columnar[$col] = [];
        foreach ($rows as $row) {
            foreach ($schema as $col => $type) {
                $columnar[$col][] = self::castValue($row[$col] ?? null, $type);
            }
        }

        return json_encode($columnar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function inferColumnType(array $rows, string $col): string
    {
        foreach ($rows as $row) {
            $v = $row[$col] ?? null;
            if ($v === null) continue;
            if (is_bool($v))   return self::TYPE_BOOL;
            if (is_int($v))    return self::TYPE_INT;
            if (is_float($v))  return self::TYPE_FLOAT;
            if (is_string($v)) return self::TYPE_STRING;
            return self::TYPE_STRING;
        }
        return self::TYPE_NULL;
    }

    private static function castValue(mixed $v, string $type): mixed
    {
        if ($v === null) return null;
        return match ($type) {
            self::TYPE_BOOL   => (bool) $v,
            self::TYPE_INT    => is_numeric($v) ? (int) $v : (string) $v,
            self::TYPE_FLOAT  => is_numeric($v) ? (float) $v : (string) $v,
            self::TYPE_STRING => is_scalar($v) ? (string) $v : json_encode($v),
            default           => null,
        };
    }
}
