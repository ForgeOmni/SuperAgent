<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Embeddings;

/**
 * Talks to a local [Ollama](https://ollama.ai) daemon's `/api/embeddings`
 * endpoint. Default model: `nomic-embed-text` (768 dims). The daemon must
 * be running and the model pulled (`ollama pull nomic-embed-text`).
 *
 * **Why this is the path of least resistance.** Almost every developer
 * already running local LLMs has Ollama installed; spinning up an
 * embedding model is one `ollama pull` and zero PHP-extension surgery.
 * For higher-throughput / hermetic deployments where shelling Ollama
 * isn't acceptable, swap in `OnnxEmbeddingProvider` (or a custom
 * implementation that hits OpenAI / Cohere / self-hosted vLLM).
 *
 * Single-row endpoint compatibility: Ollama's `/api/embeddings` accepts
 * one prompt at a time. We loop over the batch internally and surface
 * the per-row failure shape (`[]` for the failed index) without
 * throwing, so a flaky daemon doesn't disable the entire router for
 * the rest of the session.
 */
final class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly string $baseUrl  = 'http://127.0.0.1:11434',
        private readonly string $model    = 'nomic-embed-text',
        private readonly int    $timeoutMs = 5000,
        private readonly ?int   $dimensions = null,
    ) {}

    public function embed(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            $vec = $this->embedOne((string) $text);
            $out[] = $vec ?? [];
        }
        return $out;
    }

    public function dimensions(): int
    {
        return $this->dimensions ?? 0;
    }

    public function fingerprint(): string
    {
        return 'ollama:' . $this->model;
    }

    /** @return list<float>|null  null on failure (caller substitutes []) */
    private function embedOne(string $text): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/embeddings';
        $body = json_encode(['model' => $this->model, 'prompt' => $text], JSON_UNESCAPED_UNICODE);
        if ($body === false) return null;

        $ch = curl_init($url);
        if ($ch === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($resp) || $code < 200 || $code >= 300) return null;

        $decoded = json_decode($resp, true);
        if (!is_array($decoded) || !isset($decoded['embedding']) || !is_array($decoded['embedding'])) return null;

        return array_values(array_map('floatval', $decoded['embedding']));
    }
}
