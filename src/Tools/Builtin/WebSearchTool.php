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

        try {
            if (empty($apiKey)) {
                // No API key – fall back to WebFetchTool + DuckDuckGo HTML parsing.
                $results = $this->searchWithWebFetch($query, $limit);
            } else {
                $results = match ($searchEngine) {
                    'serper' => $this->searchWithSerper($query, $limit, $apiKey),
                    'google' => $this->searchWithGoogle($query, $limit, $apiKey),
                    'bing' => $this->searchWithBing($query, $limit, $apiKey),
                    default => throw new \Exception("Unsupported search engine: {$searchEngine}"),
                };
            }

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

    /**
     * Fallback: fetch DuckDuckGo HTML via WebFetchTool (no API key required).
     * Reuses WebFetchTool's cURL/stream stack and UA handling.
     */
    private function searchWithWebFetch(string $query, int $limit): array
    {
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);

        $fetchTool = new WebFetchTool();
        $fetchResult = $fetchTool->execute(['url' => $url, 'timeout' => 10]);

        if ($fetchResult->isError) {
            throw new \Exception(
                'WebFetch fallback failed: ' . (is_string($fetchResult->content) ? $fetchResult->content : json_encode($fetchResult->content)) .
                '. Set SEARCH_API_KEY (Serper/Google/Bing) for reliable search.'
            );
        }

        // WebFetchTool already strips HTML; we need the raw HTML to parse links.
        // Re-fetch the raw HTML for link extraction.
        $html = $this->fetchRawHtml($url);

        return $this->parseDuckDuckGoResults($html, $limit);
    }

    /**
     * Fetch raw HTML without converting to text, using cURL when available.
     */
    private function fetchRawHtml(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_USERAGENT      =>
                    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) ' .
                    'AppleWebKit/537.36 (KHTML, like Gecko) ' .
                    'Chrome/124.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.5'],
            ]);
            $html  = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($html === false || $error !== '') {
                throw new \Exception("cURL error: {$error}");
            }

            return (string) $html;
        }

        $ctx = stream_context_create(['http' => [
            'header'          => "User-Agent: Mozilla/5.0 (compatible; SuperAgent/1.0)\r\n",
            'timeout'         => 10,
            'follow_location' => 1,
        ]]);
        $html = @file_get_contents($url, false, $ctx);

        if ($html === false) {
            $err = error_get_last();
            throw new \Exception($err['message'] ?? 'Failed to fetch search page');
        }

        return $html;
    }

    /**
     * Parse result links out of a DuckDuckGo HTML response.
     */
    private function parseDuckDuckGoResults(string $html, int $limit): array
    {
        $results = [];

        // DDG HTML: <a class="result__a" href="...">title</a>
        if (preg_match_all(
            '/<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach (array_slice($matches, 0, $limit) as $match) {
                $href  = html_entity_decode($match[1]);
                $title = strip_tags($match[2]);

                // DDG wraps links as //duckduckgo.com/l/?uddg=<encoded-url>
                if (str_starts_with($href, '//duckduckgo.com/l/?')) {
                    parse_str(parse_url($href, PHP_URL_QUERY) ?? '', $qs);
                    $href = urldecode($qs['uddg'] ?? $href);
                }

                // Skip non-HTTP links (DDG internal pages, etc.)
                if (!str_starts_with($href, 'http')) {
                    continue;
                }

                // Try to grab snippet from the next .result__snippet element
                $results[] = [
                    'title'  => trim($title),
                    'url'    => $href,
                    'snippet' => '',
                    'domain' => parse_url($href, PHP_URL_HOST) ?? '',
                    'source' => 'webfetch_fallback',
                ];
            }
        }

        if (empty($results)) {
            throw new \Exception(
                'No parseable results from DuckDuckGo. ' .
                'Set SEARCH_API_KEY (Serper/Google/Bing) for reliable search.'
            );
        }

        return $results;
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