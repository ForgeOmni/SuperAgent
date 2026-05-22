<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;
use SuperAgent\Tracing\TraceCollector;

/**
 * Agent-driven trace ring snapshot.
 *
 * Inspired by magic-trace's trigger-function pattern: the traced program
 * (here: the agent itself) decides "this moment is interesting" and tells
 * the recorder to dump the ring buffer. Useful when the agent suspects
 * it's about to loop, is about to do something irreversible, or has just
 * completed a long debate / red-team round worth archiving.
 *
 * Common call sites:
 *   - Before any destructive tool call ("snapshot('about to git reset')")
 *   - When the model notices it's reread the same file 3+ times
 *   - At natural milestones in a long-running agent
 *
 * The tool always succeeds — when tracing is disabled or the ring is empty,
 * it returns a noop message instead of erroring (so the agent doesn't
 * misinterpret tracing config issues as task failure).
 */
class SnapshotTool extends Tool
{
    public function name(): string
    {
        return 'snapshot';
    }

    public function description(): string
    {
        return 'Flush the agent execution trace ring to disk with a tagged reason. Call this when something interesting happens — about to loop, about to do something irreversible, debate round just finished, etc. Returns the file path so an operator can open the timeline in Perfetto.';
    }

    public function category(): string
    {
        return 'observability';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'Free-text label for this snapshot (e.g., "about to run irreversible migration", "third time reading same file").',
                ],
                'tag' => [
                    'type' => 'string',
                    'description' => 'Optional short tag for filtering on the viewer (e.g., "loop_suspect", "milestone", "pre_destructive").',
                ],
            ],
            'required' => ['reason'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $reason = (string) ($input['reason'] ?? '');
        $tag = isset($input['tag']) && is_string($input['tag']) ? $input['tag'] : null;

        if ($reason === '') {
            return ToolResult::error('Reason is required — pass a short string describing why this moment is interesting.');
        }

        $tracer = TraceCollector::getInstance();
        if (!$tracer->isEnabled()) {
            return ToolResult::success(
                "Tracing is disabled (SUPERAGENT_TRACE_ENABLED=false). Snapshot skipped: \"{$reason}\"."
            );
        }

        // Always record the instant — even when the dump fails (no writer
        // configured) the in-memory ring keeps the marker for a later
        // manual dump.
        $tracer->emitInstant(
            name: 'snapshot',
            category: 'marker',
            tid: 'agent:' . ($tag ?? 'self'),
            args: [
                'reason' => mb_substr($reason, 0, 500),
                'tag'    => $tag,
            ],
        );

        $path = $tracer->dump(
            trigger: 'snapshot',
            reason: mb_substr($reason, 0, 200),
            extraMetadata: $tag !== null ? ['tag' => $tag] : [],
        );

        if ($path === null) {
            return ToolResult::success(
                "Snapshot marker recorded in-ring: \"{$reason}\". No writer configured — call dump-trace artisan / use TraceCollector::setWriter() to persist."
            );
        }

        return ToolResult::success(sprintf(
            'Snapshot dumped: %s  reason="%s"%s',
            $path,
            mb_substr($reason, 0, 100),
            $tag !== null ? "  tag={$tag}" : '',
        ));
    }

    public function isReadOnly(): bool
    {
        // Pure observability: writes a trace file but does not mutate
        // application state, so it's safe to call any number of times.
        return true;
    }
}
