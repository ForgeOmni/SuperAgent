<?php

declare(strict_types=1);

namespace SuperAgent\Goals;

/**
 * Raised when a caller tries to start a fresh goal on a thread that
 * already has one. Codex's `create_goal` tool fails with the same
 * shape; the model is expected to call `get_goal` first and decide
 * whether to mark it complete or just continue working.
 */
class GoalAlreadyExistsException extends \RuntimeException
{
}
