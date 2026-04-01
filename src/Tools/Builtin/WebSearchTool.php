<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class WebSearchTool extends Tool
{
    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Search the web using a search API. Returns search results with titles, URLs, and snippets.';
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
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query to use.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return. Default: 10.',
                ],
                'allowed_domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Only include search results from these domains.',
                ],
                'blocked_domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Never include search results from these domains.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $query = $input['query'] ?? '';
        $limit = min(max(1, $input['limit'] ?? 10), 50);
        $allowedDomains = $input['allowed_domains'] ?? [];
        $blockedDomains = $input['blocked_domains'] ?? [];

        if (empty($query)) {
            return ToolResult::error('Query cannot be empty.');
        }

        // Check for API configuration
        $apiKey = $_ENV['SEARCH_API_KEY'] ?? getenv('SEARCH_API_KEY') ?: null;
        $searchEngine = $_ENV['SEARCH_ENGINE'] ?? getenv('SEARCH_ENGINE') ?: 'serper';

        if (empty($apiKey)) {
            return ToolResult::error('Search API key not configured. Please set SEARCH_API_KEY in your environment.');
        }

        try {
            $results = match ($searchEngine) {
                'serper' => $this->searchWithSerper($query, $limit, $apiKey),
                'google' => $this->searchWithGoogle($query, $limit, $apiKey),
                'bing' => $this->searchWithBing($query, $limit, $apiKey),
                default => throw new \Exception("Unsupported search engine: {$searchEngine}"),
            };

            // Filter results based on domain rules
            if (!empty($allowedDomains) || !empty($blockedDomains)) {
                $results = $this->filterResultsByDomain($results, $allowedDomains, $blockedDomains);
            }

            // Format output
            if (empty($results)) {
                return ToolResult::success('No search results found.');
            }

            $output = $this->formatResults($results);
            return ToolResult::success($output);

        } catch (\Exception $e) {
            return ToolResult::error('Search failed: ' . $e->getMessage());
        }
    }

    private function searchWithSerper(string $query, int $limit, string $apiKey): array
    {
        $url = 'https://google.serper.dev/search';
        
        $data = [
            'q' => $query,
            'num' => $limit,
        ];

        $options = [
            'http' => [
                'header' => [
                    "Content-Type: application/json\r\n",
                    "X-API-KEY: {$apiKey}\r\n",
                ],
                'method' => 'POST',
                'content' => json_encode($data),
                'timeout' => 10,
            ],
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \Exception('Failed to connect to Serper API');
        }

        $json = json_decode($response, true);
        
        if (!isset($json['organic'])) {
            return [];
        }

        $results = [];
        foreach ($json['organic'] as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'url' => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'domain' => parse_url($item['link'] ?? '', PHP_URL_HOST),
            ];
        }

        return $results;
    }

    private function searchWithGoogle(string $query, int $limit, string $apiKey): array
    {
        // Placeholder for Google Custom Search API implementation
        throw new \Exception('Google Custom Search not yet implemented. Please use "serper" search engine.');
    }

    private function searchWithBing(string $query, int $limit, string $apiKey): array
    {
        // Placeholder for Bing Search API implementation
        throw new \Exception('Bing Search not yet implemented. Please use "serper" search engine.');
    }

    private function filterResultsByDomain(array $results, array $allowedDomains, array $blockedDomains): array
    {
        return array_filter($results, function ($result) use ($allowedDomains, $blockedDomains) {
            $domain = $result['domain'] ?? '';
            
            // Check blocked domains first
            foreach ($blockedDomains as $blocked) {
                if (str_contains($domain, $blocked)) {
                    return false;
                }
            }
            
            // If allowed domains specified, check if domain is allowed
            if (!empty($allowedDomains)) {
                foreach ($allowedDomains as $allowed) {
                    if (str_contains($domain, $allowed)) {
                        return true;
                    }
                }
                return false; // Not in allowed list
            }
            
            return true; // No restrictions
        });
    }

    private function formatResults(array $results): string
    {
        $output = [];
        
        foreach ($results as $index => $result) {
            $num = $index + 1;
            $output[] = "{$num}. {$result['title']}";
            $output[] = "   URL: {$result['url']}";
            if (!empty($result['snippet'])) {
                $output[] = "   {$result['snippet']}";
            }
            $output[] = "";
        }
        
        return implode("\n", $output);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}