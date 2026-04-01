<?php

namespace SuperAgent\Enums;

enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
}
