<?php

declare(strict_types=1);

namespace SuperAgent\Evals;

/**
 * Load eval dimension definitions from disk.
 *
 * Dimension files are JSON. Resolution order (later wins):
 *   1. Bundled — `resources/evals/<name>.json` shipped with the package.
 *   2. User    — `~/.superagent/evals/<name>.json` for local overrides.
 *
 * Schema (each file is an object):
 *
 *   {
 *     "name":   "coding",
 *     "weight": 1.0,                // optional, future weighted aggregation
 *     "system": null,               // optional system prompt for every case
 *     "cases":  [
 *       {
 *         "id":      "fib-iter",    // short stable id (used in result rows)
 *         "prompt":  "...",         // user prompt (use \n for newlines)
 *         "system":  null,          // optional per-case system override
 *         "options": {},            // provider options (max_tokens, temperature, ...)
 *         "scorer":  "rule" | "judge",
 *         // rule scorer:
 *         "rule":    { "type": "contains|regex|exact|json|not_contains|any_of|all_of", ... }
 *         // judge scorer:
 *         "judge_criteria": "..."   // what the judge model should check
 *       }
 *     ]
 *   }
 */
final class DimensionLoader
{
    public function __construct(
        private ?string $bundledDir = null,
        private ?string $userDir = null,
    ) {
        $this->bundledDir ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'evals';
        $this->userDir    ??= self::defaultUserDir();
    }

    public static function defaultUserDir(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        return rtrim($home, "/\\") . DIRECTORY_SEPARATOR . '.superagent' . DIRECTORY_SEPARATOR . 'evals';
    }

    /** @return list<string> dimension names available across both dirs */
    public function available(): array
    {
        $names = [];
        foreach ([$this->bundledDir, $this->userDir] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
                $names[basename($file, '.json')] = true;
            }
        }
        $list = array_keys($names);
        sort($list);
        return $list;
    }

    /**
     * Load one dimension by name. User override wins over bundled.
     *
     * @return array{name:string, weight:float, system:?string, cases:list<array<string,mixed>>}
     */
    public function load(string $name): array
    {
        $userPath = $this->userDir . DIRECTORY_SEPARATOR . $name . '.json';
        $bundledPath = $this->bundledDir . DIRECTORY_SEPARATOR . $name . '.json';

        $path = is_readable($userPath) ? $userPath : (is_readable($bundledPath) ? $bundledPath : null);
        if ($path === null) {
            throw new \RuntimeException("Eval dimension not found: {$name}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read eval dimension file: {$path}");
        }
        $def = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf(
                'Eval dimension JSON parse error (%s): %s',
                $path,
                json_last_error_msg(),
            ));
        }
        if (! is_array($def) || empty($def['cases']) || ! is_array($def['cases'])) {
            throw new \RuntimeException("Eval dimension file is malformed: {$path}");
        }

        return [
            'name'   => (string) ($def['name'] ?? $name),
            'weight' => (float) ($def['weight'] ?? 1.0),
            'system' => isset($def['system']) ? (string) $def['system'] : null,
            'cases'  => array_values($def['cases']),
        ];
    }
}
