# Embedded-host plan

How SuperAgent changes so it can be embedded in a multi-tenant SaaS host —
Superroute's conversational assistant is the first — **without changing what it
does for its current hosts**. Five waves, in dependency order. Nothing here is
a behaviour change for an existing caller: every default stays where it is, and
new behaviour arrives behind a named profile or an opt-in policy.

Driving requirements from the host side live in
`Superroute/docs/AI-ASSISTANT-ENGINE-UPSTREAM-CHANGES.md` (items A1–A9 and §4);
this document is the package-side plan for them.

## Ground rules for every wave

- **Additive.** New config keys default to today's values. New contract methods
  arrive on new interfaces, or with defaults — no breaking signature changes.
- **Docs in three languages.** `README.md` / `README_CN.md` / `README_FR.md`,
  and any `docs/*` page that exists as `_CN` / `_FR` gets the same edit. Every
  feature section ends with its *Since* line.
- **CHANGELOG discipline.** One entry per wave in the existing Keep-a-Changelog
  shape, with the `### 💻 Summary` narrative first.
- **Version constant.** `src/CLI/SuperAgentApplication.php::VERSION` and the
  README badge move together with the release.
- **Suite stays green.** 3420 tests today, `failOnRisky` and `failOnWarning`
  already on; a wave lands only with the full suite passing on every matrix leg.
- **No composer lifecycle scripts.** The supply-chain workflow fails the build
  on any; nothing proposed here needs one.

---

## Wave 1 — v1.2.0 · Runtime compatibility (PHP 8.4 / 8.5, Laravel 13) — **shipped**

**Why first:** Superroute's `master` runs Laravel 10 / PHP 8.1 and its `l13`
branch runs Laravel 13 / PHP 8.5. One SuperAgent release line has to serve both,
or the host cannot take the dependency at all. Everything else waits on this.

The good news: SuperAgent's runtime `require` is only `php ^8.1`,
`guzzlehttp/guzzle ^7.15.1` and `psr/log ^3.0`. Illuminate and Symfony Console
are `require-dev` + `suggest`. So "Laravel 13 support" is mostly a test-matrix
and deprecation question, not a dependency rewrite.

**Scope**

1. `composer.json`: widen dev/suggest bounds — `illuminate/support` add `^13.0`,
   `orchestra/testbench` add the L13 major, `phpunit/phpunit` add `^12.0`,
   `symfony/console` add `^8.0`. Runtime `php ^8.1` stays (it already admits
   8.5); the point is that CI must actually exercise 8.5.
2. **Implicit-nullable parameter deprecations.** A scan finds ~43 candidate
   signatures of the form `function f(Foo $x = null)` across `src/` (859 files).
   PHP 8.4+ deprecates them, and the host's `l13` branch runs 8.5. Rewrite as
   `?Foo $x = null` — valid since 7.1, so no version-gated code.
3. `.github/workflows/test.yml`: matrix `php: [8.1, 8.2, 8.3, 8.4, 8.5]`, plus a
   second job that installs the Laravel integration deps per framework major
   (L10 / L11 / L12 / L13 through testbench) and runs the service-provider,
   facade and Artisan-command tests.
4. Audit the Laravel-facing code (`SuperAgentServiceProvider`, `Facades/`,
   `Console/`) for L13 API drift — container binding shapes, command signatures,
   config publishing. Where L10 and L13 genuinely differ, branch on
   `Application::VERSION`; do not fork.
5. Once the 8.5 leg is clean, turn on `failOnDeprecation` in `phpunit.xml`. That
   is what keeps the next deprecation from arriving silently.

**Acceptance:** full suite green on 8.1 through 8.5; the testbench job green on
L10 and L13; a scratch Laravel 13 / PHP 8.5 app can `composer require` the
package and boot the service provider; `composer validate --strict` and the
supply-chain workflow still pass.

**What it actually took** (the estimate was half right):

- 23 implicit-nullable signatures in `src`, not 43. The 43 came from a regex;
  a tokenizer scan separates real parameters from comments and from promoted
  properties that were already nullable.
- Two deprecation families the plan had not counted: 6 `curl_close()` calls
  (no-ops since 8.0) and 129 `Reflection*::setAccessible()` calls (no-ops since
  8.1, which this package requires). 149 deprecations on 8.5 in total, now 0
  from `src` or `tests`.
