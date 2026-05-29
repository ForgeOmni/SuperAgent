<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Streaming;

use SuperAgent\Arrow\ArrowSerializer;
use SuperAgent\Messages\AssistantMessage;

/**
 * Translate assistant messages carrying tabular payloads to Arrow IPC chunks.
 *
 * Wave 5 / SA-10. Sibling to OpenAIStreamTranslator. When a downstream
 * consumer is a data tool (Perspective viewer, pandas/DuckDB, BI dashboard),
 * shipping rows as JSON SSE chunks burns CPU on both ends. This translator
 * detects tabular payloads in the message (either `content[].type === 'json'`
 * with a recognized table shape, or an explicit `tabular_payload` attribute)
 * and emits them as a single Arrow IPC stream chunk instead.
 *
 * Wire format:
 *   SSE event:   `event: arrow_table\n`
 *   SSE data:    `data: <base64(arrow bytes)>\n\n`
 *
 * Non-tabular content (regular text, tool calls) falls through to the
 * supplied JSON translator unchanged — so this is purely additive. Hosts
 * opt in by inspecting the assistant message before / after the LLM call
 * and tagging the payload as `tabular_payload`.
 */
final class ArrowStreamTranslator
{
    public function __construct(
        private readonly OpenAIStreamTranslator $jsonTranslator,
    ) {}

    /**
     * @return string[] Each element is a complete SSE event ready to write.
     */
    public function translate(AssistantMessage $message): array
    {
        $tabular = $this->extractTabular($message);
        if ($tabular === null) {
            // No tabular payload — defer entirely to the JSON translator.
            return $this->jsonTranslator->translate($message);
        }

        $arrowBytes = ArrowSerializer::fromRows($tabular['rows']);
        $b64 = base64_encode($arrowBytes);

        $events = [];
        // Emit the arrow table FIRST so consumers can start materializing
        // before the trailing JSON descriptor lands.
        $events[] = "event: arrow_table\n" .
                    "data: " . $b64 . "\n\n";
        $events[] = "event: arrow_table_meta\n" .
                    "data: " . json_encode([
                        'row_count'    => count($tabular['rows']),
                        'column_count' => isset($tabular['rows'][0]) ? count((array) $tabular['rows'][0]) : 0,
                        'schema_hint'  => $tabular['schema'] ?? null,
                        'encoding'     => 'arrow-ipc-json-columnar-fallback',
                    ], JSON_UNESCAPED_SLASHES) . "\n\n";

        // Any remaining non-tabular content still streams as JSON.
        $jsonChunks = $this->jsonTranslator->translate($message);
        return array_merge($events, $jsonChunks);
    }

    /**
     * Detect a tabular payload on the message.
     *
     * Recognition order:
     *   1. Explicit `tabular_payload` attribute on the message (host-tagged)
     *   2. A content block of type 'json' whose decoded value is a list of
     *      associative arrays with consistent keys (auto-detect)
     *
     * @return array{rows: list<array<string,mixed>>, schema: ?array}|null
     */
    private function extractTabular(AssistantMessage $message): ?array
    {
        // Path 1: explicit attribute set by host code
        $explicit = $message->tabularPayload ?? null;
        if (is_array($explicit) && !empty($explicit) && is_array(reset($explicit))) {
            return ['rows' => $explicit, 'schema' => null];
        }

        // Path 2: auto-detect a tabular JSON block
        foreach ($message->content as $block) {
            if (($block->type ?? null) !== 'json') continue;
            $val = $block->data ?? null;
            if (!is_array($val) || empty($val)) continue;
            $first = reset($val);
            if (!is_array($first) || empty($first)) continue;
            // Heuristic: every entry is an assoc array sharing ≥2 keys
            $firstKeys = array_keys($first);
            $ok = true;
            foreach ($val as $row) {
                if (!is_array($row)) { $ok = false; break; }
                if (count(array_intersect($firstKeys, array_keys($row))) < 2) { $ok = false; break; }
            }
            if ($ok) return ['rows' => array_values($val), 'schema' => null];
        }

        return null;
    }
}
