# Wire Event Protocol v1

> **Status:** Phase 8a (interface + renderer) **and** Phase 8b (base-class migration) have both landed. Every existing `StreamEvent` subclass (10 classes: `TurnCompleteEvent`, `ToolStartedEvent`, `ToolCompletedEvent`, `TextDeltaEvent`, `ThinkingDeltaEvent`, `AgentCompleteEvent`, `CompactionEvent`, `ErrorEvent`, `StatusEvent`) is now `WireEvent`-compliant for free — their `toArray()` carries `wire_version: 1` and a stable `type`, and any of them can be emitted through `JsonStreamRenderer`. Phase 8c (ACP IDE bridge) remains deferred.
>
> **Inspiration:** Moonshot's kimi-cli `src/kimi_cli/wire/types.py` — a single Pydantic-defined event stream that drives shell TUI, ACP IDE server, and `stream-json` output from the same source. Our `src/Harness/*Event.php` hierarchy is close in spirit but ad-hoc; formalizing it unlocks the same TUI/IDE/CI-pipe story.

---

## 1. Why a unified wire protocol

We have three plausible consumers of agent-loop events:

1. **Interactive TUI** (`src/Console/Output/RealTimeCliRenderer.php`) — the streaming "Claude-Code-style" REPL.
2. **`--output json-stream` CLI mode** — one line of JSON per event for pipeline consumption, CI logging, editor integrations.
3. **ACP IDE server** (not yet implemented) — agent-control-protocol bridge so JetBrains / VS Code plugins can drive a local SuperAgent.

Today each new consumer would have to re-scrape `StreamEvent` subclasses. A versioned, self-describing protocol means **one** schema to honor, and breaking changes are explicit (bump `wire_version`).

## 2. Core invariants

Every wire event is:

- **One JSON object on one line** — no trailing whitespace, no pretty-printing. `jq -c` / pipelines parse trivially.
- **Self-describing** — `type` and `wire_version` are at the top level on every event.
- **Additive-safe** — consumers that pin `wire_version: 1` must keep working even when new optional fields are added. Breaking a field shape requires `wire_version: 2`.

## 3. Event catalog (draft v1)

Types below are the target; existing classes are the current name in our codebase.

| `type`                | Today's class                    | Carries                                                                     |
|-----------------------|----------------------------------|-----------------------------------------------------------------------------|
| `turn.begin`          | `TurnBeginEvent`                 | turn number, model id, provider name                                        |
| `turn.end`            | `TurnEndEvent`                   | turn number, stop reason, final text length                                 |
| `text.delta`          | `TextDeltaEvent`                 | assistant text chunk                                                        |
| `thinking.delta`      | `ThinkingEvent`                  | reasoning text chunk (when thinking enabled)                                |
| `tool.call`           | `ToolStartedEvent`               | tool name, tool_use_id, input args                                          |
| `tool.result`         | `ToolCompletedEvent`             | tool_use_id, output, success flag, duration                                 |
| `tool.permission_ask` | `PermissionRequestEvent`         | pending tool + input, decision channel ref                                  |
| `usage`               | `UsageEvent`                     | prompt_tokens, completion_tokens, cached_tokens, cost USD                   |
| `compaction.begin`    | `CompactionBeginEvent`           | trigger, context size before                                                |
| `compaction.end`      | `CompactionEndEvent`             | context size after, messages dropped                                        |
| `agent.spawned`       | `SubAgentSpawnedEvent`           | child agent_id, subagent_type, parent id                                    |
| `agent.completed`     | `SubAgentCompletedEvent`         | child agent_id, status, filesWritten, toolCallsByName (from 0.8.9)          |
| `hook.fired`          | (new)                            | hook name, input summary                                                    |
| `error`               | `ErrorEvent`                     | code, message, retryable flag                                               |

Two event types are deliberately **new**:

- `tool.permission_ask` — projects the pending-approval state onto the stream so any UI can render it. Today permissions go through `Hooks/` + private channels; surfacing them on the wire follows kimi-cli's `ApprovalRuntime` pattern.
- `hook.fired` — makes hook execution visible for logging / debugging without requiring direct access to the hook runtime.

## 4. Canonical shapes

Every event has the following skeleton:

```json
{
  "wire_version": 1,
  "type": "<event-type>",
  "ts": "2026-04-22T10:00:00Z",
  ...
}
```

### `turn.begin`
```json
{
  "wire_version": 1, "type": "turn.begin", "ts": "…",
  "turn": 3,
  "model":    "claude-opus-4-7",
  "provider": "anthropic"
}
```

### `tool.call`
```json
{
  "wire_version": 1, "type": "tool.call", "ts": "…",
  "tool_use_id": "toolu_01X...",
  "name":  "Read",
  "input": {"file_path": "/tmp/x.md"}
}
```

### `usage`
```json
{
  "wire_version": 1, "type": "usage", "ts": "…",
  "turn": 3,
  "input_tokens":         1024,
  "output_tokens":         320,
  "cached_input_tokens":   800,
  "cost_usd":             0.0123
}
```

### `agent.completed` (aligns with 0.8.9 productivity instrumentation)
```json
{
  "wire_version": 1, "type": "agent.completed", "ts": "…",
  "agent_id": "...",
  "status":   "completed|completed_empty|async_launched",
  "files_written":       ["/abs/path/report.md"],
  "tool_calls_by_name":  {"Read": 3, "Write": 1},
  "total_tool_use_count": 4,
  "productivity_warning": null
}
```

## 5. Migration plan (Phase 8b — NOT in this PR)

Do the migration in small PRs, one event family at a time, to keep diffs reviewable:

1. **Turn events** — `TurnBeginEvent`, `TurnEndEvent` → implement `WireEvent`. Update `StreamEventEmitter` to call `JsonStreamRenderer::emit()` when `--output json-stream` is active.
2. **Text + thinking** — `TextDeltaEvent`, `ThinkingEvent`.
3. **Tool events** — `ToolStartedEvent`, `ToolCompletedEvent`, new `PermissionRequestEvent` with fields projected from the existing hook runtime.
4. **Usage + compaction** — `UsageEvent`, `CompactionBeginEvent`, `CompactionEndEvent`.
5. **Agent events** — `SubAgentSpawnedEvent`, `SubAgentCompletedEvent`. These are already rich (Phase 0.8.9 productivity fields); just wrap the existing `toArray` in the WireEvent contract.
6. **Error + hook** — `ErrorEvent` plus a new `HookFiredEvent` tapped into the existing hook pipeline.

Compat rule during migration: the existing `toArray` / `__toString` methods on each event class must keep producing byte-exact output for pre-0.8.9 consumers. The new `WireEvent::toArray()` can be wider (more fields, the `wire_version` key, etc.), but the old shape stays a strict subset.

## 6. ACP bridge (Phase 8c — deferred)

When at least the first three migration steps land, we can expose the same stream as an ACP-compatible endpoint:

```
$ superagent acp --listen 127.0.0.1:7125 &
# IDE plugin connects, gets the same wire_version:1 event stream
```

This is a thin I/O layer on top of `JsonStreamRenderer` — no new protocol work. Kept separate so it can land once we have enough migrated events for a useful IDE UX (you need at least `turn.*`, `text.delta`, and `tool.*` to light up an editor panel).

## 7. Consumer guarantees

Consumers pinning `wire_version: 1` can assume:

- Every event has `type` and `wire_version` at the top level.
- Adding a new optional field to an event is not a breaking change.
- Removing or changing the type of an existing field is a breaking change → bump `wire_version` to 2 and dual-emit during the deprecation window (similar to our `resources/models.json` v1 ↔ v2 story).
