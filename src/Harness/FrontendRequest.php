<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

/**
 * Represents a request from the frontend to the backend.
 */
class FrontendRequest
{
    public const TYPE_SUBMIT = 'submit_line';
    public const TYPE_PERMISSION = 'permission_response';
    public const TYPE_QUESTION = 'question_response';
    public const TYPE_SELECT = 'select_command';
    public const TYPE_APPLY_SELECT = 'apply_select_command';

    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {}

    public static function fromArray(array $raw): ?self
    {
        if (!isset($raw['type'])) {
            return null;
        }
        return new self($raw['type'], $raw['data'] ?? []);
    }

    public function isSubmit(): bool
    {
        return $this->type === self::TYPE_SUBMIT;
    }

    public function isPermission(): bool
    {
        return $this->type === self::TYPE_PERMISSION;
    }

    public function isQuestion(): bool
    {
        return $this->type === self::TYPE_QUESTION;
    }

    public function getLine(): ?string
    {
        return $this->data['line'] ?? null;
    }

    public function getRequestId(): ?string
    {
        return $this->data['request_id'] ?? null;
    }

    public function getValue(): mixed
    {
        return $this->data['value'] ?? null;
    }
}
