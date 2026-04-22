<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsSwarm;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * Moonshot Kimi — Platform API.
 *
 * Native provider for the Kimi K2.6 family (and predecessors). Speaks the
 * OpenAI `/chat/completions` wire shape at the base level; vendor-specific
 * capabilities (Agent Swarm, Skills, Files/extract, Batches, context caching)
 * land in later phases as separate Capability interface implementations.
 *
 * Regions:
 *   - `intl` (default) → api.moonshot.ai  — international
 *   - `cn`             → api.moonshot.cn  — China mainland
 *
 * API keys are host-bound: an intl key cannot call the cn endpoint and vice
 * versa. SuperAgent's CredentialPool carries an optional `region` tag so a
 * single account can safely pool both key families.
 */
class KimiProvider extends ChatCompletionsProvider implements SupportsSwarm, SupportsThinking
{
    /**
     * Kimi does not expose a request-field for thinking — it uses a
     * dedicated model variant (`kimi-k2-thinking-preview`). Returning a
     * `model` override here tells the caller (via FeatureDispatcher deep-
     * merge) to swap in the thinking variant for this turn. `$budgetTokens`
     * is advisory — Kimi's thinking model decides its own budget.
     */
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        return ['model' => 'kimi-k2-thinking-preview'];
    }

    /**
     * Submit a Kimi K2.6 Agent Swarm job.
     *
     * NOTE: Moonshot has announced Agent Swarm (300 sub-agents / 4000 step
     * coordination) but the public REST schema is not yet published as of
     * the Phase-7 implementation date. The endpoint / body shape used here
     * is a best-effort structural placeholder — when Moonshot publishes the
     * official spec, the path + body keys below need validation and likely
     * minor adjustment. The callers (KimiSwarmTool, SwarmRouter) are
     * architecturally correct and will not need to change.
     *
     * Provisional endpoints (mark UNVERIFIED):
     *   POST  /v1/swarm/jobs                 — submit job
     *   GET   /v1/swarm/jobs/{id}            — poll status
     *   POST  /v1/swarm/jobs/{id}/cancel     — cancel
     *
     * @param array<string, mixed> $opts
     */
    public function submitSwarm(string $prompt, array $opts = []): JobHandle
    {
        $body = array_filter([
            'prompt' => $prompt,
            'model' => $opts['model'] ?? $this->model,
            'max_sub_agents' => $opts['max_sub_agents'] ?? null,
            'max_steps' => $opts['max_steps'] ?? null,
            'deliverable' => $opts['deliverable'] ?? null,
            'skills' => $opts['skills'] ?? null,
        ], static fn ($v) => $v !== null);

        $response = $this->client->post('v1/swarm/jobs', ['json' => $body]);
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded) || ! isset($decoded['id'])) {
            throw new ProviderException(
                'Kimi swarm submit returned no job id',
                $this->providerName(),
            );
        }

        return JobHandle::new(
            provider: $this->providerName(),
            jobId: (string) $decoded['id'],
            kind: 'swarm',
        );
    }

    public function poll(JobHandle $handle): JobStatus
    {
        $response = $this->client->get('v1/swarm/jobs/' . rawurlencode($handle->jobId));
        $decoded = json_decode((string) $response->getBody(), true);
        $status = (string) ($decoded['status'] ?? 'unknown');

        return match (strtolower($status)) {
            'completed', 'done', 'success'       => JobStatus::Done,
            'failed', 'error'                    => JobStatus::Failed,
            'cancelled', 'canceled'              => JobStatus::Canceled,
            'pending', 'queued', 'validating'    => JobStatus::Pending,
            default                              => JobStatus::Running,
        };
    }

    public function fetch(JobHandle $handle): mixed
    {
        $response = $this->client->get('v1/swarm/jobs/' . rawurlencode($handle->jobId));
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            return null;
        }
        // Deliverable shape depends on the job kind — callers inspect it.
        return $decoded['result'] ?? $decoded['output'] ?? $decoded;
    }

    public function cancel(JobHandle $handle): bool
    {
        try {
            $this->client->post('v1/swarm/jobs/' . rawurlencode($handle->jobId) . '/cancel');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function providerName(): string
    {
        return 'kimi';
    }

    protected function defaultRegion(): string
    {
        return 'intl';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return match ($region) {
            'intl' => 'https://api.moonshot.ai',
            'cn' => 'https://api.moonshot.cn',
            default => throw new ProviderException(
                "Unknown region '{$region}' for kimi (expected: intl, cn)",
                'kimi',
            ),
        };
    }

    protected function defaultModel(): string
    {
        return 'kimi-k2-6';
    }
}
