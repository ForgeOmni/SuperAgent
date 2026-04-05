<?php

declare(strict_types=1);

namespace SuperAgent\Performance;

use GuzzleHttp\Client;

class BatchApiClient
{
    /** @var array Queued requests */
    private array $queue = [];

    /** @var array<string, array> Results keyed by custom_id */
    private array $results = [];

    public function __construct(
        private bool $enabled = true,
        private int $maxBatchSize = 100,
        private ?Client $client = null,
        private string $apiKey = '',
        private string $baseUrl = 'https://api.anthropic.com/',
        private string $apiVersion = '2023-06-01',
    ) {}

    /**
     * Create an instance from application configuration.
     *
     * Reads `superagent.performance.batch_api` when the `config()` helper is
     * available.  Unlike other performance optimizations the batch client is
     * disabled by default when no configuration is present, because it requires
     * a valid API key to be useful.
     */
    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config')
                ? (config('superagent.performance.batch_api') ?? [])
                : [];
        } catch (\Throwable) {
            $config = [];
        }

        $apiKey = $config['api_key'] ?? '';
        $baseUrl = $config['base_url'] ?? 'https://api.anthropic.com/';
        $apiVersion = $config['api_version'] ?? '2023-06-01';

        $client = new Client([
            'base_uri' => $baseUrl,
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => $apiVersion,
                'content-type' => 'application/json',
            ],
        ]);

        return new self(
            enabled: $config['enabled'] ?? false,
            maxBatchSize: $config['max_batch_size'] ?? 100,
            client: $client,
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            apiVersion: $apiVersion,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Queue a request for batch processing.
     *
     * @param string $customId Unique identifier for this request
     * @param array  $body     Request body (same as Messages API)
     */
    public function queue(string $customId, array $body): void
    {
        $this->queue[] = [
            'custom_id' => $customId,
            'params' => $body,
        ];
    }

    /**
     * Submit all queued requests as a batch.
     * Returns the batch ID for polling.
     */
    public function submit(): ?string
    {
        if ($this->queue === []) {
            return null;
        }

        $client = $this->resolveClient();

        // Anthropic limits batch size; chunk if necessary but submit only the
        // first chunk here -- callers needing multiple chunks should drain the
        // queue in a loop.
        $requests = array_splice($this->queue, 0, $this->maxBatchSize);

        $response = $client->post('v1/messages/batches', [
            'json' => ['requests' => $requests],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return $data['id'] ?? null;
    }

    /**
     * Poll a batch for completion.
     * Returns null if still processing, results array if done.
     *
     * @return array<string, array>|null Results keyed by custom_id, or null if not ready
     */
    public function poll(string $batchId): ?array
    {
        $client = $this->resolveClient();

        $response = $client->get("v1/messages/batches/{$batchId}");
        $data = json_decode((string) $response->getBody(), true);

        if (($data['processing_status'] ?? '') !== 'ended') {
            return null;
        }

        $resultsUrl = $data['results_url'] ?? null;

        if ($resultsUrl === null) {
            return [];
        }

        return $this->downloadResults($resultsUrl);
    }

    /**
     * Submit and wait for results (blocking).
     *
     * @param int $timeoutSeconds Max wait time
     * @return array<string, array> Results keyed by custom_id
     *
     * @throws \RuntimeException When the batch does not complete within the timeout
     */
    public function submitAndWait(int $timeoutSeconds = 300): array
    {
        $batchId = $this->submit();

        if ($batchId === null) {
            return [];
        }

        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $results = $this->poll($batchId);

            if ($results !== null) {
                $this->results = array_merge($this->results, $results);

                return $results;
            }

            sleep(5);
        }

        throw new \RuntimeException(
            "Batch {$batchId} did not complete within {$timeoutSeconds} seconds."
        );
    }

    /**
     * Get queue size.
     */
    public function queueSize(): int
    {
        return count($this->queue);
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Resolve a Guzzle client, creating one on the fly if none was injected.
     */
    private function resolveClient(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'content-type' => 'application/json',
            ],
        ]);

        return $this->client;
    }

    /**
     * Download the JSONL results file and map entries by custom_id.
     *
     * @return array<string, array>
     */
    private function downloadResults(string $resultsUrl): array
    {
        $client = $this->resolveClient();

        $response = $client->get($resultsUrl);
        $body = (string) $response->getBody();

        $mapped = [];

        foreach (explode("\n", trim($body)) as $line) {
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);

            if (isset($entry['custom_id'])) {
                $mapped[$entry['custom_id']] = $entry['result'] ?? $entry;
            }
        }

        return $mapped;
    }
}
