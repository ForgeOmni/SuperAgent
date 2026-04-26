<?php

declare(strict_types=1);

namespace SuperAgent\Conversation;

/**
 * The six distinct wire-format families the SDK speaks. Multiple
 * providers can target the same family — Kimi, GLM, MiniMax, Qwen
 * (proxied), OpenRouter and LMStudio all speak `OpenAIChat`; Bedrock's
 * `anthropic.*` model invocations speak `Anthropic`. The Transcoder
 * is the single point of translation; each `Encoder` owns one family.
 *
 * The cases are deliberately ordered by the implementation phase that
 * adds them so the gap between "the enum case exists" and "the encoder
 * is wired up" stays auditable. Cases from later phases throw from
 * the Transcoder until their encoder lands.
 */
enum WireFamily: string
{
    /** A — Anthropic Messages (native + Bedrock anthropic.*).      */
    case Anthropic       = 'anthropic';

    /** B — OpenAI Chat Completions and every compatible variant.   */
    case OpenAIChat      = 'openai_chat';

    /** C — OpenAI Responses API (`/v1/responses`).                  */
    case OpenAIResponses = 'openai_responses';

    /** D — Google Gemini `generateContent` / `streamGenerateContent`. */
    case Gemini          = 'gemini';

    /** E — Alibaba DashScope (Qwen native).                         */
    case DashScope       = 'dashscope';

    /** F — Ollama `/api/chat`.                                      */
    case Ollama          = 'ollama';
}
