<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

use SuperAgent\Providers\AsyncCapable;
use SuperAgent\Providers\JobHandle;

/**
 * Provider exposes server-side image generation (CogView, MiniMax
 * `image-01`, any future T2I / I2I endpoints).
 *
 * Modelled as async to match the other media generators — an implementation
 * whose upstream returns images synchronously should resolve the handle to
 * `Done` on first poll, same as short-form TTS.
 */
interface SupportsImage extends AsyncCapable
{
    /**
     * Submit an image-generation job.
     *
     * `$opts['kind']` is `'t2i'` (text-to-image, default) or `'i2i'`
     * (image-to-image — `$opts['image']` carries the source).
     *
     * @param array{kind?: 't2i'|'i2i', image?: string, size?: string, n?: int, style?: string, ...} $opts
     */
    public function submitImage(string $prompt, array $opts = []): JobHandle;
}
