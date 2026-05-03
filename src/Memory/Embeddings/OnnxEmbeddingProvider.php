<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Embeddings;

/**
 * **Scaffolding (B3).** Opt-in adapter for an in-process ONNX embedding
 * model — the long-game replacement for `OllamaEmbeddingProvider` when
 * the host can't run a daemon.
 *
 * jcode bundles MiniLM-L6-v2 inline so its agents get vector RAG with
 * zero external dependency. PHP's analogous path requires:
 *
 *   1. `ext-onnxruntime` (or a pure-PHP runtime; `phpllm/onnxruntime`
 *      maintains a binding). This SDK does not depend on it.
 *   2. The MiniLM-L6-v2 ONNX model file (~80MB). Too large to bundle;
 *      ships in the optional `forgeomni/superagent-embeddings` package
 *      (planned).
 *
 * Until that companion package lands, this class exists as the
 * documented integration point so:
 *   - hosts have a stable type they can `instanceof` against;
 *   - any custom in-process ONNX wrapper can be slotted in by extending
 *     and overriding `runModel()` / `tokenise()`;
 *   - the constructor surfaces a clear error if the runtime isn't
 *     present, instead of failing deep inside an embedding sweep.
 *
 * **Wiring outline once the companion package exists:**
 * ```php
 * $provider = new \SuperAgent\Memory\Embeddings\OnnxEmbeddingProvider(
 *     modelPath: __DIR__ . '/vendor/forgeomni/superagent-embeddings/models/all-MiniLM-L6-v2.onnx',
 *     vocabPath: __DIR__ . '/vendor/forgeomni/superagent-embeddings/models/vocab.txt',
 * );
 * $router = new \SuperAgent\Skills\SemanticSkillRouter(
 *     skillManager: $manager,
 *     embedder: $provider,
 * );
 * ```
 *
 * Until then, this class throws at `embed()` time with a message
 * pointing at the install path. It still satisfies the
 * `EmbeddingProvider` interface so DI / type-checking work.
 */
final class OnnxEmbeddingProvider implements EmbeddingProvider
{
    public const DEFAULT_DIMENSIONS = 384;
    public const DEFAULT_MODEL_NAME = 'all-MiniLM-L6-v2';

    public function __construct(
        private readonly ?string $modelPath = null,
        private readonly ?string $vocabPath = null,
        private readonly int     $dimensions = self::DEFAULT_DIMENSIONS,
        private readonly string  $modelName = self::DEFAULT_MODEL_NAME,
    ) {}

    public function embed(array $texts): array
    {
        $this->ensureRuntime();
        // Reachable only when a subclass provides runModel(); the default
        // implementation throws above. Scaffolded shape so subclasses can
        // override one method and inherit the batch wrapper.
        $out = [];
        foreach ($texts as $text) {
            $tokens = $this->tokenise((string) $text);
            $out[]  = $this->runModel($tokens);
        }
        return $out;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function fingerprint(): string
    {
        return 'onnx:' . $this->modelName;
    }

    /**
     * Tokeniser stub. Subclasses override with the model's own
     * WordPiece / BPE vocabulary. Returns an empty token list by default
     * so a misconfigured deploy fails fast in `runModel()` instead of
     * silently producing garbage vectors.
     *
     * @return list<int>
     */
    protected function tokenise(string $text): array
    {
        return [];
    }

    /**
     * Run the model on a token sequence and return the pooled vector.
     * Default implementation throws — subclasses (or the future
     * companion package) provide the real ONNX session call.
     *
     * @param  list<int> $tokens
     * @return list<float>
     */
    protected function runModel(array $tokens): array
    {
        throw new \RuntimeException(
            'OnnxEmbeddingProvider::runModel() is not implemented. '
            . 'Install the companion package `forgeomni/superagent-embeddings` '
            . '(planned) or subclass and override runModel(). For an out-of-the-box '
            . 'embedder today, use OllamaEmbeddingProvider instead.'
        );
    }

    private function ensureRuntime(): void
    {
        if ($this->modelPath === null) {
            throw new \RuntimeException(
                'OnnxEmbeddingProvider requires a modelPath. Pass it to the '
                . 'constructor, or use OllamaEmbeddingProvider for a '
                . 'dependency-free local embedder.'
            );
        }
        if (!is_file($this->modelPath)) {
            throw new \RuntimeException(
                "OnnxEmbeddingProvider: model file not found at {$this->modelPath}. "
                . 'See class docblock for the install path.'
            );
        }
        if (!extension_loaded('onnxruntime') && !class_exists(\OnnxRuntime\Model::class)) {
            throw new \RuntimeException(
                'OnnxEmbeddingProvider needs either ext-onnxruntime (PHP extension) '
                . 'or the `ankane/onnxruntime` userland binding. Neither is loaded. '
                . 'Until the companion package lands, prefer OllamaEmbeddingProvider.'
            );
        }
    }
}
