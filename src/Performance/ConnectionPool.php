<?php

declare(strict_types=1);

namespace SuperAgent\Performance;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlMultiHandler;

/**
 * HTTP connection pooling for LLM API calls.
 *
 * Reuses TCP/TLS connections across multiple requests to the same host,
 * eliminating handshake overhead. Uses cURL multi handler with keep-alive.
 */
class ConnectionPool
{
    /** @var array<string, Client> Pooled clients keyed by base URL */
    private static array $clients = [];

    private bool $enabled;

    public function __construct(bool $enabled = true)
    {
        $this->enabled = $enabled;
    }

    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config')
                ? (config('superagent.performance.connection_pool') ?? [])
                : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get or create a pooled Guzzle client for the given base URL.
     *
     * The client is configured with:
     * - cURL keep-alive (Connection: keep-alive header)
     * - TCP_NODELAY for reduced latency
     * - Connection reuse via shared cURL multi handler
     *
     * @param string $baseUrl  Base URL (e.g. 'https://api.anthropic.com/')
     * @param array  $headers  Default headers for all requests
     * @param int    $timeout  Request timeout in seconds
     */
    public function getClient(string $baseUrl, array $headers = [], int $timeout = 300): Client
    {
        if (!$this->enabled) {
            return new Client([
                'base_uri' => $baseUrl,
                'headers' => $headers,
                'timeout' => $timeout,
            ]);
        }

        $key = $baseUrl;

        if (!isset(self::$clients[$key])) {
            $handler = HandlerStack::create(new CurlMultiHandler());

            self::$clients[$key] = new Client([
                'base_uri' => $baseUrl,
                'headers' => array_merge($headers, [
                    'Connection' => 'keep-alive',
                ]),
                'timeout' => $timeout,
                'handler' => $handler,
                'curl' => [
                    CURLOPT_TCP_NODELAY => true,
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_TCP_KEEPIDLE => 60,
                    CURLOPT_TCP_KEEPINTVL => 30,
                ],
            ]);
        }

        return self::$clients[$key];
    }

    /**
     * Close all pooled connections.
     */
    public static function closeAll(): void
    {
        self::$clients = [];
    }

    /**
     * Get number of active connection pools.
     */
    public static function poolCount(): int
    {
        return count(self::$clients);
    }
}
