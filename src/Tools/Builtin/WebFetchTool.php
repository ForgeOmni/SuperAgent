<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class WebFetchTool extends Tool
{
    public function name(): string
    {
        return 'web_fetch';
    }

    public function description(): string
    {
        return 'Fetch and parse web page content. Converts HTML to readable text format.';
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
                    'description' => 'The URL to fetch content from.',
                ],
                'extract' => [
                    'type' => 'string',
                    'description' => 'Optional CSS selector or extraction rule to get specific content.',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Request timeout in seconds. Default: 10.',
                ],
                'follow_redirects' => [
                    'type' => 'boolean',
                    'description' => 'Follow HTTP redirects. Default: true.',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $url = $input['url'] ?? '';
        $extract = $input['extract'] ?? null;
        $timeout = min(max(1, $input['timeout'] ?? 10), 30);
        $followRedirects = $input['follow_redirects'] ?? true;

        if (empty($url)) {
            return ToolResult::error('URL cannot be empty.');
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ToolResult::error('Invalid URL format.');
        }

        // Only allow HTTP/HTTPS
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            return ToolResult::error('Only HTTP and HTTPS URLs are supported.');
        }

        try {
            // Fetch the content
            $content = $this->fetchUrl($url, $timeout, $followRedirects);
            
            // Parse HTML to text
            $text = $this->htmlToText($content, $extract);
            
            if (empty($text)) {
                return ToolResult::success('(Page has no text content)');
            }
            
            // Truncate if too long
            $maxLength = 50000;
            if (strlen($text) > $maxLength) {
                $text = substr($text, 0, $maxLength) . "\n\n... (content truncated)";
            }
            
            return ToolResult::success($text);
            
        } catch (\Exception $e) {
            return ToolResult::error('Failed to fetch URL: ' . $e->getMessage());
        }
    }

    private function fetchUrl(string $url, int $timeout, bool $followRedirects): string
    {
        $options = [
            'http' => [
                'header' => [
                    "User-Agent: SuperAgent/1.0\r\n",
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n",
                    "Accept-Language: en-US,en;q=0.5\r\n",
                ],
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => $followRedirects ? 1 : 0,
                'max_redirects' => 5,
            ],
        ];

        $context = stream_context_create($options);
        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $error = error_get_last();
            throw new \Exception($error['message'] ?? 'Unknown error');
        }

        return $content;
    }

    private function htmlToText(string $html, ?string $selector = null): string
    {
        // Remove script and style elements
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mis', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mis', '', $html);
        
        // If selector provided, try to extract specific content
        if ($selector) {
            $html = $this->extractBySelector($html, $selector);
        }
        
        // Extract title
        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(html_entity_decode(strip_tags($matches[1])));
        }
        
        // Extract meta description
        $description = '';
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/is', $html, $matches)) {
            $description = trim(html_entity_decode($matches[1]));
        }
        
        // Convert breaks to newlines
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        
        // Add newlines for block elements
        $blockElements = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'blockquote'];
        foreach ($blockElements as $tag) {
            $html = preg_replace('/<' . $tag . '(\s[^>]*)?>/i', "\n<" . $tag . '$1>', $html);
            $html = preg_replace('/<\/' . $tag . '>/i', "</" . $tag . ">\n", $html);
        }
        
        // Strip remaining HTML tags
        $text = strip_tags($html);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/\n\s+\n/', "\n\n", $text); // Remove lines with only whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);  // Limit consecutive newlines
        $text = preg_replace('/^\s+/m', '', $text);       // Remove leading whitespace from lines
        $text = trim($text);
        
        // Prepend title and description if available
        $output = [];
        if ($title) {
            $output[] = "Title: {$title}";
        }
        if ($description) {
            $output[] = "Description: {$description}";
        }
        if (!empty($output)) {
            $output[] = str_repeat('-', 40);
        }
        $output[] = $text;
        
        return implode("\n", $output);
    }

    private function extractBySelector(string $html, string $selector): string
    {
        // Simple selector extraction (id or class)
        if (preg_match('/^#([\w-]+)$/', $selector, $matches)) {
            // ID selector
            $id = $matches[1];
            if (preg_match('/<[^>]+\sid=["\']' . preg_quote($id) . '["\'][^>]*>(.*?)<\/[^>]+>/is', $html, $matches)) {
                return $matches[0];
            }
        } elseif (preg_match('/^\.([\w-]+)$/', $selector, $matches)) {
            // Class selector
            $class = $matches[1];
            if (preg_match('/<[^>]+\sclass=["\'][^"\']*\b' . preg_quote($class) . '\b[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is', $html, $matches)) {
                return $matches[0];
            }
        } elseif (preg_match('/^([\w]+)$/', $selector)) {
            // Tag selector
            $tag = $selector;
            if (preg_match('/<' . preg_quote($tag) . '(\s[^>]*)?>.*?<\/' . preg_quote($tag) . '>/is', $html, $matches)) {
                return $matches[0];
            }
        }
        
        // If extraction fails, return original HTML
        return $html;
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}