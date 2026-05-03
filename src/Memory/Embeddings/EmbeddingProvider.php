<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Embeddings;

/**
 * Batch embedding provider used by `Skills\SemanticSkillRouter` and any
 * downstream code that wants to embed multiple texts in one call.
 *
 * Why batch? Most embedding endpoints (OpenAI, Ollama, Cohere, local
 * ONNX MiniLM) charge / cost the same regardless of whether you submit
 * 1 or 32 inputs in a single request — the rate-limit / round-trip
 * cost dominates. Forcing a batch shape at the interface saves callers
 * from N round-trips when they have N skills / drawers to vectorise.
 *
 * **Single-text adapter for legacy callers.** `VectorMemoryProvider`
 * still uses the `callable(string): array<float>` shape from earlier
 * SDK versions. To plug an `EmbeddingProvider` into it without changing
 * that contract, wrap it:
 *
 *   $vectorMem = new VectorMemoryProvider(
 *       embedFn: fn (string $t) => $provider->embed([$t])[0] ?? [],
 *       …
 *   );
 *
 * **Bundled implementations** (none required at runtime; the SDK's
 * keyword fallbacks degrade silently when no provider is wired):
 *   - `NullEmbeddingProvider` — returns empty vectors. Useful in tests.
 *   - `CallableEmbeddingProvider` — adapts an existing closure.
 *   - `OllamaEmbeddingProvider` — talks to a local Ollama daemon.
 *   - `OnnxEmbeddingProvider` — opt-in `ext-onnxruntime` adapter.
 *     Doesn't ship the model file or extension — see the class
 *     docblock for the install path / companion-package plan.
 *
 * Hosts can implement their own (Cohere, OpenAI, prebuilt Parquet
 * cache, …) by satisfying this interface.
 */
interface EmbeddingProvider
{
    /**
     * Embed N input strings and return N vectors in the same order.
     *
     * Implementations MUST preserve order. On per-row failure they MAY
     * return an empty vector for that index instead of throwing — the
     * caller treats `[]` as "no signal, fall back to baseline". Throw
     * only on infrastructure failure (network, model load) so the caller
     * can decide whether to disable the provider for the rest of the run.
     *
     * @param  list<string>           $texts
     * @return list<list<float>>      one vector per input, same order
     */
    public function embed(array $texts): array;

    /**
     * Embedding dimensionality. Used by callers to allocate vector
     * buffers / validate sidecar caches. Return 0 when the dimension
     * isn't known statically (e.g. forwarded to a configurable upstream).
     */
    public function dimensions(): int;

    /**
     * Stable identifier for this provider + model combination. Used as
     * the cache key prefix so a switch from MiniLM-L6 to MiniLM-L12
     * invalidates everything that was vectorised with the old model
     * without touching unrelated cache entries.
     */
    public function fingerprint(): string;
}