- The risk was where the plan guessed, but one layer further out: not Laravel
  13 itself — **PHPUnit 12**, which the L13 leg resolves to. It dropped
  docblock metadata, so 9 `@dataProvider` annotations errored rather than
  skipped, and it redirects `error_log` to a per-test file after `setUp()`,
  which silently voided three assertions in `FeatureSpecValidationTest` —
  including two negative ones that would have passed no matter what the code
  did. Both found by running the real stack locally (Laravel 13.32 / PHPUnit
  12.5 / PHP 8.5), not by reading release notes.
- Known and accepted: PHPUnit 12 reports `phpunit.xml` as a deprecated schema
  because `restrictDeprecations` is now `ignoreIndirectDeprecations`. The old
  name still works on 12; the new one does not exist on 10, which the PHP 8.1
  leg is pinned to. One config serves both until 8.1 is dropped.
- Open, non-blocking: 87 PHPUnit *notices* on 12, all "mock object without
  expectations — consider a stub".

---

## Wave 2 — v1.3.0 · Embedded profile and tool policy (A1 + A2) — **shipped**

**Why:** an SDK embedded in a delivery platform must not be able to run shell
commands, edit files or fetch arbitrary URLs. Today `Agent::initializeTools()`
loads the default tool set when `tool_loader.auto_load` is unset, and that
default is `true` (`src/Agent.php:164`, `src/Tools/ToolLoader.php:309`) — so the
safe posture depends on the caller remembering an option. That is not a posture,
it is a hope.

**Scope**

1. **`superagent.profile`** — `workstation` (today, the default) and `embedded`.
   The profile supplies *defaults only*; anything the caller sets explicitly
   still wins. `embedded` means: no tool auto-load, no plugin auto-discovery, no
   skills, no sub-agents, no sandbox, no goal mode, no file history, no session
   sharing.
2. **`ToolPolicy`** — a small value object (`allow_list`, `deny_categories`,
   `read_only_only`) resolved onto the agent. `Tool` already exposes
   `category()`, `isReadOnly()` and `requiresUserInteraction()`; the policy is
   what finally consumes them.
3. **Two-point enforcement.** The policy filters the tool list at assembly *and*
   checks again immediately before a tool executes. One point is not enough: a
   tool can arrive after assembly from a plugin, an MCP server or a future
   builtin added by an SDK upgrade.
4. A named constructor / factory for the embedded case, so a host has one
   obvious way to build a locked-down agent.

**Tests**

- Profile default snapshot: `embedded` yields an empty tool list unless tools
  are passed explicitly; `workstation` yields exactly today's default set.
- A tool injected after assembly, and a tool arriving from an MCP server, are
  both refused by `deny_categories`.
- `read_only_only` admits a read tool and refuses a write tool of the same
  category.
- An upgrade-proofing test: every class under `Tools\Builtin` declares a
  category, so a new builtin cannot be category-less and therefore unfilterable.

**Acceptance:** with `profile: embedded` and an explicit tool list, no class
under `SuperAgent\Tools\Builtin` is reachable, proven by test rather than by
configuration review.

**What it actually took**

Close to the estimate, plus one thing the plan had assumed away. `ToolPolicy`
filters by category — and three builtins (`CreateGoalTool`, `GetGoalTool`,
`UpdateGoalTool`) declared none, so they inherited the base `general`, which no
deny list names. A category-based policy could not have filtered them at all.
They are `planning` now, and `BuiltinToolCategoryLockdownTest` fails on the next
builtin that forgets, in both directions: a tool that inherits the base
category, and a `HOST_CATEGORIES` entry that no builtin declares any more.

Two design points settled while building it:

- **A refused call is an error result, not an exception.** Raising mid-run
  would let one refused tool abort a conversation. Contradictions in the
  caller's own configuration — a tool it named itself that its own policy
  refuses — do raise, at construction, which is the earliest moment they can.
- **A host's own policy merges over the profile's floor rather than replacing
  it.** `'tool_policy' => ['read_only_only' => true]` on an embedded agent
  keeps the host-category denials underneath. Dropping the floor is
  `'tool_policy' => false`, which is explicit.

