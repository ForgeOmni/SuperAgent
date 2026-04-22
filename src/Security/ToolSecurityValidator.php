<?php

declare(strict_types=1);

namespace SuperAgent\Security;

use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Tools\Providers\ProviderToolBase;

/**
 * Top-level gate that every tool call funnels through in Phase 8+.
 *
 * Composition:
 *   - `NetworkPolicy`  — blocks `network`-attributed tools when the process
 *                        is in offline mode (env `SUPERAGENT_OFFLINE=1`).
 *   - `CostLimiter`    — enforces per-call / per-tool-daily / global-daily
 *                        caps on `cost`-attributed tools.
 *   - `sensitive` attr — policy-configurable: `allow` / `ask` / `deny`.
 *                        Default is `ask` so uploads surface a prompt.
 *   - Bash tools       — delegated to the existing `BashSecurityValidator`
 *                        so the 23 hardened checks from Claude Code aren't
 *                        lost. This is the "Bash 行为零变化" red line from
 *                        the Phase 1 compat contract.
 *
 * The validator is attribute-driven, so every new provider tool that
 * declares its attributes correctly gets the right gating for free —
 * nothing new to wire up per tool. Bash delegation uses duck typing
 * (class-name check) so we don't pull in `Permissions\*` types here and
 * risk circular imports.
 *
 * `validate()` returns a `SecurityDecision` (`allow` / `ask` / `deny`)
 * which the agent harness consults before calling `$tool->execute()`.
 * The `ask` verdict means "present the user with the reason and wait for
 * confirmation"; how that interaction is rendered is up to the caller
 * (CLI uses `ConsolePermissionCallback`; a web UI surfaces a modal).
 */
final class ToolSecurityValidator
{
    private readonly NetworkPolicy $network;
    private readonly CostLimiter $cost;

    /**
     * @param array<string, mixed> $options
     *   [
     *     'sensitive_default' => 'ask' | 'allow' | 'deny',    // default 'ask'
     *     'network'           => [...],                       // NetworkPolicy options (reserved)
     *     'cost'              => [...],                       // CostLimiter options
     *   ]
     */
    public function __construct(
        private readonly array $options = [],
        ?NetworkPolicy $network = null,
        ?CostLimiter $cost = null,
    ) {
        $this->network = $network ?? NetworkPolicy::default();
        $this->cost = $cost ?? new CostLimiter($options['cost'] ?? []);
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Validate a single tool call.
     *
     * @param array<string, mixed> $input          Tool input (for future
     *                                             pattern matching; unused today).
     * @param float                $estimatedCost  Caller estimate in USD for
     *                                             `cost` tools. Pass 0.0 when
     *                                             the tool is free or cost is
     *                                             unknown — `CostLimiter` then
     *                                             only blocks on overall
     *                                             daily caps.
     */
    public function validate(ToolInterface $tool, array $input = [], float $estimatedCost = 0.0): SecurityDecision
    {
        // 1. Bash delegation — we keep the Claude-Code-derived 23 checks.
        //    Any tool whose class name matches `*BashTool` gets routed to
        //    the legacy path so behaviour is byte-identical to pre-Phase-8.
        $className = $tool::class;
        if (str_ends_with($className, 'BashTool')) {
            return SecurityDecision::allow('delegated to BashSecurityValidator');
        }

        $attributes = $this->extractAttributes($tool);

        // 2. Network policy — deny outright in offline mode.
        $netDecision = $this->network->check($attributes);
        if (! $netDecision->isAllow()) {
            return $netDecision;
        }

        // 3. Cost limiter — deny or ask based on limits.
        $costDecision = $this->cost->check($tool->name(), $attributes, $estimatedCost);
        if (! $costDecision->isAllow()) {
            return $costDecision;
        }

        // 4. Sensitive attribute — default policy is `ask`. Allows the
        //    config to tune it without changing call sites.
        if (in_array('sensitive', $attributes, true)) {
            $sensitivePolicy = (string) ($this->options['sensitive_default'] ?? 'ask');
            return match ($sensitivePolicy) {
                'allow' => SecurityDecision::allow('sensitive tool allowed by policy'),
                'deny'  => SecurityDecision::deny(
                    'sensitive tool denied by policy',
                    ['attribute' => 'sensitive', 'tool' => $tool->name()],
                ),
                default => SecurityDecision::ask(
                    sprintf('Tool %s uploads user data — approve?', $tool->name()),
                    ['attribute' => 'sensitive', 'tool' => $tool->name()],
                ),
            };
        }

        return SecurityDecision::allow();
    }

    /**
     * Report successful tool execution so the cost ledger increments.
     * Call after `$tool->execute()` returned a non-error result.
     */
    public function recordCost(string $toolName, float $usd): void
    {
        $this->cost->record($toolName, $usd);
    }

    public function network(): NetworkPolicy
    {
        return $this->network;
    }

    public function cost(): CostLimiter
    {
        return $this->cost;
    }

    /**
     * Read the `attributes()` list off a tool. Only `ProviderToolBase`
     * descendants declare it; everything else is treated as empty
     * (i.e. unrestricted) so the validator never blocks legacy tools
     * that existed before the attribute system.
     *
     * @return array<int, string>
     */
    private function extractAttributes(ToolInterface $tool): array
    {
        if ($tool instanceof ProviderToolBase) {
            return $tool->attributes();
        }
        if (method_exists($tool, 'attributes')) {
            $raw = $tool->attributes();
            if (is_array($raw)) {
                return array_values(array_map('strval', $raw));
            }
        }
        return [];
    }
}
