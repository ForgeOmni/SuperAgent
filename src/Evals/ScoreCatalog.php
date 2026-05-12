<?php

declare(strict_types=1);

namespace SuperAgent\Evals;

/**
 * Read/write `~/.superagent/model_scores.json` — the eval-derived scoreboard
 * that `AutoModelStrategy` (and friends) can consult to pick a model by
 * dimension score instead of by hand-tuned heuristics.
 *
 * The file is the single source of truth: each (model, dimension) pair is
 * overwritten on every eval run, so the disk state always reflects the most
 * recent measurement. No history kept on purpose — eval results decay fast
 * (model versions change weekly), and the routing layer only needs "current".
 *
 * Schema (v1):
 * {
 *   "_meta": { "schema_version": 1, "updated_at": "2026-05-12T10:00:00+00:00" },
 *   "models": {
 *     "<model-id>": {
 *       "provider": "anthropic",
 *       "dims": {
 *         "<dim>": {
 *           "score": 0.92,       // 0..1 normalized
 *           "cases": 10,
 *           "passed": 9,
 *           "latency_ms": 1840,  // avg per case
 *           "cost_usd": 0.0031,  // total for this dim run
 *           "ran_at": "2026-05-12T10:00:00+00:00"
 *         }
 *       },
 *       "overall": 0.90          // simple mean across known dims
 *     }
 *   }
 * }
 */
final class ScoreCatalog
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private string $path)
    {
    }

    public static function default(): self
    {
        return new self(self::defaultPath());
    }

    public static function defaultPath(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        return rtrim($home, "/\\") . DIRECTORY_SEPARATOR . '.superagent' . DIRECTORY_SEPARATOR . 'model_scores.json';
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        if (! is_readable($this->path)) {
            return $this->blank();
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return $this->blank();
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['models']) || ! is_array($decoded['models'])) {
            return $this->blank();
        }
        return $decoded;
    }

    /**
     * Merge a single (model, dim) result into the catalog and persist.
     *
     * @param array{score:float,cases:int,passed:int,latency_ms:int,cost_usd:float} $dimResult
     */
    public function upsert(string $modelId, string $provider, string $dim, array $dimResult): void
    {
        $catalog = $this->load();
        if (! isset($catalog['models'][$modelId]) || ! is_array($catalog['models'][$modelId])) {
            $catalog['models'][$modelId] = ['provider' => $provider, 'dims' => []];
        }
        $catalog['models'][$modelId]['provider'] = $provider;
        $catalog['models'][$modelId]['dims'][$dim] = [
            'score'      => round((float) $dimResult['score'], 4),
            'cases'      => (int) $dimResult['cases'],
            'passed'     => (int) $dimResult['passed'],
            'latency_ms' => (int) $dimResult['latency_ms'],
            'cost_usd'   => round((float) $dimResult['cost_usd'], 6),
            'ran_at'     => date('c'),
        ];

        $dims = $catalog['models'][$modelId]['dims'];
        $sum = 0.0;
        $n = 0;
        foreach ($dims as $d) {
            if (isset($d['score'])) {
                $sum += (float) $d['score'];
                $n++;
            }
        }
        $catalog['models'][$modelId]['overall'] = $n > 0 ? round($sum / $n, 4) : 0.0;

        $catalog['_meta'] = [
            'schema_version' => self::SCHEMA_VERSION,
            'updated_at'     => date('c'),
        ];

        $this->save($catalog);
    }

    /**
     * Best model id for a given dimension by raw score.
     * Returns null when no model has been evaluated on this dim.
     */
    public function bestModelFor(string $dim): ?string
    {
        $best = null;
        $bestScore = -1.0;
        foreach ($this->load()['models'] ?? [] as $id => $entry) {
            $score = $entry['dims'][$dim]['score'] ?? null;
            if (! is_numeric($score)) {
                continue;
            }
            if ((float) $score > $bestScore) {
                $bestScore = (float) $score;
                $best = (string) $id;
            }
        }
        return $best;
    }

    public function scoreFor(string $modelId, string $dim): ?float
    {
        $score = $this->load()['models'][$modelId]['dims'][$dim]['score'] ?? null;
        return is_numeric($score) ? (float) $score : null;
    }

    /**
     * Best model by `overall` (mean across all evaluated dims). Used as the
     * "brain" model in smart-mode planning / merging — we want the most
     * generally capable model on the table, not one that just happens to ace
     * a single dim.
     */
    public function bestByOverall(): ?string
    {
        $best = null;
        $bestScore = -1.0;
        foreach ($this->load()['models'] ?? [] as $id => $entry) {
            $score = $entry['overall'] ?? null;
            if (! is_numeric($score)) {
                continue;
            }
            if ((float) $score > $bestScore) {
                $bestScore = (float) $score;
                $best = (string) $id;
            }
        }
        return $best;
    }

    /**
     * Cheapest model that scored at or above `$threshold` on the given dim.
     * Cost is taken from `ModelCatalog::pricing()` — `input + output` price per
     * million tokens summed (rough proxy for "how expensive is one call").
     *
     * Used by smart-mode to route "easy" subtasks: we don't need the strongest
     * model, just one that's known to pass the bar for that capability at the
     * lowest cost. Returns null if no scored model meets the threshold.
     */
    public function cheapestPassingFor(string $dim, float $threshold = 0.6): ?string
    {
        $candidates = [];
        foreach ($this->load()['models'] ?? [] as $id => $entry) {
            $score = $entry['dims'][$dim]['score'] ?? null;
            if (! is_numeric($score) || (float) $score < $threshold) {
                continue;
            }
            $candidates[(string) $id] = (float) $score;
        }
        if (empty($candidates)) {
            return null;
        }

        $best = null;
        $bestCost = PHP_FLOAT_MAX;
        foreach ($candidates as $id => $_score) {
            $pricing = \SuperAgent\Providers\ModelCatalog::pricing($id);
            // Models without pricing in the catalog are treated as "free" but
            // sorted last — we prefer a model with known low price over one
            // whose price we can't verify.
            $cost = $pricing === null
                ? PHP_FLOAT_MAX - 1
                : ((float) $pricing['input'] + (float) $pricing['output']);
            if ($cost < $bestCost) {
                $bestCost = $cost;
                $best = $id;
            }
        }
        return $best;
    }

    /** @return array<string, mixed> */
    private function blank(): array
    {
        return [
            '_meta'  => ['schema_version' => self::SCHEMA_VERSION, 'updated_at' => null],
            'models' => [],
        ];
    }

    /** @param array<string, mixed> $catalog */
    private function save(array $catalog): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create score catalog dir: {$dir}");
        }
        $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode score catalog');
        }
        $tmp = $this->path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("Cannot write score catalog: {$this->path}");
        }
        @rename($tmp, $this->path);
    }
}
