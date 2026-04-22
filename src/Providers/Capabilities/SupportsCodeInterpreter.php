<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider supports a server-side code interpreter — a sandbox that executes
 * model-authored code during a chat turn and returns stdout / artefacts
 * (Qwen `enable_code_interpreter`, OpenAI code interpreter tool).
 *
 * The interface returns a request-body fragment rather than offering a
 * direct call, because code-interpreter is usually a per-turn opt-in that
 * rides along with the chat request, not a standalone RPC.
 */
interface SupportsCodeInterpreter
{
    /**
     * Build the provider-specific body fragment that turns the code
     * interpreter on for the next chat call.
     *
     * @param array<string, mixed> $opts Provider-specific knobs
     *                                    (language, timeout, files, …).
     * @return array<string, mixed>
     */
    public function codeInterpreterRequestFragment(array $opts = []): array;
}
