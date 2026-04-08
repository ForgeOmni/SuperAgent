<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Providers;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Memory\Contracts\MemoryProviderInterface;

/**
 * Vector-based memory provider using embedding similarity search.
 *
 * Stores memory entries as text with associated embeddings for semantic retrieval.
 * Supports multiple embedding backends via a simple callable interface.
 *
 * Requires an embedding function: fn(string $text): array<float>
 *
 * Usage:
 *   $provider = new VectorMemoryProvider(
 *       storagePath: '/path/to/vectors.json',
 *       embedFn: fn(string $text) => $openai->embeddings($text),
 *   );
 */
class VectorMemoryProvider implements MemoryProviderInterface
{
    /** @var array<string, array{content: string, embedding: float[], metadata: array, created_at: string}> */
    private array $entries = [];

    private LoggerInterface $logger;

    /** @var callable(string): float[] */
    private $embedFn;

    private bool $dirty = false;

    public function __construct(
        private string $storagePath,
        callable $embedFn,
        ?LoggerInterface $logger = null,
        private int $maxEntries = 10000,
        private float $similarityThreshold = 0.7,
    ) {
        $this->embedFn = $embedFn;
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return 'vector';
    }

    public function initialize(array $config = []): void
    {
        if (file_exists($this->storagePath)) {
            $data = json_decode(file_get_contents($this->storagePath), true);
            if (is_array($data)) {
                $this->entries = $data;
            }
        }

        $this->logger->info('VectorMemoryProvider initialized', [
            'entries' => count($this->entries),
            'storage' => $this->storagePath,
        ]);
    }

    public function onTurnStart(string $userMessage, array $conversationHistory): ?string
    {
        $results = $this->search($userMessage, 3);

        if (empty($results)) {
            return null;
        }

        $parts = [];
        foreach ($results as $result) {
            $parts[] = "- [{$result['source']}] {$result['content']}";
        }

        return "## Relevant Memories (vector search)\n" . implode("\n", $parts);
    }

    public function onTurnEnd(array $assistantResponse, array $conversationHistory): void
    {
        // Could auto-index key information from the response
    }

    public function onPreCompress(array $messagesToCompress): void
    {
        // Extract and index key information before context compression
        foreach ($messagesToCompress as $msg) {
            $text = $this->extractText($msg);
            if (strlen($text) > 100) {
                $key = 'pre_compress_' . substr(md5($text), 0, 8);
                $this->store($key, mb_substr($text, 0, 500), ['source' => 'pre_compress']);
            }
        }
    }

    public function onSessionEnd(array $fullConversation): void
    {
        $this->persist();
    }

    public function onMemoryWrite(string $key, string $content, array $metadata = []): void
    {
        $this->store($key, $content, $metadata);
    }

    public function search(string $query, int $maxResults = 5): array
    {
        if (empty($this->entries)) {
            return [];
        }

        try {
            $queryEmbedding = ($this->embedFn)($query);
        } catch (\Throwable $e) {
            $this->logger->warning('Vector embedding failed for search', ['error' => $e->getMessage()]);
            return [];
        }

        // Calculate cosine similarity for all entries
        $scored = [];
        foreach ($this->entries as $key => $entry) {
            if (empty($entry['embedding'])) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryEmbedding, $entry['embedding']);
            if ($similarity >= $this->similarityThreshold) {
                $scored[] = [
                    'content' => $entry['content'],
                    'relevance' => $similarity,
                    'source' => $key,
                    'metadata' => $entry['metadata'] ?? [],
                ];
            }
        }

        // Sort by relevance descending
        usort($scored, fn($a, $b) => $b['relevance'] <=> $a['relevance']);

        return array_slice($scored, 0, $maxResults);
    }

    public function isReady(): bool
    {
        return is_callable($this->embedFn);
    }

    public function shutdown(): void
    {
        $this->persist();
    }

    /**
     * Store a memory entry with its embedding.
     */
    public function store(string $key, string $content, array $metadata = []): void
    {
        try {
            $embedding = ($this->embedFn)($content);
        } catch (\Throwable $e) {
            $this->logger->warning('Vector embedding failed for store', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            $embedding = [];
        }

        $this->entries[$key] = [
            'content' => $content,
            'embedding' => $embedding,
            'metadata' => $metadata,
            'created_at' => date('c'),
        ];

        $this->dirty = true;

        // Enforce max entries (LRU eviction)
        if (count($this->entries) > $this->maxEntries) {
            // Remove oldest entry
            $oldest = array_key_first($this->entries);
            unset($this->entries[$oldest]);
        }
    }

    /**
     * Persist entries to disk.
     */
    private function persist(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $this->storagePath . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($this->entries, JSON_UNESCAPED_UNICODE));
        rename($tmp, $this->storagePath);
        $this->dirty = false;
    }

    /**
     * Calculate cosine similarity between two vectors.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }

        $magnitude = sqrt($magnitudeA) * sqrt($magnitudeB);

        return $magnitude > 0 ? $dotProduct / $magnitude : 0.0;
    }

    private function extractText(mixed $msg): string
    {
        if (is_string($msg)) {
            return $msg;
        }
        if (is_object($msg) && method_exists($msg, 'text')) {
            return $msg->text();
        }
        if (is_object($msg) && property_exists($msg, 'content')) {
            $content = $msg->content;
            return is_string($content) ? $content : '';
        }
        return '';
    }
}
