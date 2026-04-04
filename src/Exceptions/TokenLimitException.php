<?php

namespace SuperAgent\Exceptions;

class TokenLimitException extends RecoverableException
{
    private int $currentTokens = 0;
    private int $maxTokens = 0;
    
    public function setTokenCounts(int $current, int $max): void
    {
        $this->currentTokens = $current;
        $this->maxTokens = $max;
    }
    
    public function getCurrentTokens(): int
    {
        return $this->currentTokens;
    }
    
    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }
    
    public function getExcessTokens(): int
    {
        return max(0, $this->currentTokens - $this->maxTokens);
    }
    
    public function getReductionPercentage(): float
    {
        if ($this->currentTokens === 0) {
            return 0;
        }
        
        return ($this->getExcessTokens() / $this->currentTokens) * 100;
    }
}