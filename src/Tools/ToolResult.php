<?php

namespace SuperAgent\Tools;

class ToolResult
{
    public function __construct(
        public readonly string|array $content,
        public readonly bool $isError = false,
    ) {
    }

    public static function success(string|array $content): static
    {
        return new static($content);
    }

    public static function error(string $message): static
    {
        return new static($message, isError: true);
    }
    
    public static function failure(string $message): static
    {
        return self::error($message);
    }

    public function isSuccess(): bool
    {
        return !$this->isError;
    }

    public function __get(string $name)
    {
        if ($name === 'error' && $this->isError) {
            return $this->contentAsString();
        }
        if ($name === 'data' && !$this->isError) {
            return $this->content;
        }
        return null;
    }

    public function contentAsString(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        return json_encode($this->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
