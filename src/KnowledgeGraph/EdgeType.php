<?php

declare(strict_types=1);

namespace SuperAgent\KnowledgeGraph;

/**
 * Types of edges (relationships) in the knowledge graph.
 */
enum EdgeType: string
{
    /** Agent read a file. */
    case READ = 'read';

    /** Agent modified a file. */
    case MODIFIED = 'modified';

    /** Agent created a file. */
    case CREATED = 'created';

    /** File depends on another file (import/require). */
    case DEPENDS_ON = 'depends_on';

    /** Agent made a decision about something. */
    case DECIDED = 'decided';

    /** Agent searched for a pattern and found it in a file. */
    case SEARCHED = 'searched';

    /** Agent executed a command related to a file/symbol. */
    case EXECUTED = 'executed';

    /** Symbol is defined in a file. */
    case DEFINED_IN = 'defined_in';

    /** Generic relation — the specific verb is stored in metadata["relation"]. */
    case RELATES_TO = 'relates_to';
}
