<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers;

use GuzzleHttp\Client;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Base for every tool that surfaces a provider's native side-endpoint as
 * a standard SuperAgent Tool — the "Specialty-as-Tool" pattern from
 * `design/NATIVE_PROVIDERS_CN.md` §4.4.
 *
 * Why this exists:
 *   - Provider-specific endpoints (GLM `/web_search`, Kimi `/files`,
 *     MiniMax `/t2a_v2` etc.) aren't reachable via the normal chat loop.
 *     Wrapping them as Tools lets **any main LLM** (Claude, GPT, Gemini…)
 *     call them via ordinary tool-calling — the vendor that owns the
 *     endpoint doesn't have to be the main brain.
 *   - The wrapper handles auth, base-URL resolution, region and pool key
 *     rotation via the already-constructed `LLMProvider` instance so each
 *     tool only writes the path-specific HTTP logic.
 *
 * Attributes:
 *   - `network`   — tool reaches the public internet / vendor API; should
 *                   respect an `--offline` switch in Phase 8.
 *   - `cost`      — tool invocation is metered by the vendor (media
 *                   generation, search queries, batch submissions);
 *                   should respect per-day / per-request limits.
 *   - `sensitive` — tool uploads caller-supplied data to a vendor
 *                   (file-extract etc.) and may need explicit approval.
 *
 * Attributes are consumed by `ToolSecurityValidator` in Phase 8; until
 * then they're advisory metadata surfaced via `attributes()` for callers
 * that want to inspect them.
 */
abstract class ProviderToolBase extends Tool
{
    public function __construct(
        protected readonly LLMProvider $provider,
    ) {}

    public function getProvider(): LLMProvider
    {
        return $this->provider;
    }

    /**
     * Advisory metadata used by the forthcoming ToolSecurityValidator.
     * Subclasses SHOULD override when their profile differs from pure
     * read-only network access.
     *
     * @return array<int, string>
     */
    public function attributes(): array
    {
        return ['network'];
    }

    public function hasAttribute(string $attr): bool
    {
        return in_array($attr, $this->attributes(), true);
    }

    public function category(): string
    {
        return 'provider-native';
    }

    /**
     * Default: provider tools are read-only from SuperAgent's perspective
     * (they don't mutate the local filesystem or environment). Subclasses
     * that upload files or mutate vendor-side state override to false.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Shared helper to pull the authenticated Guzzle client out of a
     * `ChatCompletionsProvider` / `QwenProvider` subclass. Subclasses use
     * it to reuse the already-configured bearer / base URL / headers
     * rather than re-authenticating for every side-endpoint call.
     *
     * Not type-hinted on a single base because Qwen doesn't inherit from
     * `ChatCompletionsProvider`; reflection keeps the helper usable across
     * the small set of providers that expose a `$client` field.
     */
    protected function client(): Client
    {
        $ref = new \ReflectionObject($this->provider);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        if (! $ref) {
            throw new \LogicException(
                'Provider '. $this->provider->name() .' has no $client property',
            );
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        return $prop->getValue($this->provider);
    }

    /**
     * Wrap the inner call so network / decoding failures come back as a
     * non-throwing `ToolResult::error()` — the agent loop treats that as
     * "the tool failed, try something else" rather than propagating to
     * the user as an uncaught exception.
     */
    protected function safeInvoke(callable $fn): ToolResult
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf(
                '[%s] %s: %s',
                $this->name(),
                $e::class,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Poll a callable until it reports "done" or the timeout elapses.
     *
     * `$probe` returns an array with at least `status` (`'done'`|`'failed'`|
     * anything else for "still running"). Extra fields are passed through
     * to the caller as the poll result. When `status = 'done'`, the full
     * probe payload is returned. When `'failed'`, a `RuntimeException` is
     * raised with the payload's error message (or a generic one).
     *
     * Used by async tools (KimiBatch, MiniMax video/music/image, long-form
     * TTS) to sync-wait on upstream job completion without each tool
     * reimplementing the exponential-backoff loop. Tools that want to
     * return a `JobHandle` instead of blocking should skip this helper
     * and call the submit path directly.
     *
     * @param callable(): array{status: string, error?: string, ...} $probe
     *
     * @return array{status: string, ...} The final probe payload.
     *
     * @throws \RuntimeException on upstream failure or timeout.
     */
    protected function pollUntilDone(
        callable $probe,
        int $timeoutSeconds = 120,
        float $intervalSeconds = 2.0,
    ): array {
        foreach ($this->pollIterator($probe, $timeoutSeconds, $intervalSeconds) as $payload) {
            if (($payload['status'] ?? null) === 'done') {
                return $payload;
            }
        }

        // pollIterator handles 'failed' + timeout by throwing — reaching
        // this point means the generator completed without yielding 'done',
        // which should never happen but guards against future edits.
        throw new \RuntimeException('pollUntilDone: generator drained without a terminal state');
    }

    /**
     * Non-blocking cousin of `pollUntilDone()` — yields the probe payload
     * after each iteration, sleeping in the caller's frame between yields.
     *
     * Use this when running under a job queue (Laravel queue worker etc.)
     * where blocking the whole process on `usleep` is unacceptable — the
     * caller can release the worker, reschedule after a delay, and resume
     * on the next run. The generator's internal state (deadline, interval)
     * stays consistent across yields because each iteration recomputes
     * from `microtime(true)`.
     *
     * Under the hood `pollUntilDone()` drives this generator in a tight
     * loop with `usleep`, preserving its original blocking semantics for
     * CLI use.
     *
     * @param callable(): array{status: string, error?: string, ...} $probe
     *
     * @return \Generator<int, array{status: string, ...}>
     *
     * @throws \RuntimeException on upstream 'failed' or timeout.
     */
    protected function pollIterator(
        callable $probe,
        int $timeoutSeconds = 120,
        float $intervalSeconds = 2.0,
    ): \Generator {
        $deadline = microtime(true) + $timeoutSeconds;
        $interval = max(0.1, $intervalSeconds);

        while (true) {
            $payload = $probe();
            $status = (string) ($payload['status'] ?? 'unknown');

            yield $payload;

            if ($status === 'done') {
                return;
            }
            if ($status === 'failed') {
                throw new \RuntimeException(
                    $payload['error'] ?? 'upstream job reported failure',
                );
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException(
                    "job did not complete within {$timeoutSeconds}s (last status: {$status})",
                );
            }

            // Blocking sleep — the sync wrapper path. Non-blocking callers
            // consume the generator directly and manage sleeping themselves
            // (e.g. Laravel queue's `release($delay)`).
            usleep((int) ($interval * 1_000_000));
        }
    }
}
