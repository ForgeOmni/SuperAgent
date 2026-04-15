<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

/**
 * Kind of wing.
 *
 * Each wing represents a single subject in the palace — a person you work
 * with, a project you work on, a topic you think about, or an agent that
 * owns its own diary.
 */
enum WingType: string
{
    case PERSON = 'person';
    case PROJECT = 'project';
    case TOPIC = 'topic';
    case AGENT = 'agent';
    case GENERAL = 'general';
}
