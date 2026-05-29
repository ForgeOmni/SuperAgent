# Pi-aligned JSON Event Stream

SuperAgent emits session events under a canonical taxonomy borrowed from
[pi's JSON Event Stream Mode](https://pi.dev/docs/latest/json) so external
tooling (SuperAICore `/processes`, pi viewers, third-party log shippers)
can consume one consistent vocabulary regardless of backend.

## Event types

All events carry `type` + `timestamp` (RFC3339 UTC). Additional fields are
type-specific.

| `type` | Emitted when | Key fields |
|---|---|---|
| `session` | First line of a session file | `version`, `id`, `cwd`, `parentSession?` |
| `agent_start` / `agent_end` | Whole session boundary | `sessionId` |
| `turn_start` / `turn_end` | One user→assistant interaction | `turnId`, `sessionId`, `model` |
| `message_start` / `message_update` / `message_end` | Streaming message progression | `messageId`, `role`, `text_delta?`, `thinking_delta?` |
| `tool_execution_start` / `tool_execution_update` / `tool_execution_end` | Tool invocation lifecycle | `toolCallId`, `name`, `arguments`, `partial_result?`, `result?`, `error?` |
| `queue_update` | Pending steering/follow-up queue changes | `pending: string[]` |
| `compaction_start` / `compaction_end` | Manual or auto compaction | `tokensBefore`, `firstKeptEntryId?`, `summary?` |
| `auto_retry_start` / `auto_retry_end` | Provider/network retry attempt | `attempt`, `reason?`, `backoffMs?` |
| `model_change` | Mid-session model swap | `provider`, `modelId` |
| `thinking_level_change` | Mid-session thinking-budget swap | `thinkingLevel` |

## Wire format

JSONL, LF-only line terminators. Each event:

```json
{"type":"turn_start","timestamp":"2026-05-22T10:01:00Z","sessionId":"s-1","turnId":"t-1","model":"claude-opus-4-7"}
```

## API

```php
use SuperAgent\Tracing\PiEventStream;
use SuperAgent\Tracing\PiEventStreamWriter;

PiEventStream::subscribe(new PiEventStreamWriter('/path/to/session.events.jsonl'));

PiEventStream::emit(PiEventStream::AGENT_START, ['sessionId' => 's-1']);
PiEventStream::emit(PiEventStream::TURN_START, ['turnId' => 't-1', 'sessionId' => 's-1']);
// ...
PiEventStream::emit(PiEventStream::AGENT_END, ['sessionId' => 's-1']);
```

## Legacy back-compat

`SuperAgent\Telemetry\StructuredLogger` still emits its older `type` values
(`tool_execution`, `llm_request`, `llm_response`, ...). A translator is
provided so consumers can normalize them:

```php
PiEventStream::translateLegacy('tool_execution'); // → 'tool_execution_end'
```

The legacy names will be removed in the next major; new call sites SHOULD
emit Pi-aligned events directly.
