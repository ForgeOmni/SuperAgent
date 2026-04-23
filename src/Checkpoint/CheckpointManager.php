<?php

declare(strict_types=1);

namespace SuperAgent\Checkpoint;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Messages\Message;

/**
 * Manages agent checkpoints for crash-recovery and long-running task resumption.
 *
 * Priority control for checkpoint behavior:
 *   1. Per-task override: options['checkpoint'] = true/false (highest priority)
 *   2. Config toggle: config('superagent.checkpoint.enabled')
 *
 * Usage in QueryEngine:
 *   $manager = new CheckpointManager($store, interval: 5);
 *   // After each turn in the run() loop:
 *   $manager->maybeCheckpoint($sessionId, $messages, $turnCount, ...);
 *   // On resume:
 *   $checkpoint = $manager->getLatest($sessionId);
 *   $messages = MessageSerializer::deserializeAll($checkpoint->messages);
 *
 * Artisan commands:
 *   php artisan superagent:checkpoint list [--session=id]
 *   php artisan superagent:checkpoint show <id>
 *   php artisan superagent:checkpoint resume <id>
 *   php artisan superagent:checkpoint delete <id>
 *   php artisan superagent:checkpoint clear [--session=id]
 *   php artisan superagent:checkpoint prune [--keep=3]
 *   php artisan superagent:checkpoint stats
 */
class CheckpointManager
{
    private CheckpointStore $store;

    private LoggerInterface $logger;

    /** Checkpoint every N turns */
    private int $interval;

    /** Keep only the latest N checkpoints per session */
    private int $maxPerSession;

    /** Per-task override: null = use config, true/false = forced */
    private ?bool $forceEnabled = null;

    /** Config-level toggle */
    private bool $configEnabled;

    /**
     * Optional shadow git store. When set, every saved checkpoint
     * also captures a file-level snapshot of the user's worktree
     * — the resulting commit hash is stashed under
     * `metadata['shadow_commit']`. `restoreFiles()` plays it back.
     * When null (the default), checkpoints stay JSON-only and
     * behave exactly as before — pure backwards-compat extension.
     */
    private ?GitShadowStore $shadowStore = null;

    public function __construct(
        CheckpointStore $store,
        int $interval = 5,
        int $maxPerSession = 5,
        bool $configEnabled = true,
        ?LoggerInterface $logger = null,
        ?GitShadowStore $shadowStore = null,
    ) {
        $this->store = $store;
        $this->interval = max(1, $interval);
        $this->maxPerSession = $maxPerSession;
        $this->configEnabled = $configEnabled;
        $this->logger = $logger ?? new NullLogger();
        $this->shadowStore = $shadowStore;
    }

    /**
     * Attach (or swap) the shadow git store after construction —
     * useful for DI containers that configure the checkpoint manager
     * before the project root is known.
     */
    public function setShadowStore(?GitShadowStore $shadowStore): void
    {
        $this->shadowStore = $shadowStore;
    }

    /**
     * Set per-task override. Takes precedence over config toggle.
     *
     * @param bool|null $enabled true=force on, false=force off, null=use config
     */
    public function setForceEnabled(?bool $enabled): void
    {
        $this->forceEnabled = $enabled;
    }

    /**
     * Whether checkpointing is currently active (considering override > config).
     */
    public function isEnabled(): bool
    {
        if ($this->forceEnabled !== null) {
            return $this->forceEnabled;
        }

        return $this->configEnabled;
    }

    /**
     * Create a checkpoint if the interval condition is met.
     *
     * Called after each turn in the QueryEngine loop. Only saves
     * if (turnCount % interval == 0) and checkpointing is enabled.
     *
     * @param Message[] $messages Current conversation messages
     */
    public function maybeCheckpoint(
        string $sessionId,
        array $messages,
        int $turnCount,
        float $totalCostUsd,
        int $turnOutputTokens,
        string $model,
        string $prompt,
        array $budgetTrackerState = [],
        array $collectorState = [],
        array $metadata = [],
    ): ?Checkpoint {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($turnCount <= 0 || $turnCount % $this->interval !== 0) {
            return null;
        }

        return $this->createCheckpoint(
            $sessionId, $messages, $turnCount, $totalCostUsd,
            $turnOutputTokens, $model, $prompt,
            $budgetTrackerState, $collectorState, $metadata,
        );
    }

