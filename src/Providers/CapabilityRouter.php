<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;

/**
 * Routing decision returned by `CapabilityRouter::pick()` — a pure value
 * object describing which provider / model / region the caller should
 * use and which features will be activated.
 */
final class RoutingDecision
{
    /**
     * @param array<string, array<string, mixed>> $features Feature specs
     *            that WILL be applied (i.e. pruned to what the chosen
     *            candidate can honour — `required` features that couldn't
     *            be honoured would have caused `pick()` to throw earlier).
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $region,
        public readonly array $features,
    ) {}
}

/**
 * Capability-aware router — given a request spec (features, optional
 * provider/region preferences, optional required feature list), picks the
 * (provider, model, region) triple that best satisfies it.
 *
 * This is the **Phase 3 skeleton**: it can filter by required features,
 * honour an explicit provider/region pin, and prefer candidates that
 * support more of the requested (non-required) features natively. Rich
 * cost/latency ranking is deferred to Phase 4 — for now, ordering is
 * simply "more native capabilities first, then catalog order".
 *
 * Separation of concerns vs. existing `ModelRouter`:
 *   - `ModelRouter` (in `src/Optimization/`) handles cost / capacity /
 *     fallback cycling once a provider-model pair is known.
 *   - `CapabilityRouter` decides *which* pair to use when the caller
 *     didn't pin one.
 *
 * Usage (Phase 4 integration):
 *   $decision = CapabilityRouter::pick([
 *       'features' => ['thinking' => ['budget' => 4000, 'required' => true]],
 *       'region'   => 'intl',
 *   ]);
 *   $provider = ProviderRegistry::createWithRegion(
 *       $decision->provider,
 *       $decision->region ?? 'intl',
 *       ['model' => $decision->model],
 *   );
 *   $provider->chat($messages, $tools, $system, [
 *       'features' => $decision->features,
 *   ]);
 */
class CapabilityRouter
{
    /**
     * Resolve a request to a concrete (provider, model, region) triple.
     *
     * Request shape:
     *   [
     *     'features'       => ['<name>' => [...], ...],   // generic feature specs
     *     'provider'       => 'kimi'|null,                // optional pin
     *     'region'         => 'intl'|null,                // optional region preference
     *     'preferred'      => ['anthropic', 'kimi'],       // optional ranking hint
     *   ]
     *
     * @param array<string, mixed> $request
     *
     * @throws ProviderException when no candidate can satisfy every
     *         feature flagged `required: true`, or when the explicitly-
     *         pinned provider/model lacks a required feature.
     */
    public static function pick(array $request): RoutingDecision
    {
        $features = $request['features'] ?? [];
        $requiredFeatures = self::extractRequired($features);
        $preferredProvider = $request['provider'] ?? null;
        $preferredRegion = $request['region'] ?? null;
        $preferredList = $request['preferred'] ?? [];

        // 1. Candidate set = all models in the catalog, filtered by explicit pins.
        $candidates = [];
        foreach (ModelCatalog::all() as $id => $entry) {
            $provider = $entry['provider'] ?? null;
            if (! is_string($provider)) {
                continue;
            }
            if ($preferredProvider !== null && $provider !== $preferredProvider) {
                continue;
            }
            $candidates[] = ['id' => $id, 'provider' => $provider, 'entry' => $entry];
        }

        if ($candidates === []) {
            throw new ProviderException(
                $preferredProvider !== null
                    ? "No models registered for provider '{$preferredProvider}'"
                    : 'Model catalog is empty',
                $preferredProvider ?? 'router',
            );
        }

        // 2. Required-feature filter.
        $candidates = array_values(array_filter(
            $candidates,
            static function (array $c) use ($requiredFeatures): bool {
                $caps = ModelCatalog::capabilitiesFor($c['id']);
                foreach ($requiredFeatures as $feature) {
                    if (empty($caps[$feature])) {
                        return false;
                    }
                }
                return true;
            },
        ));

        if ($candidates === []) {
            $missing = implode(', ', $requiredFeatures);
            throw new ProviderException(
                "No candidate satisfies required features: {$missing}",
                $preferredProvider ?? 'router',
            );
        }

        // 3. Region filter — keep only candidates that either declare the
        //    requested region or don't declare any (assumed universal).
        if ($preferredRegion !== null) {
            $regionFiltered = array_values(array_filter(
                $candidates,
                static function (array $c) use ($preferredRegion): bool {
                    $regions = ModelCatalog::regionsFor($c['id']);
                    return $regions === [] || in_array($preferredRegion, $regions, true);
                },
            ));
            if ($regionFiltered !== []) {
                $candidates = $regionFiltered;
            }
            // else: keep original set — region preference is a hint, not a hard filter
        }

        // 4. Rank: explicit preferred list > count of native (non-required)
        //    features supported > catalog order.
        $nonRequiredFeatures = [];
        foreach ($features as $name => $spec) {
            if (is_string($name) && empty(($spec['required'] ?? false))) {
                $nonRequiredFeatures[] = $name;
            }
        }

        usort(
            $candidates,
            static function (array $a, array $b) use ($preferredList, $nonRequiredFeatures): int {
                $prefA = array_search($a['provider'], $preferredList, true);
                $prefB = array_search($b['provider'], $preferredList, true);
                $prefA = $prefA === false ? PHP_INT_MAX : $prefA;
                $prefB = $prefB === false ? PHP_INT_MAX : $prefB;
                if ($prefA !== $prefB) {
                    return $prefA <=> $prefB;
                }

                $supA = self::countSupported($a['id'], $nonRequiredFeatures);
                $supB = self::countSupported($b['id'], $nonRequiredFeatures);
                return $supB <=> $supA;
            },
        );

        $winner = $candidates[0];
        $regions = ModelCatalog::regionsFor($winner['id']);
        $chosenRegion = $preferredRegion
            ?? ($regions === [] ? null : $regions[0]);

        return new RoutingDecision(
            provider: $winner['provider'],
            model: $winner['id'],
            region: $chosenRegion,
            features: $features,
        );
    }

    /**
     * @param array<string, mixed> $features
     * @return array<int, string>
     */
    private static function extractRequired(array $features): array
    {
        $out = [];
        foreach ($features as $name => $spec) {
            if (! is_string($name)) {
                continue;
            }
            if (is_array($spec) && ! empty($spec['required'])) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * @param array<int, string> $featureNames
     */
    private static function countSupported(string $modelId, array $featureNames): int
    {
        $caps = ModelCatalog::capabilitiesFor($modelId);
        $n = 0;
        foreach ($featureNames as $name) {
            if (! empty($caps[$name])) {
                $n++;
            }
        }
        return $n;
    }
}
