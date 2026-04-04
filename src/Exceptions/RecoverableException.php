<?php

namespace SuperAgent\Exceptions;

class RecoverableException extends \RuntimeException
{
    private array $retryHistory = [];
    private ?array $checkpoint = null;
    
    public function setRetryHistory(array $history): void
    {
        $this->retryHistory = $history;
    }
    
    public function getRetryHistory(): array
    {
        return $this->retryHistory;
    }
    
    public function setCheckpoint(array $checkpoint): void
    {
        $this->checkpoint = $checkpoint;
    }
    
    public function getCheckpoint(): ?array
    {
        return $this->checkpoint;
    }
    
    public function canRetry(): bool
    {
        return count($this->retryHistory) < 5;
    }
}