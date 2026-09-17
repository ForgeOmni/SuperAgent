<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * A tool was handed to an agent whose tool policy refuses it.
 *
 * Raised at construction or at `addTool()` — never mid-run. A tool that
 * reaches the model anyway is refused at call time with an error result
 * instead, so one bad tool cannot abort a conversation.
 *
 * @since 1.3.0
 */
class ToolPolicyException extends SuperAgentException
{
}
