<?php

namespace SuperAgent\Auth;

class TokenResponse
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType = 'bearer',
        public readonly string $scope = '',
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresIn = null,
    ) {}

    public function isExpired(): bool
    {
        // Can't determine without knowing when token was issued
        // Return false - caller should track creation time
        return false;
    }

    public function toArray(): array
    {
        return array_filter([
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'scope' => $this->scope,
            'refresh_token' => $this->refreshToken,
            'expires_in' => $this->expiresIn,
        ], fn($v) => $v !== null);
    }
}
