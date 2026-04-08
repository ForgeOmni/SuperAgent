<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Providers;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Memory\Contracts\MemoryProviderInterface;

/**
 * Episodic memory provider that stores conversation episodes with temporal context.
 *
 * Unlike the builtin MEMORY.md (factual/declarative) or vector store (semantic),
 * episodic memory captures WHEN things happened and in what sequence — enabling
 * temporal reasoning ("last time we discussed X", "after the deployment on Tuesday").
 *
 * Episodes are stored as structured JSON with:
 * - Temporal markers (timestamps, relative ordering)
 * - Session context (what project, what branch)
 * - Key events (decisions, errors, completions)
 * - Emotional valence (positive/negative outcome)
 */
class EpisodicMemoryProvider implements MemoryProviderInterface
{
    /** @var array<int, array{summary: string, timestamp: string, context: array, events: array, outcome: string}> */
    private array $episodes = [];

    private LoggerInterface $logger;

    public function __construct(
        private string $storagePath,
        ?LoggerInterface $logger = null,
        private int $maxEpisodes = 500,
        private int $maxEventsPerEpisode = 20,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return 'episodic';
    }

    public function initialize(array $config = []): void
    {
        if (file_exists($this->storagePath)) {
            $data = json_decode(file_get_contents($this->storagePath), true);
            if (is_array($data)) {
                $this->episodes = $data;
            }
        }

        $this->logger->info('EpisodicMemoryProvider initialized', [
            'episodes' => count($this->episodes),
        ]);
    }

    public function onTurnStart(string $userMessage, array $conversationHistory): ?string
    {
        $relevant = $this->findRelevantEpisodes($userMessage, 3);

        if (empty($relevant)) {
            return null;
        }

        $parts = [];
        foreach ($relevant as $ep) {
            $time = $this->formatRelativeTime($ep['timestamp']);
            $parts[] = "- [{$time}] {$ep['summary']} (outcome: {$ep['outcome']})";
        }

        return "## Related Past Episodes\n" . implode("\n", $parts);
    }

    public function onTurnEnd(array $assistantResponse, array $conversationHistory): void
    {
        // Accumulate events in the current episode
    }

    public function onPreCompress(array $messagesToCompress): void
    {
        // Extract episode from messages about to be compressed
        $this->createEpisodeFromMessages($messagesToCompress);
    }

    public function onSessionEnd(array $fullConversation): void
    {
        // Create a complete episode from the session
        $this->createEpisodeFromMessages($fullConversation);
        $this->persist();
    }

    public function onMemoryWrite(string $key, string $content, array $metadata = []): void
    {
        // Record memory writes as events in the current episode
    }

    public function search(string $query, int $maxResults = 5): array
    {
        $relevant = $this->findRelevantEpisodes($query, $maxResults);

        return array_map(fn($ep) => [
            'content' => $ep['summary'],
            'relevance' => $ep['_score'] ?? 0.5,
            'source' => 'episode_' . ($ep['timestamp'] ?? 'unknown'),
            'metadata' => [
                'outcome' => $ep['outcome'] ?? 'unknown',
                'timestamp' => $ep['timestamp'] ?? null,
                'context' => $ep['context'] ?? [],
            ],
        ], $relevant);
    }

    public function isReady(): bool
    {
        return true;
    }

    public function shutdown(): void
    {
        $this->persist();
    }

    /**
     * Create an episode from a conversation segment.
     */
    public function createEpisodeFromMessages(array $messages): void
    {
        if (empty($messages)) {
            return;
        }

        // Extract summary from first user message
        $summary = '';
        $events = [];
        $toolNames = [];

        foreach ($messages as $msg) {
            if (is_object($msg)) {
                $role = $msg->role->value ?? 'unknown';
                if ($role === 'user' && empty($summary)) {
                    $content = property_exists($msg, 'content') ? $msg->content : '';
                    $summary = is_string($content) ? mb_substr($content, 0, 200) : '';
                }
                if (property_exists($msg, 'content') && is_array($msg->content)) {
                    foreach ($msg->content as $block) {
                        if (is_object($block) && property_exists($block, 'toolName') && $block->toolName) {
                            $toolNames[] = $block->toolName;
                            if (count($events) < $this->maxEventsPerEpisode) {
                                $events[] = [
                                    'type' => 'tool_use',
                                    'tool' => $block->toolName,
                                ];
                            }
                        }
                    }
                }
            }
        }

        if (empty($summary)) {
            return;
        }

        // Determine outcome based on last message
        $lastMsg = end($messages);
        $outcome = 'completed';
        if (is_object($lastMsg) && property_exists($lastMsg, 'content')) {
            $content = is_array($lastMsg->content) ? $lastMsg->content : [];
            foreach ($content as $block) {
                if (is_object($block) && property_exists($block, 'isError') && $block->isError) {
                    $outcome = 'error';
                    break;
                }
            }
        }

        $episode = [
            'summary' => $summary,
            'timestamp' => date('c'),
            'context' => [
                'cwd' => getcwd() ?: '.',
                'tools_used' => array_unique($toolNames),
                'message_count' => count($messages),
            ],
            'events' => $events,
            'outcome' => $outcome,
        ];

        $this->episodes[] = $episode;

        // Enforce max episodes (remove oldest)
        while (count($this->episodes) > $this->maxEpisodes) {
            array_shift($this->episodes);
        }
    }

    /**
     * Find episodes relevant to a query using keyword matching.
     */
    private function findRelevantEpisodes(string $query, int $limit): array
    {
        $queryWords = array_filter(
            preg_split('/\s+/', strtolower($query)),
            fn($w) => strlen($w) > 2
        );

        if (empty($queryWords)) {
            return array_slice(array_reverse($this->episodes), 0, $limit);
        }

        $scored = [];
        foreach ($this->episodes as $i => $episode) {
            $text = strtolower(
                ($episode['summary'] ?? '') . ' ' .
                implode(' ', $episode['context']['tools_used'] ?? [])
            );

            $score = 0;
            foreach ($queryWords as $word) {
                if (str_contains($text, $word)) {
                    $score++;
                }
            }

            // Boost recent episodes
            $age = time() - strtotime($episode['timestamp'] ?? 'now');
            $recencyBoost = max(0, 1.0 - ($age / (86400 * 30))); // Decay over 30 days

            $totalScore = $score + ($recencyBoost * 0.5);

            if ($totalScore > 0) {
                $episode['_score'] = $totalScore;
                $scored[] = $episode;
            }
        }

        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Format a timestamp as relative time.
     */
    private function formatRelativeTime(string $timestamp): string
    {
        $diff = time() - strtotime($timestamp);

        if ($diff < 3600) {
            $mins = (int) ($diff / 60);
            return "{$mins}m ago";
        }
        if ($diff < 86400) {
            $hours = (int) ($diff / 3600);
            return "{$hours}h ago";
        }
        $days = (int) ($diff / 86400);
        return "{$days}d ago";
    }

    private function persist(): void
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $this->storagePath . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($this->episodes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $this->storagePath);
    }
}
