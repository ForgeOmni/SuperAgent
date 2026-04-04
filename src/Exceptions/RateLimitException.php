<?php

namespace SuperAgent\Exceptions;

class RateLimitException extends RecoverableException
{
    private ?int $retryAfter = null;
    private ?int $resetTime = null;
    
    public function setRetryAfter(int $seconds): void
    {
        $this->retryAfter = $seconds;
    }
    
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
    
    public function setResetTime(int $timestamp): void
    {
        $this->resetTime = $timestamp;
    }
    
    public function getResetTime(): ?int
    {
        return $this->resetTime;
    }
    
    public function getWaitTime(): int
    {
        if ($this->retryAfter !== null) {
            return $this->retryAfter * 1000; // Convert to milliseconds
        }
        
        if ($this->resetTime !== null) {
            return max(0, $this->resetTime - time()) * 1000;
        }
        
        // Default wait time
        return 5000; // 5 seconds
    }
}