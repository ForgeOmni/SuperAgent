<?php

namespace SuperAgent\Exceptions;

class UnrecoverableException extends \LogicException
{
    private ?string $reason = null;
    private array $context = [];
    
    public function setReason(string $reason): void
    {
        $this->reason = $reason;
    }
    
    public function getReason(): ?string
    {
        return $this->reason;
    }
    
    public function setContext(array $context): void
    {
        $this->context = $context;
    }
    
    public function getContext(): array
    {
        return $this->context;
    }
}