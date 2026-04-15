<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use SuperAgent\KnowledgeGraph\KnowledgeEdge;
use SuperAgent\KnowledgeGraph\KnowledgeGraph;
use SuperAgent\KnowledgeGraph\KnowledgeNode;
use SuperAgent\KnowledgeGraph\NodeType;

/**
 * Check an assertion against the knowledge graph.
 *
 * Three conflict severities:
 *   - ATTRIBUTION: "X did Y" but KG says Z did Y (current fact conflict)
 *   - STALE:       "X is still Y" but KG says X stopped being Y on date D
 *   - UNSUPPORTED: "X is Y" but KG has no such relationship
 *
 * The checker is conservative — it only flags clear conflicts based on
 * existing triples. It does not call an LLM.
 */
class FactChecker
{
    public function __construct(private readonly KnowledgeGraph $graph) {}

    /**
     * @return array<int, array{severity: string, subject: string, reason: string}>
     */
    public function check(string $subject, string $relation, string $object): array
    {
        $issues = [];
        $subjectId = KnowledgeNode::makeId(NodeType::ENTITY, $subject);
        $objectId = KnowledgeNode::makeId(NodeType::ENTITY, $object);

        $now = date('c');
        $matchingCurrent = [];
        $matchingEnded = [];
        $attributionConflicts = [];

        foreach ($this->graph->queryEntity($subject) as $edge) {
            $rel = $this->relationOf($edge);
            if (strcasecmp($rel, $relation) !== 0) {
                continue;
            }
            if ($edge->sourceId === $subjectId && $edge->targetId === $objectId) {
                $matchingCurrent[] = $edge;
            } elseif ($edge->sourceId === $subjectId && $edge->targetId !== $objectId) {
                $attributionConflicts[] = $edge;
            }
        }

        foreach ($this->graph->timeline($subject) as $edge) {
            $rel = $this->relationOf($edge);
            if (strcasecmp($rel, $relation) !== 0) {
                continue;
            }
            if ($edge->sourceId === $subjectId && $edge->targetId === $objectId && $edge->isInvalidated()) {
                $matchingEnded[] = $edge;
            }
        }

        if (!empty($attributionConflicts) && empty($matchingCurrent)) {
            $other = $attributionConflicts[0];
            $otherNode = $this->graph->getNode($other->targetId);
            $issues[] = [
                'severity' => 'attribution_conflict',
                'subject' => $subject,
                'reason' => sprintf(
                    'KG says %s %s %s, not %s',
                    $subject,
                    $relation,
                    $otherNode?->label ?? '?',
                    $object,
                ),
            ];
        }

        if (!empty($matchingEnded) && empty($matchingCurrent)) {
            $last = end($matchingEnded);
            $issues[] = [
                'severity' => 'stale',
                'subject' => $subject,
                'reason' => sprintf(
                    '%s %s %s ended on %s',
                    $subject,
                    $relation,
                    $object,
                    $last->validUntil ?: 'unknown',
                ),
            ];
        }

        if (empty($matchingCurrent) && empty($matchingEnded) && empty($attributionConflicts)) {
            $issues[] = [
                'severity' => 'unsupported',
                'subject' => $subject,
                'reason' => "KG has no triple matching {$subject} {$relation} {$object}",
            ];
        }

        return $issues;
    }

    private function relationOf(KnowledgeEdge $edge): string
    {
        return (string) ($edge->metadata['relation'] ?? $edge->type->value);
    }
}