    /**
     * Force-create a checkpoint regardless of interval.
     *
     * @param Message[] $messages
     */
    public function createCheckpoint(
        string $sessionId,
        array $messages,
        int $turnCount,
        float $totalCostUsd,
        int $turnOutputTokens,
        string $model,
        string $prompt,
        array $budgetTrackerState = [],
        array $collectorState = [],
        array $metadata = [],
    ): Checkpoint {
        $serializedMessages = MessageSerializer::serializeAll($messages);

        // Optional filesystem snapshot alongside the JSON state. The
        // commit hash lives under metadata['shadow_commit'] so
        // restoreFiles() can find it without schema changes to
        // the Checkpoint value object.
        if ($this->shadowStore !== null) {
            try {
                $this->shadowStore->init();
                $hash = $this->shadowStore->snapshot(
                    "checkpoint @ turn {$turnCount}"
                );
                $metadata = $metadata + ['shadow_commit' => $hash];
            } catch (\Throwable $e) {
                // A git failure must not kill the JSON checkpoint —
                // they're independent, and the JSON path has always
                // been best-effort. Log and continue.
                $this->logger->warning('Shadow snapshot failed; JSON checkpoint saved without it', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $checkpoint = new Checkpoint(
            id: Checkpoint::generateId($sessionId, $turnCount),
            sessionId: $sessionId,
            messages: $serializedMessages,
            turnCount: $turnCount,
            totalCostUsd: $totalCostUsd,
            turnOutputTokens: $turnOutputTokens,
            budgetTrackerState: $budgetTrackerState,
            collectorState: $collectorState,
            model: $model,
            prompt: $prompt,
            createdAt: date('c'),
            metadata: $metadata,
        );

        $this->store->save($checkpoint);

        // Auto-prune old checkpoints
        $this->store->prune($this->maxPerSession);

        $this->logger->info("Checkpoint created at turn {$turnCount}", [
            'id' => $checkpoint->id,
            'session' => $sessionId,
            'messages' => count($messages),
            'cost' => $totalCostUsd,
        ]);

        return $checkpoint;
    }

    /**
     * Resume from a checkpoint: deserialize messages and return state.
     *
     * @return array{messages: Message[], turnCount: int, totalCostUsd: float, turnOutputTokens: int, budgetTrackerState: array, collectorState: array, model: string, prompt: string}|null
     */
    public function resume(string $checkpointId): ?array
    {
        $checkpoint = $this->store->load($checkpointId);
        if ($checkpoint === null) {
            return null;
        }

        $messages = MessageSerializer::deserializeAll($checkpoint->messages);

        $this->logger->info("Resuming from checkpoint", [
            'id' => $checkpointId,
            'turn' => $checkpoint->turnCount,
            'messages' => count($messages),
        ]);

        return [
            'messages' => $messages,
            'turnCount' => $checkpoint->turnCount,
            'totalCostUsd' => $checkpoint->totalCostUsd,
            'turnOutputTokens' => $checkpoint->turnOutputTokens,
            'budgetTrackerState' => $checkpoint->budgetTrackerState,
            'collectorState' => $checkpoint->collectorState,
            'model' => $checkpoint->model,
            'prompt' => $checkpoint->prompt,
        ];
    }

    /**
     * Get the latest checkpoint for a session.
     */
    public function getLatest(string $sessionId): ?Checkpoint
    {
        return $this->store->getLatest($sessionId);
    }

    /**
     * Restore the project worktree to the filesystem state captured
     * when the given checkpoint was saved. Only works when the
     * checkpoint was created with a shadow store configured AND the
     * same shadow store is attached now.
     *
     * Returns true on success, false if the checkpoint didn't carry
     * a `shadow_commit` (either no shadow store was set at save time,
     * or that attempt failed silently). Throws on git failure — we
     * surface restore errors so callers can fall back to "at least
     * we have the JSON state" behaviour explicitly.
     *
     * Untracked files created after the snapshot are LEFT IN PLACE
     * by the underlying GitShadowStore — restore is reversible via
     * a subsequent checkpoint.
     */
    public function restoreFiles(Checkpoint $checkpoint): bool
    {
        if ($this->shadowStore === null) {
            $this->logger->warning('restoreFiles() called without a shadow store attached');
            return false;
        }
        $hash = $checkpoint->metadata['shadow_commit'] ?? null;
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        $this->shadowStore->restore($hash);
        $this->logger->info("Restored project worktree from shadow commit {$hash}", [
            'checkpoint' => $checkpoint->id,
        ]);
        return true;
    }

    // ── Delegation to store ────────────────────────────────────────

    public function list(?string $sessionId = null): array
    {
        return $this->store->list($sessionId);
    }

    public function show(string $id): ?Checkpoint
    {
        return $this->store->load($id);
    }

    public function delete(string $id): bool
    {
        return $this->store->delete($id);
    }

    public function clear(?string $sessionId = null): int
    {
        return $this->store->clear($sessionId);
    }

    public function prune(int $keepPerSession = 3): int
    {
        return $this->store->prune($keepPerSession);
    }

    public function getStatistics(): array
    {
        return $this->store->getStatistics();
    }

    public function getStore(): CheckpointStore
    {
        return $this->store;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }
}
