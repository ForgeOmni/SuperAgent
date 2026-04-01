<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use SuperAgent\Permissions\PermissionMode;

class PlanApprovalResponseMessage extends StructuredMessage
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $from,
        public readonly bool $approve,
        public readonly ?string $feedback = null,
        public readonly ?PermissionMode $permissionMode = null,
    ) {}
    
    public function getType(): string
    {
        return 'plan_approval_response';
    }
    
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->getType(),
            'request_id' => $this->requestId,
            'from' => $this->from,
            'approve' => $this->approve,
            'feedback' => $this->feedback,
            'permission_mode' => $this->permissionMode?->value,
        ]);
    }
}