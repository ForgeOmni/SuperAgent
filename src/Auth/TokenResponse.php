<?php

namespace SuperAgent\Auth;

class TokenResponse
{
    /**
     * @param array<string, mixed>|null $extra Vendor-specific extras
     *   the spec doesn't standardize — e.g. Qwen's `resource_url`
     *   (per-account API base URL), OpenID `id_token`. Caller-
     *   dependent; `toArray()` merges these in.
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType = 'bearer',
        public readonly string $scope = '',
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresIn = null,
        public readonly ?array $extra = null,
    ) {}

    public function isExpired(): bool
    {
        // Can't determine without knowing when token was issued
        // Return false - caller should track creation time
        return false;
    }

    public function toArray(): array
    {
        $base = array_filter([
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'scope' => $this->scope,
            'refresh_token' => $this->refreshToken,
            'expires_in' => $this->expiresIn,
        ], fn($v) => $v !== null);
        // Extra fields from the raw token response merged in so callers
        // that read back via toArray() see the vendor-specific bits.
        if ($this->extra !== null) {
            foreach ($this->extra as $k => $v) {
                if ($v !== null) {
                    $base[$k] = $v;
                }
            }
        }
        return $base;
    }
}
