<?php

namespace SuperAgent\Tools\Builtin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class HttpRequestTool extends Tool
{
    protected Client $client;

    public function __construct(array $guzzleOptions = [])
    {
        $this->client = new Client(array_merge([
            'timeout' => 30,
            'allow_redirects' => true,
        ], $guzzleOptions));
    }

    public function name(): string
    {
        return 'http_request';
    }

    public function description(): string
    {
        return 'Make an HTTP request (GET, POST, PUT, DELETE, etc.) and return the response body. Useful for calling APIs, fetching web content, and webhooks.';
    }

    public function category(): string
    {
        return 'network';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to request.',
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'HTTP method (GET, POST, PUT, DELETE, PATCH). Default: GET.',
                    'enum' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'],
                ],
                'headers' => [
                    'type' => 'object',
                    'description' => 'Optional HTTP headers as key-value pairs.',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Request body (for POST/PUT/PATCH).',
                ],
                'json' => [
                    'type' => 'object',
                    'description' => 'JSON body (for POST/PUT/PATCH). Mutually exclusive with body.',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $url = $input['url'] ?? '';
        $method = strtoupper($input['method'] ?? 'GET');

        if (empty($url)) {
            return ToolResult::error('URL cannot be empty.');
        }

        $options = [];

        if (isset($input['headers']) && is_array($input['headers'])) {
            $options['headers'] = $input['headers'];
        }

        if (isset($input['json'])) {
            $options['json'] = $input['json'];
        } elseif (isset($input['body'])) {
            $options['body'] = $input['body'];
        }

        try {
            $response = $this->client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            // Truncate very large responses
            if (strlen($body) > 100_000) {
                $body = substr($body, 0, 100_000) . "\n\n... (truncated, total: " . strlen($body) . " bytes)";
            }

            return ToolResult::success("HTTP {$statusCode}\n\n{$body}");
        } catch (GuzzleException $e) {
            return ToolResult::error("HTTP request failed: {$e->getMessage()}");
        }
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
