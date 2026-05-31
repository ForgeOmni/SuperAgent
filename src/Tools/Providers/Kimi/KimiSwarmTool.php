<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

use SuperAgent\Providers\Capabilities\SupportsSwarm;
use SuperAgent\Providers\JobStatus;
use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Dispatch a Kimi Agent Swarm job as a regular SuperAgent Tool.
 *
 * EXPERIMENTAL / OPT-IN. Moonshot has *announced* Agent Swarm but has not
 * published a public Swarm REST spec, and the official kimi-code client
 * ships no swarm endpoint — its parallelism comes from local `coder` /
 * `explore` / `plan` subagents instead. To avoid silently calling a
 * non-existent endpoint, this tool is disabled unless the operator opts in
 * by setting `SUPERAGENT_KIMI_SWARM_ENABLED` (point it at a preview/private
 * endpoint). For local multi-agent orchestration today, prefer SuperAgent's
 * own Squad / Swarm / Coordinator.
 *
 * Usage modes (when enabled):
 *   - `wait = true`  (default): sync-wait on the job via `AsyncCapable`
 *     and return the final deliverable in the tool result.
 *   - `wait = false`: return a job handle immediately so the agent loop
 *     can interleave other work and poll externally later.
 *
 * The REST surface this tool hits is provisional — see
 * `KimiProvider::submitSwarm()`. The Tool API (name, inputSchema, return
 * shape) is stable regardless, so wiring the real spec in later is a point
 * change.
 */
class KimiSwarmTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'kimi_swarm';
    }

    public function description(): string
    {
        return 'Submit a complex task to Kimi Agent Swarm and return the '
            . 'assembled deliverable. EXPERIMENTAL: Moonshot has not published '
            . 'the public Swarm REST spec, so this is disabled unless '
            . 'SUPERAGENT_KIMI_SWARM_ENABLED is set (against a preview/private '
            . 'endpoint). For local multi-agent orchestration, prefer '
            . "SuperAgent's Squad/Swarm.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'High-level task description for the swarm.',
                ],
                'max_sub_agents' => [
                    'type' => 'integer',
                    'description' => 'Cap on concurrent sub-agents (upstream default: 300).',
                ],
                'max_steps' => [
                    'type' => 'integer',
                    'description' => 'Cap on coordinated steps (upstream default: 4000).',
                ],
                'deliverable' => [
                    'type' => 'string',
                    'description' => 'Optional delivery-format hint ("markdown", "deck", "website", …).',
                ],
                'skills' => [
                    'type' => 'array',
                    'description' => 'Previously-registered Kimi skill ids to bias the swarm.',
                    'items' => ['type' => 'string'],
                ],
                'wait' => [
                    'type' => 'boolean',
                    'description' => 'If true (default) sync-wait; if false, return job_id immediately.',
                    'default' => true,
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'description' => 'Max sync-wait seconds (default 900).',
                    'default' => 900,
                ],
            ],
            'required' => ['prompt'],
        ];
    }

    public function attributes(): array
    {
        return ['network', 'cost'];
    }

    /**
     * Swarms are generally read-only from SuperAgent's perspective — they
     * produce deliverables but don't mutate our local state. The `cost`
     * attribute is the real guard; approvals go via `ToolSecurityValidator`.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $input): ToolResult
    {
        return $this->safeInvoke(function () use ($input) {
            $prompt = $input['prompt'] ?? null;
            if (! is_string($prompt) || trim($prompt) === '') {
                return ToolResult::error('prompt is required');
            }

            if (! self::swarmEnabled()) {
                return ToolResult::error(
                    'Kimi Agent Swarm is not generally available: Moonshot has '
                    . 'not published the public Swarm REST spec, so this tool is '
                    . 'disabled by default to avoid calling a non-existent '
                    . 'endpoint. Set SUPERAGENT_KIMI_SWARM_ENABLED=1 to opt in '
                    . 'against a preview/private endpoint, or use SuperAgent\'s '
                    . 'local Squad/Swarm orchestration instead.',
                );
            }

            if (! $this->provider instanceof SupportsSwarm) {
                return ToolResult::error(
                    sprintf(
                        'provider %s does not implement SupportsSwarm',
                        $this->provider->name(),
                    ),
                );
            }

            $swarmOpts = array_intersect_key($input, array_flip([
                'max_sub_agents', 'max_steps', 'deliverable', 'skills',
            ]));

            $handle = $this->provider->submitSwarm($prompt, $swarmOpts);

            if (! ($input['wait'] ?? true)) {
                return ToolResult::success([
                    'job_id' => $handle->jobId,
                    'provider' => $handle->provider,
                    'kind' => $handle->kind,
                    'status' => 'submitted',
                ]);
            }

            $this->pollUntilDone(function () use ($handle): array {
                $status = $this->provider->poll($handle);
                return [
                    'status' => match ($status) {
                        JobStatus::Done => 'done',
                        JobStatus::Failed, JobStatus::Canceled => 'failed',
                        default => 'running',
                    },
                ];
            }, (int) ($input['timeout_seconds'] ?? 900), 3.0);

            $deliverable = $this->provider->fetch($handle);

            return ToolResult::success([
                'job_id' => $handle->jobId,
                'status' => 'completed',
                'deliverable' => $deliverable,
            ]);
        });
    }

    /**
     * Opt-in gate. Off by default because Moonshot's public Swarm REST spec
     * is unpublished and the production endpoint does not exist; enabling it
     * is an explicit operator choice (e.g. a private/preview endpoint).
     */
    private static function swarmEnabled(): bool
    {
        $v = getenv('SUPERAGENT_KIMI_SWARM_ENABLED');
        if ($v === false) {
            return false;
        }
        $v = strtolower(trim($v));
        return $v !== '' && $v !== '0' && $v !== 'false' && $v !== 'no';
    }
}
