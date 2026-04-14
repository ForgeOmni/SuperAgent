<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

/**
 * Auto-route a piece of content to the best-matching wing.
 *
 * Strategy: score each wing by how many of its keywords appear in the
 * content (case-insensitive substring match). Ties broken by wing
 * type priority (project > person > agent > topic > general) and
 * then keyword-match density.
 *
 * If no wing scores above zero, returns the configured default wing
 * (or a synthesized wing_general).
 */
class WingDetector
{
    public function __construct(
        private readonly PalaceStorage $storage,
        private readonly string $defaultWingSlug = 'wing_general',
    ) {}

    public function detect(string $content, ?string $hint = null): Wing
    {
        $content = strtolower($content);
        if ($hint !== null) {
            $content .= ' ' . strtolower($hint);
        }

        $wings = $this->storage->listWings();
        if (empty($wings)) {
            return $this->ensureDefault();
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($wings as $wing) {
            $score = $this->scoreWing($wing, $content);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $wing;
            }
        }

        if ($best === null || $bestScore <= 0.0) {
            return $this->ensureDefault();
        }

        return $best;
    }

    public function detectRoom(string $content, ?string $roomHint = null): string
    {
        if ($roomHint !== null && trim($roomHint) !== '') {
            return Room::slugify($roomHint);
        }

        // Use the most salient noun phrase as a weak room hint. We keep
        // this deliberately simple — extract the longest 2-4 word phrase
        // that looks like a topic keyword. Higher-quality extraction
        // lives in MemoryExtractor.
        $clean = preg_replace('/[^a-zA-Z0-9\s-]/', ' ', $content) ?? '';
        $words = preg_split('/\s+/', trim(strtolower($clean))) ?: [];
        $words = array_values(array_filter(
            $words,
            fn ($w) => strlen($w) >= 4 && !in_array($w, self::STOPWORDS, true),
        ));

        if (empty($words)) {
            return 'general';
        }

        $phrase = implode('-', array_slice($words, 0, 3));

        return Room::slugify($phrase);
    }

    private function scoreWing(Wing $wing, string $content): float
    {
        if (empty($wing->keywords)) {
            return 0.0;
        }
        $score = 0.0;
        foreach ($wing->keywords as $kw) {
            $kw = strtolower(trim($kw));
            if ($kw === '') {
                continue;
            }
            $count = substr_count($content, $kw);
            if ($count > 0) {
                $score += $count * (1.0 + min(strlen($kw) / 20.0, 1.0));
            }
        }
        $score += match ($wing->type) {
            WingType::PROJECT => 0.3,
            WingType::PERSON => 0.2,
            WingType::AGENT => 0.15,
            WingType::TOPIC => 0.1,
            WingType::GENERAL => 0.0,
        };

        return $score;
    }

    private function ensureDefault(): Wing
    {
        $wing = $this->storage->loadWing($this->defaultWingSlug);
        if ($wing !== null) {
            return $wing;
        }
        $wing = new Wing(
            slug: $this->defaultWingSlug,
            name: 'General',
            type: WingType::GENERAL,
            description: 'Fallback wing for uncategorized memories',
        );
        $this->storage->saveWing($wing);

        return $wing;
    }

    private const STOPWORDS = [
        'this', 'that', 'with', 'from', 'have', 'been', 'will', 'should',
        'would', 'could', 'there', 'their', 'about', 'which', 'where', 'when',
        'what', 'these', 'those', 'into', 'than', 'then', 'because', 'since',
        'just', 'like', 'also', 'very', 'much', 'some', 'more', 'most', 'only',
        'said', 'says', 'need', 'want', 'make', 'does', 'done', 'them', 'they',
        'your', 'yours', 'ours', 'over', 'under', 'here', 'such',
    ];
}