3450 tests (25 new) green on PHP 8.1 / PHPUnit 10 and on Laravel 13 / PHPUnit
12 / PHP 8.5.

**Size:** small-to-medium, and the highest safety return in the plan.

---

## Wave 3 — v1.4.0 · Deferred tool results (A3) — **shipped**

**Why:** the loop is synchronous. `HookEvent` has `PRE_TOOL_USE`,
`PERMISSION_REQUEST` and `PERMISSION_DENIED`, but a hook can only allow or deny
— it cannot say "a human will answer in ten minutes". Every host with
human-in-the-loop approval (Superroute's confirm cards and write-approval queue;
a coding host approving a destructive command) has to build suspend/resume
around the SDK instead of inside it.

**Scope**

1. `ToolResult::deferred(string $ticketId, array $meta = [])`.
2. The loop treats a deferred result as a clean end of turn, state
   `awaiting_human`, transcript intact and serialisable.
3. A **resume envelope**: everything needed to continue — conversation, the
   pending tool-call id, provider wire family — serialisable so submit and
   resume can happen in different processes, after a deploy.
4. `Agent::resume($envelope, $ticketId, ToolResult $result)` continues the turn
   as if the tool had answered.
5. Hook integration: a `PRE_TOOL_USE` hook may return "defer" beside allow/deny.

**The hard part** is wire-format round-tripping. Each provider family expects
the tool result in its own shape and position — Anthropic `tool_result` blocks,
OpenAI `tool` messages, Gemini `functionResponse`. The SDK already has
`Conversation/Transcoder`, `WireFamily` and `ProviderArtifacts`, so the path
exists; the work is proving it round-trips per family, including after
serialisation.

**Tests:** per-provider round-trip (defer → serialise → new process → resume →
model sees a well-formed result); resume with an unknown or expired ticket
refused; double-resume refused; a deferred turn that is never resumed leaves no
partial state.

**What it actually took**

The wire round-trip was the work the plan expected, and it needed one piece the
plan had not named: `MessageSerializer`. `Message::toArray()` reports both a
tool result and a user message as `role: user` — correct on the wire, lossy for
a round trip — so a transcript could not be rebuilt from it at all.

Two design points settled while building it:

- **Completed siblings wait with the deferred call.** A provider rejects an
  assistant message whose tool calls are only half answered, so when one tool
  in a turn defers, the results that did complete are held in the envelope and
  sent together when the answer arrives.
- **Refusals happen before any model call.** Unknown ticket, already-answered
  ticket, expired envelope, envelope from another provider — all
  `ResumeException`, so a duplicate queue delivery or a double-clicked Approve
  cannot run the same tool twice.

Two latent defects the tests exposed, both outside the feature:

- **Gemini function calls were never executed.** Gemini reports
  `finishReason: STOP` on a turn that asks for a function call; the loop only
  runs tools on a `tool_use` stop reason; nothing reconciled the two. A Gemini
  agent with tools quietly did nothing with them. Found because the Gemini leg
  of the round-trip never reached the tool.
- **Every hook attached to a QueryEngine threw.** All four call sites built
  `HookInput` with an `event:` argument the class does not have and omitted the
  two it requires, so attaching a registry turned the first tool call into
  `Error: Unknown named parameter $event`. The hook classes had tests; the
  wiring did not. `HookResult::merge()` also dropped fields it did not know
  about, which would have lost the new defer decision whenever two hooks were
  registered.

3475 tests (48 new across waves 2–3) green on PHP 8.1 / PHPUnit 10 and on
Laravel 13 / PHPUnit 12 / PHP 8.5. The wave-1 deprecation gate caught one
implicit-nullable parameter in the new tests on the 8.5 leg, which is what it
is for.

**Size:** the largest wave, and the only one with real design content. Its value
is not Superroute-specific.

---

## Wave 4 — v1.5.0 · Tenant hygiene (A4, A6, A8) — **shipped**

Three changes that only matter once many tenants share one long-lived process.

1. **`SessionStorageInterface`** extracted from the concrete
   `Session/SessionStorage`, with `SqliteSessionStorage` and the current class as
   implementations, resolved through the container. A SaaS host then keeps
   transcripts in its own tables instead of a local SQLite file it must then
   secure, back up and purge per tenant.
