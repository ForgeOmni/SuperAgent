<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

use SuperAgent\Providers\Capabilities\SupportsSwarm;
use SuperAgent\Providers\JobStatus;
use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Dispatch a Kimi K2.6 Agent Swarm job as a regular SuperAgent Tool.
 *
 * Any main brain (Claude, GPT, Gemini, Qwen, …) can call this tool to
 * hand a complex task off to Kimi's 300-sub-agent / 4 000-step coordinator
 * and get back the assembled deliverable — the defining Phase 7 feature.
 *
 * Usage modes:
 *   - `wait = true`  (default): sync-wait on the job via `AsyncCapable`
 *     and return the final deliverable in the tool result.
 *   - `wait = false`: return a job handle immediately so the agent loop
 *     can interleave other work and poll externally later.
 *
 * Schema caveat: the swarm REST endpoints this tool hits are provisional
 * — see `KimiProvider::submitSwarm()` for the REST surface that needs
 * validating once Moonshot publishes the official spec. The Tool API
 * (name, inputSchema, return shape) is stable regardless.
 */
class KimiSwarmTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'kimi_swarm';
    }

    public function description(): string
    {
        return 'Submit a complex task to Kimi K2.6 Agent Swarm — 300 '
            . 'sub-agents over up to 4 000 coordinated steps — and return '
            . 'the assembled deliverable. Good fit for tasks a single LLM '
            . 'call cannot handle (multi-file refactors, research + write, '
            . 'deck + doc generation).';
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
}
