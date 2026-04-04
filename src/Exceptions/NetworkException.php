<?php

namespace SuperAgent\Exceptions;

class NetworkException extends RecoverableException
{
    private ?string $host = null;
    private ?int $port = null;
    private ?float $timeout = null;
    
    public function setConnectionDetails(string $host, ?int $port = null): void
    {
        $this->host = $host;
        $this->port = $port;
    }
    
    public function getHost(): ?string
    {
        return $this->host;
    }
    
    public function getPort(): ?int
    {
        return $this->port;
    }
    
    public function setTimeout(float $seconds): void
    {
        $this->timeout = $seconds;
    }
    
    public function getTimeout(): ?float
    {
        return $this->timeout;
    }
    
    public function isTimeout(): bool
    {
        return str_contains(strtolower($this->getMessage()), 'timeout');
    }
    
    public function isConnectionRefused(): bool
    {
        return str_contains(strtolower($this->getMessage()), 'refused');
    }
}