<?php

namespace SuperAgent\Exceptions;

class ModelOverloadedException extends RecoverableException
{
    private ?string $model = null;
    private ?string $suggestedModel = null;
    private ?float $loadLevel = null;
    
    public function setModel(string $model): void
    {
        $this->model = $model;
    }
    
    public function getModel(): ?string
    {
        return $this->model;
    }
    
    public function setSuggestedModel(string $model): void
    {
        $this->suggestedModel = $model;
    }
    
    public function getSuggestedModel(): ?string
    {
        return $this->suggestedModel;
    }
    
    public function setLoadLevel(float $level): void
    {
        $this->loadLevel = $level;
    }
    
    public function getLoadLevel(): ?float
    {
        return $this->loadLevel;
    }
}