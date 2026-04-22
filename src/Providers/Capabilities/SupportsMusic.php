<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

use SuperAgent\Providers\AsyncCapable;
use SuperAgent\Providers\JobHandle;

/**
 * Provider exposes server-side music generation (MiniMax `music-2.6`).
 *
 * Always async — a one-minute track takes tens of seconds to render.
 */
interface SupportsMusic extends AsyncCapable
{
    /**
     * Submit a music-generation job.
     *
     * @param array{lyrics?: string, reference_audio?: string, instrumental?: bool, style?: string, duration_seconds?: int, ...} $opts
     */
    public function submitMusic(string $prompt, array $opts = []): JobHandle;
}
