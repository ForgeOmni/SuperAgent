<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Providers\SwarmRouter;

/**
 * `superagent swarm` — plan a multi-agent "swarm" execution.
 *
 * Flags:
 *   --provider <name>        Pin to kimi | minimax | (any)
 *   --strategy <s>           Force native_swarm | agent_teams | local_swarm
 *   --max-sub-agents <N>     Upgrade to Kimi native swarm at ≥20
 *   --role <name:desc>       Repeatable; triggers MiniMax agent_teams
 *   --json                   Emit the plan as JSON instead of human text
 *
 * This command only **plans** right now — it tells you which strategy
 * would be selected and why. Phase 7 lands the router + provider surface;
 * the execution path for each strategy wires up in a subsequent phase
 * once all three targets have stable backends. Running this command
 * today is a useful dry-run when designing a swarm-backed workflow.
 */
class SwarmCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['swarm_args'] ?? [];

        [$prompt, $request] = $this->parse($args, $renderer);
        if ($prompt === null) {
            return 2;
        }

        $request['prompt'] = $prompt;

        try {
            $plan = SwarmRouter::plan($request);
        } catch (\InvalidArgumentException $e) {
            $renderer->error($e->getMessage());
            return 2;
        }

        if (! empty($request['_json'])) {
            $renderer->line(json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        $renderer->info('Swarm plan:');
        $renderer->line("  Strategy:   {$plan->strategy}");
        $renderer->line('  Provider:   ' . ($plan->provider ?? '(local)'));
        $renderer->line("  Rationale:  {$plan->rationale}");
        if (! empty($request['roles'])) {
            $renderer->line('  Roles:      ' . count($request['roles']));
        }
        $renderer->newLine();

        if (! empty($request['_plan_only'])) {
            return 0;
        }

        // Execute the plan — three code paths, one per strategy.
        return match ($plan->strategy) {
            'native_swarm' => $this->executeNativeSwarm($renderer, $plan),
            'agent_teams'  => $this->executeAgentTeams($renderer, $plan),
            'local_swarm'  => $this->executeLocalSwarm($renderer, $plan),
            default        => $this->unknownStrategy($renderer, $plan->strategy),
        };
    }

    /**
     * `native_swarm` → Kimi Agent Swarm via `KimiSwarmTool`. Requires
     * `KIMI_API_KEY` in the environment. The tool itself handles the
     * submit → poll → fetch loop (see `KimiSwarmTool`).
     */
    private function executeNativeSwarm(\SuperAgent\CLI\Terminal\Renderer $renderer, \SuperAgent\Providers\SwarmPlan $plan): int
    {
        if (! getenv('KIMI_API_KEY') && ! getenv('MOONSHOT_API_KEY')) {
            $renderer->error('native_swarm strategy requires KIMI_API_KEY in the environment');
            return 1;
        }

        try {
            $provider = \SuperAgent\Providers\ProviderRegistry::createFromEnv('kimi');
            $tool = new \SuperAgent\Tools\Providers\Kimi\KimiSwarmTool($provider);
            $renderer->info('Submitting to Kimi Agent Swarm...');
            $result = $tool->execute(array_filter([
                'prompt' => $plan->prompt,
                'max_sub_agents' => $plan->options['max_sub_agents'] ?? null,
                'timeout_seconds' => $plan->options['timeout_seconds'] ?? 900,
                'wait' => ! ($plan->options['async'] ?? false),
            ], static fn ($v) => $v !== null));

            if ($result->isError) {
                $renderer->error($result->contentAsString());
                return 1;
            }
            $renderer->success('Swarm completed.');
            $renderer->line(is_string($result->content) ? $result->content : json_encode($result->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        } catch (\Throwable $e) {
            $renderer->error('native_swarm failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * `agent_teams` → MiniMax M2.7 chat call with the
     * `agent_teams` feature — `FeatureDispatcher` injects the
     * scaffolding via `AgentTeamsAdapter`.
     */
    private function executeAgentTeams(\SuperAgent\CLI\Terminal\Renderer $renderer, \SuperAgent\Providers\SwarmPlan $plan): int
    {
        if (! getenv('MINIMAX_API_KEY')) {
            $renderer->error('agent_teams strategy requires MINIMAX_API_KEY in the environment');
            return 1;
        }

        try {
            $provider = \SuperAgent\Providers\ProviderRegistry::createFromEnv('minimax');
            $messages = [new \SuperAgent\Messages\UserMessage($plan->prompt)];
            $options = [
                'features' => [
                    'agent_teams' => array_filter([
                        'roles' => $plan->options['roles'] ?? null,
                        'objective' => $plan->options['objective'] ?? $plan->prompt,
                    ], static fn ($v) => $v !== null),
                ],
            ];

            $renderer->info('Running MiniMax Agent Teams...');
            foreach ($provider->chat($messages, [], null, $options) as $chunk) {
                foreach ($chunk->content as $block) {
                    if ($block->type === 'text') {
                        $renderer->line($block->text);
                    }
                }
            }
            return 0;
        } catch (\Throwable $e) {
            $renderer->error('agent_teams failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * `local_swarm` → SuperAgent's in-process swarm (`src/Swarm/`).
     *
     * Stub: wiring the `Team` / `ParallelAgentCoordinator` / backend
     * selection requires caller-supplied AgentDefinition instances and
     * a configured AgentManager — non-trivial to do sensibly from a
     * single prompt line. This command hands the user the plan and
     * points them at the programmatic API.
     */
    private function executeLocalSwarm(\SuperAgent\CLI\Terminal\Renderer $renderer, \SuperAgent\Providers\SwarmPlan $plan): int
    {
        $renderer->info('local_swarm strategy selected.');
        $renderer->hint(
            'Direct CLI execution of local swarms is not supported — '
            . 'wire it programmatically via src/Swarm/Team + AgentPool + ParallelAgentCoordinator. '
            . 'The --json output above contains the plan your code should consume.',
        );
        return 0;
    }

    private function unknownStrategy(\SuperAgent\CLI\Terminal\Renderer $renderer, string $strategy): int
    {
        $renderer->error("Unknown strategy: {$strategy}");
        return 2;
    }

    /**
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function parse(array $args, Renderer $renderer): array
    {
        if ($args === []) {
            $renderer->error('Usage: superagent swarm <prompt> [--provider kimi|minimax] [--strategy native_swarm|agent_teams|local_swarm] [--max-sub-agents N] [--role name:desc]');
            return [null, []];
        }

        $request = [];
        $promptParts = [];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            switch ($arg) {
                case '--provider':
                    $request['provider'] = $args[++$i] ?? null;
                    break;
                case '--strategy':
                    $request['strategy'] = $args[++$i] ?? null;
                    break;
                case '--max-sub-agents':
                    $request['max_sub_agents'] = (int) ($args[++$i] ?? 0);
                    break;
                case '--role':
                    $val = $args[++$i] ?? '';
                    [$name, $desc] = array_pad(explode(':', $val, 2), 2, '');
                    $request['roles'] ??= [];
                    $request['roles'][] = ['name' => trim($name), 'description' => trim($desc)];
                    break;
                case '--json':
                    $request['_json'] = true;
                    $request['_plan_only'] = true;  // --json is dry-run: emit plan only, no execution
                    break;
                case '--plan-only':
                case '--dry-run':
                    $request['_plan_only'] = true;
                    break;
                default:
                    $promptParts[] = $arg;
            }
        }

        $prompt = trim(implode(' ', $promptParts));
        return [$prompt === '' ? null : $prompt, $request];
    }
}
