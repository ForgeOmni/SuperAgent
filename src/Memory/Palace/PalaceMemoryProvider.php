<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Memory\Contracts\MemoryProviderInterface;
use SuperAgent\Memory\Palace\Layers\LayerManager;

/**
 * Palace-backed memory provider.
 *
 * Plugs the palace into SuperAgent's existing MemoryProviderManager via
 * the same interface the builtin file-based provider uses. The manager
 * already calls onTurnStart / onTurnEnd / onPreCompress / search, so
 * wiring is transparent.
 *
 * Behavior:
 *   - onTurnStart:   emit wake-up (L0+L1) once per session, then top
 *                    drawers for the user's message.
 *   - onTurnEnd:     file the assistant's response as a drawer under
 *                    the auto-detected wing/room.
 *   - onPreCompress: flush the messages-about-to-be-lost into drawers.
 *   - search:        expose retrieval to the manager's unified search.
 *
 * All writes go through MemoryDeduplicator when enabled.
 */
class PalaceMemoryProvider implements MemoryProviderInterface
{
    private LoggerInterface $logger;
    private bool $ready = false;
    private bool $wokenUp = false;

    public function __construct(
        private readonly PalaceStorage $storage,
        private readonly PalaceGraph $graph,
        private readonly PalaceRetriever $retriever,
        private readonly LayerManager $layers,
        private readonly WingDetector $detector,
        private readonly ?MemoryDeduplicator $dedup = null,
        private readonly ?string $defaultWingSlug = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return 'palace';
    }

    public function initialize(array $config = []): void
    {
        $this->ready = true;
        $this->wokenUp = false;
        $this->logger->info('PalaceMemoryProvider initialized', [
            'base_path' => $this->storage->basePath(),
            'wings' => count($this->storage->listWings()),
            'vector_enabled' => $this->retriever->vectorEnabled(),
        ]);
    }

    public function onTurnStart(string $userMessage, array $conversationHistory): ?string
    {
        if (!$this->ready) {
            return null;
        }

        $parts = [];
        if (!$this->wokenUp) {
            $wake = $this->layers->wakeUp($this->defaultWingSlug);
            if ($wake !== '') {
                $parts[] = $wake;
            }
            $this->wokenUp = true;
        }

        $hits = $this->retriever->search($userMessage, 3, [
            'wing' => $this->defaultWingSlug,
            'follow_tunnels' => true,
        ]);
        if (!empty($hits)) {
            $lines = ['## Recalled Drawers (L3)'];
            foreach ($hits as $hit) {
                /** @var Drawer $drawer */
                $drawer = $hit['drawer'];
                $preview = $this->preview($drawer->content, 200);
                $lines[] = sprintf(
                    '- [%s / %s / %s] %s',
                    $drawer->wingSlug,
                    $drawer->hall->value,
                    $drawer->roomSlug,
                    $preview,
                );
                $drawer->markAccessed();
                $this->storage->saveDrawer($drawer);
            }
            $parts[] = implode("\n", $lines);
        }

        return empty($parts) ? null : implode("\n\n", $parts);
    }

    public function onTurnEnd(array $assistantResponse, array $conversationHistory): void
    {
        if (!$this->ready) {
            return;
        }
        $text = $this->extractText($assistantResponse);
        if ($text === '') {
            return;
        }

        $this->fileAsDrawer($text, hint: $this->lastUserText($conversationHistory));
    }

    public function onPreCompress(array $messagesToCompress): void
    {
        if (!$this->ready) {
            return;
        }
        foreach ($messagesToCompress as $msg) {
            $text = is_array($msg) ? ($msg['content'] ?? '') : (string) $msg;
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            $this->fileAsDrawer($text, hint: null, hall: Hall::EVENTS);
        }
    }

    public function onSessionEnd(array $fullConversation): void
    {
        if (!$this->ready) {
            return;
        }
        $this->wokenUp = false;
    }

    public function onMemoryWrite(string $key, string $content, array $metadata = []): void
    {
        if (!$this->ready) {
            return;
        }
        $this->fileAsDrawer($content, hint: $key, hall: Hall::forKind((string) ($metadata['type'] ?? 'facts')), metadata: $metadata);
    }

    public function search(string $query, int $maxResults = 5): array
    {
        $hits = $this->retriever->search($query, $maxResults, ['follow_tunnels' => true]);
        $out = [];
        foreach ($hits as $hit) {
            /** @var Drawer $drawer */
            $drawer = $hit['drawer'];
            $out[] = [
                'content' => $drawer->content,
                'relevance' => $hit['score'],
                'source' => sprintf('palace:%s/%s/%s', $drawer->wingSlug, $drawer->hall->value, $drawer->roomSlug),
                'metadata' => [
                    'drawer_id' => $drawer->id,
                    'breakdown' => $hit['breakdown'],
                ],
            ];
        }

        return $out;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function shutdown(): void
    {
        $this->ready = false;
    }

    // ── Internal ───────────────────────────────────────────────────

    private function fileAsDrawer(
        string $content,
        ?string $hint = null,
        ?Hall $hall = null,
        array $metadata = [],
    ): ?Drawer {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $wing = $this->detector->detect($content, $hint);
        $roomSlug = $this->detector->detectRoom($content, $hint);
        $hall ??= Hall::forKind((string) ($metadata['type'] ?? 'events'));

        $drawer = new Drawer(
            id: Drawer::generateId(),
            wingSlug: $wing->slug,
            hall: $hall,
            roomSlug: $roomSlug,
            content: $content,
            metadata: $metadata,
        );

        if ($this->dedup !== null) {
            $existing = $this->dedup->findDuplicate($drawer);
            if ($existing !== null) {
                $existing->markAccessed();
                $this->storage->saveDrawer($existing);

                return $existing;
            }
        }

        // Ensure room exists + update graph (auto-tunnel if cross-wing).
        $existingRoom = $this->storage->loadRoom($wing->slug, $hall, $roomSlug);
        if ($existingRoom === null) {
            $room = new Room(
                slug: $roomSlug,
                name: $roomSlug,
                wingSlug: $wing->slug,
                hall: $hall,
                drawerCount: 1,
            );
            $this->storage->saveRoom($room);
            $this->graph->recordRoom($room);
        } else {
            $existingRoom->drawerCount++;
            $existingRoom->touch();
            $this->storage->saveRoom($existingRoom);
        }

        $this->storage->saveDrawer($drawer);

        return $drawer;
    }

    private function extractText(array $response): string
    {
        if (isset($response['content']) && is_string($response['content'])) {
            return $response['content'];
        }
        if (isset($response['text']) && is_string($response['text'])) {
            return $response['text'];
        }
        // Responses may be a list of content blocks.
        if (isset($response[0]) && is_array($response[0])) {
            $buf = [];
            foreach ($response as $block) {
                if (isset($block['text']) && is_string($block['text'])) {
                    $buf[] = $block['text'];
                }
            }

            return implode("\n", $buf);
        }

        return '';
    }

    private function lastUserText(array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $msg = $history[$i];
            if (is_array($msg) && ($msg['role'] ?? null) === 'user') {
                $c = $msg['content'] ?? '';
                if (is_string($c)) {
                    return $c;
                }
            }
        }

        return null;
    }

    private function preview(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return strlen($text) <= $max ? $text : substr($text, 0, $max - 3) . '...';
    }
}
