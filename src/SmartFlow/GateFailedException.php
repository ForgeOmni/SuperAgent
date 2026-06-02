<?php

declare(strict_types=1);

namespace SuperAgent\SmartFlow;

/**
 * Thrown when a `required` {@see Flow::gate()} fails and no fallback/relay
 * produced a substitute value — the flow cannot legitimately be "accepted".
 */
class GateFailedException extends \RuntimeException
{
}
