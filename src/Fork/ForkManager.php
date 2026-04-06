<?php

declare(strict_types=1);

namespace SuperAgent\Fork;

final class ForkManager
{
    /** @var ForkSession[] */
    private array $activeSessions = [];

    public function __construct(
        private readonly ForkExecutor $executor,
    ) {}

    /**
     * Create N forks from a conversation snapshot with the same prompt.
     */
    public function fork(
        array $messages,
        int $turnCount,
        string $prompt,
        int $branches,
        array $config = [],
    ): ForkSession {
        $session = new ForkSession($messages, $turnCount, $config);

        for ($i = 0; $i < $branches; $i++) {
            $session->addBranch($prompt);
        }

        $this->activeSessions[$session->id] = $session;
        return $session;
    }

    /**
     * Fork with different prompts for each branch.
     */
    public function forkWithVariants(
        array $messages,
        int $turnCount,
        array $prompts,
        array $config = [],
    ): ForkSession {
        $session = new ForkSession($messages, $turnCount, $config);

        foreach ($prompts as $prompt) {
            $session->addBranch($prompt);
        }

        $this->activeSessions[$session->id] = $session;
        return $session;
    }

    /**
     * Execute all forks in parallel.
     */
    public function execute(ForkSession $session): ForkResult
    {
        return $this->executor->executeAll($session);
    }

    /**
     * Execute and automatically select the best branch using a scorer.
     */
    public function executeAndSelect(ForkSession $session, callable $scorer): ?ForkBranch
    {
        $result = $this->executor->executeAll($session);
        return $result->getBest($scorer);
    }

    /**
     * Convenience: fork, execute, and select in one call.
     */
    public function forkAndSelect(
        array $messages,
        int $turnCount,
        string $prompt,
        int $branches,
        callable $scorer,
        array $config = [],
    ): ForkResult {
        $session = $this->fork($messages, $turnCount, $prompt, $branches, $config);
        return $this->execute($session);
    }

    /**
     * @return ForkSession[]
     */
    public function getActiveSessions(): array
    {
        return $this->activeSessions;
    }

    public function getSession(string $id): ?ForkSession
    {
        return $this->activeSessions[$id] ?? null;
    }
}
