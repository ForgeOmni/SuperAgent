<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

use SuperAgent\Providers\AsyncCapable;
use SuperAgent\Providers\JobHandle;

/**
 * Provider supports OpenAI-style batch processing — submit a JSONL file of
 * requests, receive a handle, poll until done, fetch the JSONL response
 * stream (Kimi `/batches`, OpenAI `/v1/batches`, Anthropic message batches).
 */
interface SupportsBatch extends AsyncCapable
{
    /**
     * Submit a batch job from a local JSONL file of requests.
     *
     * @param array<string, mixed> $opts Provider-specific options
     *                                    (completion window, endpoint, …).
     */
    public function submitBatch(string $jsonlPath, array $opts = []): JobHandle;
}
