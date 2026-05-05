<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Recursion guardrail for sub-agent spawning. Mirrors codex's
 * `agents.max_depth` config — every time an agent calls the `agent`
 * tool to start a child, we walk the depth counter up by one. If the
 * counter is about to exceed the configured cap, the spawn is
 * refused.
 *
 * Why this matters: a buggy or adversarial prompt can convince an
 * agent to spawn another agent that spawns another agent, ad
 * infinitum. Without a hard cap, a single misfire can fan out into
 * thousands of OS processes (each running a full LLM client) before
 * the user notices the bill.
 *
 * Implementation: depth is tracked through an environment variable
 * (`SUPERAGENT_AGENT_DEPTH`) so it survives child process spawning
 * via `proc_open` / `Symfony\Process` without requiring an in-PHP
 * shared store. The parent stamps its child's incremented depth via
 * `forChild()`; the child reads back its own depth on startup with
 * `current()`. Default cap is 5 — same as codex's default.
 *
 * Hosts can override the cap via:
 *
 *   ENV:    SUPERAGENT_MAX_AGENT_DEPTH=8
 *   PHP:    AgentDepthGuard::setMax(8)
 *   Laravel config: superagent.agents.max_depth (when running under SuperAICore)
 *
 * The cap is enforced at the spawn site, not in the agent loop —
 * that means the topmost agent (depth=0) gets the full budget; only
 * the chain length is limited.
 */
final class AgentDepthGuard
{
    private const ENV_DEPTH    = 'SUPERAGENT_AGENT_DEPTH';
    private const ENV_MAX      = 'SUPERAGENT_MAX_AGENT_DEPTH';
    private const DEFAULT_MAX  = 5;

    private static ?int $maxOverride = null;

    /**
     * Current depth of the running agent. Returns 0 for the user's
     * direct invocation; sub-agents see 1, 2, 3, … as they go down
     * the chain.
     */
    public static function current(): int
    {
        $raw = $_SERVER[self::ENV_DEPTH]
            ?? $_ENV[self::ENV_DEPTH]
            ?? getenv(self::ENV_DEPTH)
            ?? '0';
        $depth = (int) $raw;
        return max(0, $depth);
    }

    /**
     * Resolve the active cap. Precedence: explicit override > env >
     * Laravel config (when superagent.agents.max_depth is set) >
     * default.
     */
    public static function max(): int
    {
        if (self::$maxOverride !== null) {
            return self::$maxOverride;
        }
        $env = $_SERVER[self::ENV_MAX]
            ?? $_ENV[self::ENV_MAX]
            ?? getenv(self::ENV_MAX);
        if (is_string($env) && $env !== '' && ctype_digit($env)) {
            return max(1, (int) $env);
        }
        if (function_exists('config')) {
            try {
                $configured = config('superagent.agents.max_depth');
                if (is_int($configured) && $configured > 0) {
                    return $configured;
                }
            } catch (\Throwable) {
                // Container not bound (CLI / pre-boot / unit test) —
                // fall through to the static default.
            }
        }
        return self::DEFAULT_MAX;
    }

    public static function setMax(?int $max): void
    {
        self::$maxOverride = $max !== null ? max(1, $max) : null;
    }

    /**
     * Throw if the current process is already at the cap and a child
     * spawn would push past it. Call this from the spawn site BEFORE
     * launching the subprocess.
     */
    public static function check(): void
    {
        $current = self::current();
        $max = self::max();
        if ($current + 1 > $max) {
            throw new AgentDepthExceededException(
                "Sub-agent spawn refused: depth limit reached (current={$current}, max={$max}). "
                . "Set SUPERAGENT_MAX_AGENT_DEPTH or superagent.agents.max_depth to raise the cap, "
                . "or restructure the task so the parent agent does the work directly."
            );
        }
    }

    /**
     * Build the env-var bag to pass to a freshly spawned child. The
     * child's depth is parent_depth + 1. Hosts merge this into their
     * existing env array before invoking proc_open / Symfony\Process.
     *
     * @return array<string, string>
     */
    public static function forChild(): array
    {
        return [
            self::ENV_DEPTH => (string) (self::current() + 1),
        ];
    }
}
