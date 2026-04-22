<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

use SuperAgent\Providers\AsyncCapable;
use SuperAgent\Providers\JobHandle;

/**
 * Provider supports server-side text-to-speech (MiniMax T2A and the async
 * long-form variant).
 *
 * Modelled as async because the long-form endpoints (1M-character jobs with
 * sentence-level timestamps) need it. For short inputs where the upstream
 * API returns audio synchronously, an implementation can still honour the
 * async contract by resolving the handle to `Done` on first poll.
 */
interface SupportsTTS extends AsyncCapable
{
    /**
     * Submit a TTS job.
     *
     * @param array<string, mixed> $opts voice, format, pitch, speed,
     *                                    language, emotion, …
     */
    public function submitTTS(string $text, array $opts = []): JobHandle;
}