2. **Credential resolver.** `api_key` is already per-instance
   (`src/Agent.php:94`), which is enough for per-tenant keys; add an optional
   `fn(RequestContext): Credentials` resolver so the key is fetched at call time
   rather than held in an object graph — plus an audit of every log, telemetry
   and exception path, and a test asserting keys are redacted.
3. **Static-state audit.** `ModelCatalog` and other registries hold static
   caches — fine in a CLI process, less obviously fine in a queue worker serving
   many tenants. Document which statics hold request-scoped data, add `reset()`
   where one does, and add a test that runs two agents for two tenants in one
   process and asserts nothing bleeds between them.

**What it actually took**

Item 1 came out differently than the plan assumed. `SessionStorage` (file
paths, atomic writes, directory scans) and `SqliteSessionStorage` (save / load
/ search / prune) are not two implementations of one concept, so "extract an
interface with both as implementations" was not a thing that could be done.
The useful seam is the semantic one: `SessionStore`, which `SqliteSessionStorage`
already implemented in all but name, injected into `SessionManager` — and when
a host injects one, the bundled SQLite file is not opened at all, since writing
a second copy of other people's conversations to local disk is the thing the
injection exists to prevent.

Item 3 was investigation, as expected, and produced an inventory rather than a
sweep: the statics split cleanly into *catalogue* (model prices, aliases,
feature flags — identical for every tenant, deliberately kept) and
*accumulated* (provider instances with their credentials, cost / metrics /
event singletons, shared plan-mode state — cleared). `RuntimeState::inventory()`
publishes both lists so a host can assert against them.

Three defects the work exposed:

- **`AgentSpawnConfig::toArray()` serialised the parent's API key in clear
  text.** Nothing in this repo calls it today, which is why nobody had noticed;
  it is the array a host logs or ships over a wire.
- **The telemetry singletons fataled outside a booted Laravel app.**
  `CostTracker` and three siblings read `config()` unguarded in their
  constructors, and the bundled polyfill stands aside whenever Illuminate is
  merely on the autoloader — so a plain worker got
  `Class "config" does not exist`. Found because the two-tenant test
  constructed one.
- **The provider instance cache was unbounded**, keeping one client and its
  credential per tenant config for the life of the process.

3488 tests (13 new) green on PHP 8.1 / PHPUnit 10 and on Laravel 13 / PHPUnit
12 / PHP 8.5.

**Size:** small each; item 3 is investigation more than code.

---

## Wave 5 — v1.6.0 · Signals and provenance (A5, A7, A9)

1. **Multilingual injection detection.** `PromptInjectionDetector::PATTERNS` is
   English regexes. Split into per-language packs (en, zh-Hans, zh-Hant, fr at
   minimum), add a `DetectorInterface` so a host can register its own, and an
   "annotate" mode returning categories and a score instead of a boolean.
   Hosts whose untrusted text is not English currently get false confidence.
2. **Cost provenance.** `resources/models.json` prices 195 models; expose its
   version / `_meta` alongside a computed cost so a host that stores money can
   record which price list produced the number and recompute later.
3. **Streaming in a web request.** Document (and test) the path from
   `StreamingHandler` to an SSE response with no `symfony/console` dependency —
   the shape a Laravel controller needs.

**Size:** small, independent; can land in any order after Wave 1.

---

## Sequencing and parallelism

```
Wave 1 ──┬── Wave 2 ──── Wave 3
         ├── Wave 4
         └── Wave 5
```

Wave 1 blocks everything for a Laravel 13 host. Wave 2 is the safety gate a host
needs before it embeds the SDK at all. Wave 3 is what a host needs before it
lets the assistant perform writes. Waves 4 and 5 are independent of 2 and 3 and
can be interleaved.

## How the host consumes it

Superroute pins an exact version and upgrades deliberately. In its own plan
(`docs/AI-ASSISTANT-PLATFORM-DESIGN.md`): P0 (read-only assistant) needs Waves 1
and 2; P2 (writes behind confirm cards and the approval queue) needs Wave 3;
Waves 4 and 5 harden what is already shipped. Nothing in this plan gives the SDK
authority over what a user may do — that stays with the host's MCP tool
registry and permission layer in every wave.
