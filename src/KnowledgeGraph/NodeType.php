<?php

declare(strict_types=1);

namespace SuperAgent\KnowledgeGraph;

/**
 * Types of nodes in the knowledge graph.
 */
enum NodeType: string
{
    /** A file in the codebase. */
    case FILE = 'file';

    /** A function, method, or class. */
    case SYMBOL = 'symbol';

    /** An agent that performed work. */
    case AGENT = 'agent';

    /** A decision or finding made during execution. */
    case DECISION = 'decision';

    /** A tool that was used. */
    case TOOL = 'tool';
}
