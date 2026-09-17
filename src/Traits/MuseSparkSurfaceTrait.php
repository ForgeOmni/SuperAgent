<?php

declare(strict_types=1);

namespace SuperAgent\Traits;

/**
 * The parts of Meta's Muse Spark surface that are identical whichever
 * protocol you speak it over — shared by {@see \SuperAgent\Providers\MetaProvider}
 * (Chat Completions) and {@see \SuperAgent\Providers\MetaResponsesProvider}
 * (Responses).
 *
 * Both protocols take the same bearer token, front the same models and
 * bill identically; what they share here is the effort dial's shape and
 * the key lookup.
 */
trait MuseSparkSurfaceTrait
{
    /**
     * Normalise a cross-provider effort tier onto what Muse Spark accepts.
     *
     * Muse Spark reasons unconditionally — there is no `none` tier on this
     * family and sending one is a 400 — so anything meaning "don't think"
     * floors at `minimal`, the cheapest tier that exists. `max` is
     * Standard-tier `muse-spark-1.3` only ({@see modelSupportsMaxEffort()}).
     *
     * Returns null for a tier we don't recognise, so callers can decide
     * between omitting the field and failing.
     */
    protected function museSparkEffortTier(string $effort, string $model): ?string
    {
        return match (strtolower(trim($effort))) {
            // No off switch on this family — floor at the cheapest tier.
            'off', 'disabled', 'none', 'false', 'minimal' => 'minimal',
            'low' => 'low',
            'medium', 'mid', '' => 'medium',
            'high' => 'high',
            'xhigh' => 'xhigh',
            'max', 'highest' => $this->modelSupportsMaxEffort($model) ? 'max' : 'xhigh',
            default => null,
        };
    }

    /**
     * `max` ("extended reasoning") is documented for Muse Spark 1.3 on the
     * **Standard tier only**: 1.1 and 1.2 stop at `xhigh`, and so does
     * every `-contributor` id — `reasoning.effort: "max"` against
     * `muse-spark-1.3-contributor` comes back as a 400
     * `invalid_request_error`, while `xhigh` succeeds.
     */
    protected function modelSupportsMaxEffort(string $model): bool
    {
        $id = strtolower($model);

        if (! str_starts_with($id, 'muse-spark-')) {
            // Unknown id — assume the current surface rather than silently
            // downgrading a model we don't know about.
            return true;
        }

        if (str_contains($id, '-contributor')) {
            return false;
        }

        return ! str_starts_with($id, 'muse-spark-1.1')
            && ! str_starts_with($id, 'muse-spark-1.2');
    }

    /**
     * Bearer resolution shared by both Meta routes: an explicit
     * `api_key` wins, then `META_API_KEY` (our naming), then
     * `MODEL_API_KEY` (the name Meta's own docs and quickstarts use).
     *
     * @param array<string, mixed> $config
     */
    protected function resolveMetaBearer(array $config): ?string
    {
        $explicit = $config['api_key'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        foreach (['META_API_KEY', 'MODEL_API_KEY'] as $var) {
            $value = $_ENV[$var] ?? getenv($var) ?: null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function missingMetaBearerMessage(): string
    {
        return 'Meta Model API key is required — pass api_key, or set META_API_KEY '
            . '(or MODEL_API_KEY, the name Meta\'s own docs use). Keys are issued in '
            . 'the Meta Developer Console.';
    }
}
