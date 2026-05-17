<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wraps an `agentDispatcher` callable with reviewer-loop semantics.
 *
 * The wrapper is the SDK's answer to the "no `until-approved` loop"
 * gap in baseline squad mode: instead of telling the human "rejected,
 * rerun yourself", the runner traps the rejection, prepends the
 * reviewer's feedback to the writer's prompt as a structured
 * `## Reviewer feedback` block, and re-dispatches the writer step.
 * That repeats until either:
 *
 *   - the reviewer's response starts with `APPROVED` (case-insensitive,
 *     first non-blank line) — the loop exits with the writer's last
 *     output as the final answer; OR
 *   - the per-binding `max_retries` cap is hit — the loop exits with
 *     the last writer output and writes `loop_aborted: true` plus the
 *     last feedback into the blackboard under the binding's
 *     `feedback_key`. The reviewer's verdict is preserved in the
 *     pipeline result so downstream merge steps can flag it.
 *
 * Why a wrapper and not a new step type:
 *
 *   `PeerOrchestrator` already builds a pipeline of `AgentStep` nodes
 *   and runs them via the engine's dispatcher closure. Adding a new
 *   step type would force every existing consumer (AutoMode, CLI,
 *   custom hosts) to handle a new node shape. A wrapper on the
 *   dispatcher is transparent — the pipeline still sees `writer` and
 *   `reviewer` as plain agent steps; the wrapper bookkeeps state and
 *   silently re-dispatches.
 *
 * Usage:
 *
 *   $runner = new ReviewerLoopRunner($bindings, $blackboard);
 *   $wrapped = $runner->wrap($baseDispatcher);
 *   $orch = new PeerOrchestrator($wrapped, ...);
 *
 * Cost accounting: each retry pays for the writer + reviewer dispatch.
 * The wrapper preserves the dispatcher's return shape (`output` /
 * `cost_usd`) so PeerOrchestrator's budget cap fires normally when
 * the loop burns too much.
 */
final class ReviewerLoopRunner
{
    /** @var array<string, ReviewerLoopBinding> indexed by writer step name */
    private array $byWriter = [];

    /** @var array<string, ReviewerLoopBinding> indexed by reviewer step name */
    private array $byReviewer = [];

    /** @var array<string, array{output:string, attempts:int}> per-writer state */
    private array $writerState = [];

    /** @var array<string, string> per-writer last feedback (empty when none) */
    private array $lastFeedback = [];

    /**
     * @param ReviewerLoopBinding[] $bindings
     */
    public function __construct(
        array $bindings,
        private readonly ?Blackboard $blackboard = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        foreach ($bindings as $b) {
            $this->byWriter[$b->writer]     = $b;
            $this->byReviewer[$b->reviewer] = $b;
        }
    }

    /**
     * Wrap a dispatcher so reviewer rejections trigger writer re-runs.
     * Returns a new callable with the same `(SquadDispatchRequest) =>
     * array|string` signature.
     *
     * @param callable(SquadDispatchRequest): mixed $inner
     * @return callable(SquadDispatchRequest): mixed
     */
    public function wrap(callable $inner): callable
    {
        return function (SquadDispatchRequest $req) use ($inner) {
            $stepName = $req->role->name;

            // Writer side — prepend any pending feedback to the prompt.
            if (isset($this->byWriter[$stepName])) {
                $pending = $this->lastFeedback[$stepName] ?? '';
                if ($pending !== '') {
                    $req = $this->withFeedback($req, $pending);
                }
                $result = $inner($req);
                $output = $this->extractOutput($result);
                $this->writerState[$stepName] ??= ['output' => '', 'attempts' => 0];
                $this->writerState[$stepName]['output']    = $output;
                $this->writerState[$stepName]['attempts'] += 1;
                return $result;
            }

            // Reviewer side — dispatch, parse verdict, decide whether
            // to re-trigger the writer for another pass.
            if (isset($this->byReviewer[$stepName])) {
                $binding = $this->byReviewer[$stepName];
                $result  = $inner($req);
                $verdict = $this->extractOutput($result);

                if ($this->isApproved($verdict)) {
                    return $result;
                }

                $writerName = $binding->writer;
                $attempts   = $this->writerState[$writerName]['attempts'] ?? 0;

                if ($attempts >= $binding->maxRetries) {
                    $this->logger->warning('ReviewerLoopRunner: max_retries hit', [
                        'writer'      => $writerName,
                        'reviewer'    => $stepName,
                        'attempts'    => $attempts,
                        'max_retries' => $binding->maxRetries,
                    ]);
                    $this->blackboard?->write(
                        $stepName,
                        $binding->feedbackKey,
                        ['loop_aborted' => true, 'last_feedback' => $verdict, 'attempts' => $attempts],
                        Blackboard::KIND_DECISION,
                    );

                    // Cross-mode escalation: when a host-installed
                    // ModeRouter is registered and the active policy
                    // wants to escalate, route the writer's task to a
                    // bigger mode (typically `smart`) on rejection
                    // overflow. The escalation result REPLACES the
                    // reviewer's failing output so downstream pipeline
                    // steps see the recovered artefact instead of the
                    // rejection. Silently no-ops without a router.
                    $escalated = $this->maybeEscalate(
                        writerName: $writerName,
                        writerPrompt: $this->writerState[$writerName]['output'] ?? '',
                        feedback: $verdict,
                    );
                    if ($escalated !== null) {
                        return ['output' => $escalated, 'cost_usd' => 0.0, 'escalated' => true];
                    }

                    return $result;
                }

                $this->lastFeedback[$writerName] = $verdict;
                $this->blackboard?->write(
                    $stepName,
                    $binding->feedbackKey,
                    $verdict,
                    Blackboard::KIND_RISK,
                );

                // Re-run the writer with feedback baked in. We rebuild
                // the request from scratch using the prior writer's
                // session id so prompt-cache continuity is preserved.
                $rerunReq = new SquadDispatchRequest(
                    role:         clone $this->dummyWriterRole($req, $writerName),
                    provider:     $req->provider,
                    model:        $req->model,
                    prompt:       $this->writerState[$writerName]['output']
                                     ?? '(no prior output)',
                    systemPrompt: $req->systemPrompt,
                    sessionId:    $req->sessionId,
                    blackboard:   $req->blackboard,
                    mailbox:      $req->mailbox,
                );
                $rerunReq = $this->withFeedback($rerunReq, $verdict);
                $rerun   = $inner($rerunReq);
                $newOut  = $this->extractOutput($rerun);
                $this->writerState[$writerName]['output']    = $newOut;
                $this->writerState[$writerName]['attempts'] += 1;
                $this->lastFeedback[$writerName] = '';

                // Re-run the reviewer once more with the new writer
                // output. We deliberately do NOT recursively loop here
                // — `attempts < max_retries` already gates this, and
                // re-entering `wrap()` would double-bill. The next
                // reviewer pass is just one more dispatch.
                return $inner($req);
            }

            // Unrelated step — pass through.
            return $inner($req);
        };
    }

