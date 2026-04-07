<?php

namespace SuperAgent\Auth;

class DeviceCodeResponse
{
    public function __construct(
        public readonly string $deviceCode,
        public readonly string $userCode,
        public readonly string $verificationUri,
        public readonly ?string $verificationUriComplete = null,
        public readonly int $expiresIn = 300,
        public readonly int $interval = 5,
    ) {}
}
