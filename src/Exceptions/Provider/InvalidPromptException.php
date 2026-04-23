<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions\Provider;

use SuperAgent\Exceptions\ProviderException;

/**
 * The request body was malformed — tool schema JSON invalid, message
 * role missing, image attachment exceeds size limit, unsupported
 * content type, stream of consciousness with unescaped control
 * characters, etc. Not the model's content refusing to run; the
 * server refused to parse the request in the first place.
 *
 * Not retryable. Caller needs to inspect and fix the payload. Almost
 * always indicates a bug in the SDK or an upstream prompt builder —
 * real end-user prompts don't trigger this category.
 *
 * Maps to codex's `ApiError::InvalidRequest` and OpenAI's
 * `invalid_request_error` with a 400 status.
 */
final class InvalidPromptException extends ProviderException
{
}
