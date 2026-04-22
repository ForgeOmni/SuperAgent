<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider exposes a server-side code interpreter — the model can
 * generate Python / shell and have it executed on the vendor side, with
 * stdout and artefacts fed back into the conversation (Qwen's
 * `enable_code_interpreter`, OpenAI code interpreter, Gemini's
 * `code_execution`).
 *
 * The method returns a request-body fragment to merge into the outbound
 * chat request, mirroring `SupportsThinking`'s shape. Caller-driven
 * execution (a local sandbox tool, Jupyter kernel, etc.) stays outside
 * this interface — it belongs in `Tool`-land, not `Capability`-land.
 */
interface SupportsCodeInterpreter
{
    /**
     * @param array<string, mixed> $options Caller hints — reserved for
     *            future per-vendor knobs (max runtime, allowed packages, …).
     *
     * @return array<string, mixed> Fragment to deep-merge into the chat body.
     */
    public function codeInterpreterRequestFragment(array $options = []): array;
}
