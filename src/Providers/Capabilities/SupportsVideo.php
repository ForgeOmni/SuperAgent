<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

use SuperAgent\Providers\AsyncCapable;
use SuperAgent\Providers\JobHandle;

/**
 * Provider exposes server-side video generation — text-to-video and/or
 * image-to-video (MiniMax Hailuo-2.3, Z.AI CogVideoX / Vidu).
 *
 * A single interface covers T2V and I2V via the `kind` discriminant on the
 * submitted options; providers that only support one variant should ignore
 * the other.
 */
interface SupportsVideo extends AsyncCapable
{
    /**
     * Submit a video-generation job.
     *
     * `$opts['kind']` is `'t2v'` (text-to-video, default) or `'i2v'`
     * (image-to-video — `$opts['image']` carries the source frame).
     *
     * @param array{kind?: 't2v'|'i2v', image?: string, duration_seconds?: int, resolution?: string, ...} $opts
     */
    public function submitVideo(string $prompt, array $opts = []): JobHandle;
}
