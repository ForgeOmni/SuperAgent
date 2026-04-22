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
        $renderer->hint('Execution wiring lands in a follow-up phase; for now this command only plans.');
        return 0;
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
                    break;
                default:
                    $promptParts[] = $arg;
            }
        }

        $prompt = trim(implode(' ', $promptParts));
        return [$prompt === '' ? null : $prompt, $request];
    }
}
