<?php

declare(strict_types=1);

namespace SuperAgent\Skills;

use SuperAgent\Memory\Embeddings\CallableEmbeddingProvider;
use SuperAgent\Memory\Embeddings\EmbeddingProvider;

/**
 * Picks the top-K skills most relevant to a user prompt before injection.
 * Borrowed from jcode — same idea: skills aren't loaded eagerly into the
 * system prompt, but selected per-turn via embedding cosine similarity so
 * a 50-skill catalog only contributes ~3 to the prompt.
 *
 * SuperAgent already has `Memory\Providers\VectorMemoryProvider` for
 * embedding-backed memory retrieval; this router reuses the same embedder
 * abstraction so we don't bundle a second vector stack. Three constructor
 * shapes are accepted, in order of preference:
 *
 *   1. an `EmbeddingProvider` instance (`OllamaEmbeddingProvider`,
 *      `OnnxEmbeddingProvider`, or a host-implemented adapter);
 *   2. a `callable(list<string>):list<list<float>>` (legacy / inline);
 *   3. `null` — the router falls back to keyword overlap on
 *      `skill->description` so the SkillManager keeps working in
 *      pure-PHP environments.
 *
 * Wiring (preferred — typed provider):
 *
 *   $router = new SemanticSkillRouter(
 *       skillManager: $manager,
 *       embedder: new \SuperAgent\Memory\Embeddings\OllamaEmbeddingProvider(),
 *       threshold: 0.55,
 *       topK: 3,
 *   );
 *
 *   $selected = $router->select('Refactor the auth middleware to use new tokens');
 *   foreach ($selected as $skill) {
 *       $agent->addSkillToPrompt($skill);
 *   }
 */
final class SemanticSkillRouter
{
    /** Cached embeddings by skill name → vector — invalidates when a skill changes. */
    private array $skillVectors = [];

    /** Cached skill description hash so we know when to re-embed. */
    private array $skillHashes = [];

    /** Normalised provider; null = keyword-overlap fallback. */
    private ?EmbeddingProvider $provider = null;

    /**
     * @param EmbeddingProvider|callable(list<string>):list<list<float>>|null $embedder
     */
    public function __construct(
        private readonly SkillManager $skillManager,
        EmbeddingProvider|callable|null $embedder = null,
        private readonly float $threshold = 0.5,
        private readonly int $topK = 3,
    ) {
        $this->provider = $this->normalise($embedder);
    }

    /**
     * Replace the embedder at runtime (e.g. swapping providers mid-session).
     * Accepts the same shapes the constructor does.
     *
     * @param EmbeddingProvider|callable(list<string>):list<list<float>> $embedder
     */
    public function setEmbedder(EmbeddingProvider|callable $embedder): void
    {
        $this->provider = $this->normalise($embedder);
        $this->skillVectors = [];
        $this->skillHashes = [];
    }

    private function normalise(EmbeddingProvider|callable|null $embedder): ?EmbeddingProvider
    {
        if ($embedder === null) return null;
        if ($embedder instanceof EmbeddingProvider) return $embedder;
        return new CallableEmbeddingProvider($embedder);
    }

    /**
     * Return up to `$topK` skills with cosine ≥ `$threshold`. Falls back
     * to a simple keyword-overlap ranking when no embedder is available
     * — never returns an error, always degrades gracefully so the
     * caller can blindly inject the result.
     *
     * @return list<Skill>
     */
    public function select(string $prompt): array
    {
        $skills = $this->skillManager->getAll();
        if (!is_array($skills) || $skills === []) return [];
        $skills = array_values($skills);

        // Cheap tokenisation fallback when no embedder present.
        if ($this->provider === null) {
            return $this->keywordFallback($prompt, $skills);
        }

        try {
            $this->ensureSkillVectors($skills);
            $vectors = $this->provider->embed([$prompt]);
            if (!isset($vectors[0]) || !is_array($vectors[0]) || $vectors[0] === []) {
                return $this->keywordFallback($prompt, $skills);
            }
            $promptVec = array_values(array_map('floatval', $vectors[0]));
        } catch (\Throwable) {
            return $this->keywordFallback($prompt, $skills);
        }

        $scored = [];
        foreach ($skills as $skill) {
            $name = $skill->name();
            $vec = $this->skillVectors[$name] ?? null;
            if (!$vec) continue;
            $score = $this->cosine($promptVec, $vec);
            if ($score < $this->threshold) continue;
            $scored[] = ['skill' => $skill, 'score' => $score];
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, max(1, $this->topK));
        return array_map(static fn ($r) => $r['skill'], $top);
    }

    /** @param list<Skill> $skills */
    private function ensureSkillVectors(array $skills): void
    {
        $needsEmbed = [];
        foreach ($skills as $skill) {
            $name = $skill->name();
            $blurb = $this->blurb($skill);
            $hash  = sha1($blurb);
            if (($this->skillHashes[$name] ?? null) !== $hash) {
                $needsEmbed[$name] = $blurb;
                $this->skillHashes[$name] = $hash;
            }
        }
        if ($needsEmbed === []) return;

        $names = array_keys($needsEmbed);
        $texts = array_values($needsEmbed);
        $vectors = $this->provider?->embed($texts);
        if (!is_array($vectors)) return;
        foreach ($names as $i => $name) {
            if (!isset($vectors[$i]) || !is_array($vectors[$i])) continue;
            $this->skillVectors[$name] = array_values(array_map('floatval', $vectors[$i]));
        }
    }

    private function blurb(Skill $skill): string
    {
        $parts = [$skill->name(), $skill->description()];
        if (method_exists($skill, 'category')) {
            $parts[] = 'category: ' . $skill->category();
        }
        return implode("\n", array_filter($parts));
    }

    /**
     * Last-ditch keyword overlap. Splits prompt into tokens, scores each
     * skill by the count of overlapping description tokens. Stable, fast,
     * good-enough fallback when no embedder is wired.
     *
     * @param list<Skill> $skills
     * @return list<Skill>
     */
    private function keywordFallback(string $prompt, array $skills): array
    {
        $promptTokens = array_count_values($this->tokenise($prompt));
        if ($promptTokens === []) return [];

        $scored = [];
        foreach ($skills as $skill) {
            $skillTokens = array_count_values($this->tokenise($skill->description()));
            $overlap = 0;
            foreach ($promptTokens as $tok => $count) {
                if (isset($skillTokens[$tok])) {
                    $overlap += min($count, $skillTokens[$tok]);
                }
            }
            if ($overlap === 0) continue;
            $scored[] = ['skill' => $skill, 'score' => $overlap];
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, max(1, $this->topK));
        return array_map(static fn ($r) => $r['skill'], $top);
    }

    /** @return list<string> */
    private function tokenise(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
        $tokens = [];
        foreach (preg_split('/\s+/', trim($text)) as $w) {
            if ($w === '' || mb_strlen($w) < 2) continue;
            $tokens[] = $w;
        }
        return $tokens;
    }

    /** @param list<float> $a @param list<float> $b */
    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) return 0.0;
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
