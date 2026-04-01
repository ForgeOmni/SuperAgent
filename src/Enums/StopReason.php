<?php

namespace SuperAgent\Enums;

enum StopReason: string
{
    case EndTurn = 'end_turn';
    case ToolUse = 'tool_use';
    case MaxTokens = 'max_tokens';
    case StopSequence = 'stop_sequence';
}
