<?php

declare(strict_types=1);

namespace SuperAgent\Fork;

final class ForkSession
{
    public readonly string $id;
    public readonly string $createdAt;

    /** @var ForkBranch[] */
    private array $branches = [];

    public function __construct(
        public readonly array $baseMessages,
        public readonly int $forkPoint,
        public readonly array $config = [],
    ) {
        $this->id = substr(md5(uniqid((string) mt_rand(), true)), 0, 16);
        $this->createdAt = date('c');
    }

    public function addBranch(string $prompt, array $config = []): ForkBranch
    {
        $mergedConfig = array_merge($this->config, $config);
        $branch = new ForkBranch($prompt, $mergedConfig);
        $this->branches[] = $branch;
        return $branch;
    }

    public function getBranch(string $id): ?ForkBranch
    {
        foreach ($this->branches as $branch) {
            if ($branch->id === $id) {
                return $branch;
            }
        }
        return null;
    }

    /**
     * @return ForkBranch[]
     */
    public function getBranches(): array
    {
        return $this->branches;
    }

    public function getBaseMessages(): array
    {
        return $this->baseMessages;
    }

    public function getBranchCount(): int
    {
        return count($this->branches);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fork_point' => $this->forkPoint,
            'created_at' => $this->createdAt,
            'branch_count' => $this->getBranchCount(),
            'branches' => array_map(fn(ForkBranch $b) => $b->toArray(), $this->branches),
            'config' => $this->config,
        ];
    }
}