    /**
     * Return the per-writer final output snapshot. Useful when the
     * caller wants to surface "we retried 2 times before approval"
     * stats on the result envelope.
     *
     * @return array<string, array{output:string, attempts:int}>
     */
    public function writerState(): array
    {
        return $this->writerState;
    }

    /**
     * If a `ModeRouter` SPI is registered AND the active
     * `CrossModePolicy` opts into escalation, hand the failing
     * writer's task off to a larger mode (e.g. `smart`) one last
     * time. Returns the escalation text, or null when no escalation
     * happened.
     *
     * Why this lives behind a static SPI lookup rather than ctor
     * injection: ReviewerLoopRunner is built fresh per squad run
     * inside `CliSquadOrchestrator` / `SquadModeAdapter`, and we
     * don't want every construction site to know about the router.
     * The SPI matches the rest of the cross-mode wiring pattern.
     */
    private function maybeEscalate(string $writerName, string $writerPrompt, string $feedback): ?string
    {
        if (!class_exists(\SuperAgent\Modes\ModeRouterRegistry::class)) return null;
        $router = \SuperAgent\Modes\ModeRouterRegistry::get();
        if ($router === null) return null;

        // The runner doesn't own a ModeContext directly — squad runs
        // build their own. We construct a degraded one-shot context
        // for the escalation; the parent's context wouldn't be safe
        // to descend further (max_depth, cycle). The blackboard is
        // shared so the escalation can read prior step output.
        if ($this->blackboard === null) return null;
        try {
            $ctx = \SuperAgent\Modes\ModeContext::root('squad', $this->blackboard);
            // Policy: only escalate when the policy says so.
            if (!$ctx->policy->autoEscalateOnFailure) return null;
            $target = $ctx->policy->escalateTo ?: 'smart';
            if (!$router->has($target)) return null;
            $prompt = "## Reviewer feedback (final, after max_retries):\n\n"
                    . trim($feedback)
                    . "\n\n## Prior attempt by writer '{$writerName}':\n\n"
                    . trim($writerPrompt);
            $result = $router->descend($target, $prompt, $ctx);
            $this->logger->info('ReviewerLoopRunner: escalated to ' . $target, [
                'writer' => $writerName,
                'mode'   => $result->mode,
                'cost'   => $result->costUsd,
            ]);
            return $result->text;
        } catch (\Throwable $e) {
            $this->logger->warning('ReviewerLoopRunner: escalation failed: ' . $e->getMessage());
            return null;
        }
    }

    private function isApproved(string $text): bool
    {
        $firstLine = strtok(trim($text), "\n") ?: '';
        return stripos(ltrim($firstLine), 'approved') === 0;
    }

    private function extractOutput(mixed $result): string
    {
        if (is_array($result) && isset($result['output'])) return (string) $result['output'];
        if (is_string($result)) return $result;
        return '';
    }

    /**
     * Build a feedback-injected variant of the request without
     * mutating the original. We prepend a clearly-fenced block so
     * the writer's prompt template can also do its own substitution
     * for `{{feedback}}` if it wants to.
     */
    private function withFeedback(SquadDispatchRequest $req, string $feedback): SquadDispatchRequest
    {
        $prefixed = "## Reviewer feedback (must address before re-submit)\n\n"
                  . trim($feedback)
                  . "\n\n## Your prior attempt\n\n"
                  . $req->prompt;
        return new SquadDispatchRequest(
            role:         $req->role,
            provider:     $req->provider,
            model:        $req->model,
            prompt:       $prefixed,
            systemPrompt: $req->systemPrompt,
            sessionId:    $req->sessionId,
            blackboard:   $req->blackboard,
            mailbox:      $req->mailbox,
        );
    }

    /**
     * The pipeline engine owns the original writer's SquadRole — we
     * don't get to see it from inside a reviewer dispatch. Synthesise
     * a minimum-viable one so the rerun request has a valid `role`
     * field; the inner dispatcher only reads the name for logging
     * and the session-id helper.
     */
    private function dummyWriterRole(SquadDispatchRequest $req, string $writerName): SquadRole
    {
        return new SquadRole(
            name:        $writerName,
            provider:    $req->provider,
            model:       $req->model,
            tier:        $req->role->tier,
            systemPrompt: $req->systemPrompt,
            sessionId:   $req->sessionId,
        );
    }
}
