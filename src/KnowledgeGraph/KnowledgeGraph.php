<?php

declare(strict_types=1);

namespace SuperAgent\KnowledgeGraph;

/**
 * In-memory knowledge graph with JSON persistence.
 *
 * Tracks relationships between files, symbols, agents, and decisions
 * across multi-agent collaboration sessions. Subsequent agents can
 * query the graph instead of re-exploring the codebase.
 *
 * Storage format:
 *   {
 *     "version": "1.0",
 *     "nodes": { "id": {...}, ... },
 *     "edges": [ {...}, ... ],
 *     "stats": { ... },
 *     "last_updated": "..."
 *   }
 */
class KnowledgeGraph
{
    /** @var array<string, KnowledgeNode> */
    private array $nodes = [];

    /** @var KnowledgeEdge[] */
    private array $edges = [];

    public function __construct(private readonly ?string $storagePath = null)
    {
        $this->load();
    }

    // ── Node Operations ────────────────────────────────────────────

    /**
     * Add or update a node. If it exists, touches it and merges metadata.
     */
    public function addNode(NodeType $type, string $label, array $metadata = []): KnowledgeNode
    {
        $id = KnowledgeNode::makeId($type, $label);

        if (isset($this->nodes[$id])) {
            $node = $this->nodes[$id];
            $node->touch();
            $node->metadata = array_merge($node->metadata, $metadata);
        } else {
            $node = new KnowledgeNode($id, $type, $label, $metadata);
            $this->nodes[$id] = $node;
        }

        $this->save();

        return $node;
    }

    /**
     * Get a node by ID.
     */
    public function getNode(string $id): ?KnowledgeNode
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * Find a node by type and label.
     */
    public function findNode(NodeType $type, string $label): ?KnowledgeNode
    {
        return $this->nodes[KnowledgeNode::makeId($type, $label)] ?? null;
    }

    /**
     * Get all nodes, optionally filtered by type.
     *
     * @return KnowledgeNode[]
     */
    public function getNodes(?NodeType $type = null): array
    {
        if ($type === null) {
            return array_values($this->nodes);
        }

        return array_values(array_filter(
            $this->nodes,
            fn (KnowledgeNode $n) => $n->type === $type,
        ));
    }

    // ── Edge Operations ────────────────────────────────────────────

    /**
     * Add an edge. Deduplicates by (source, type, target).
     */
    public function addEdge(
        string $sourceId,
        string $targetId,
        EdgeType $type,
        string $agentName = '',
        array $metadata = [],
    ): KnowledgeEdge {
        $edge = new KnowledgeEdge($sourceId, $targetId, $type, $agentName, date('c'), $metadata);

        // Deduplicate
        $key = $edge->getKey();
        foreach ($this->edges as $i => $existing) {
            if ($existing->getKey() === $key) {
                $this->edges[$i] = $edge; // Update
                $this->save();

                return $edge;
            }
        }

        $this->edges[] = $edge;
        $this->save();

        return $edge;
    }

    /**
     * Get all edges from a source node.
     *
     * @return KnowledgeEdge[]
     */
    public function getEdgesFrom(string $sourceId, ?EdgeType $type = null): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (KnowledgeEdge $e) =>
                $e->sourceId === $sourceId && ($type === null || $e->type === $type),
        ));
    }

    /**
     * Get all edges pointing to a target node.
     *
     * @return KnowledgeEdge[]
     */
    public function getEdgesTo(string $targetId, ?EdgeType $type = null): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (KnowledgeEdge $e) =>
                $e->targetId === $targetId && ($type === null || $e->type === $type),
        ));
    }

    /**
     * Get all edges of a given type.
     *
     * @return KnowledgeEdge[]
     */
    public function getEdgesByType(EdgeType $type): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (KnowledgeEdge $e) => $e->type === $type,
        ));
    }

    /**
     * Get all edges by a specific agent.
     *
     * @return KnowledgeEdge[]
     */
    public function getEdgesByAgent(string $agentName): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (KnowledgeEdge $e) => $e->agentName === $agentName,
        ));
    }

    // ── Query API ──────────────────────────────────────────────────

    /**
     * Get all files modified by a specific agent.
     *
     * @return string[] file paths
     */
    public function getFilesModifiedBy(string $agentName): array
    {
        $files = [];
        foreach ($this->edges as $edge) {
            if ($edge->agentName === $agentName && in_array($edge->type, [EdgeType::MODIFIED, EdgeType::CREATED], true)) {
                $node = $this->nodes[$edge->targetId] ?? null;
                if ($node !== null && $node->type === NodeType::FILE) {
                    $files[] = $node->label;
                }
            }
        }

        return array_unique($files);
    }

    /**
     * Get all agents that touched a specific file.
     *
     * @return string[] agent names
     */
    public function getAgentsForFile(string $filePath): array
    {
        $fileId = KnowledgeNode::makeId(NodeType::FILE, $filePath);
        $agents = [];

        foreach ($this->edges as $edge) {
            if ($edge->targetId === $fileId && !empty($edge->agentName)) {
                $agents[] = $edge->agentName;
            }
        }

        return array_unique($agents);
    }

    /**
     * Get the most frequently accessed files.
     *
     * @return array{file: string, access_count: int}[]
     */
    public function getHotFiles(int $limit = 10): array
    {
        $fileNodes = $this->getNodes(NodeType::FILE);

        usort($fileNodes, fn (KnowledgeNode $a, KnowledgeNode $b) => $b->accessCount <=> $a->accessCount);

        return array_map(
            fn (KnowledgeNode $n) => ['file' => $n->label, 'access_count' => $n->accessCount],
            array_slice($fileNodes, 0, $limit),
        );
    }

    /**
     * Get all decisions recorded in the graph.
     *
     * @return KnowledgeNode[]
     */
    public function getDecisions(): array
    {
        return $this->getNodes(NodeType::DECISION);
    }

    /**
     * Search nodes by label substring.
     *
     * @return KnowledgeNode[]
     */
    public function searchNodes(string $keyword, ?NodeType $type = null): array
    {
        $keyword = strtolower($keyword);

        return array_values(array_filter(
            $this->nodes,
            fn (KnowledgeNode $n) =>
                str_contains(strtolower($n->label), $keyword)
                && ($type === null || $n->type === $type),
        ));
    }

    /**
     * Generate a summary for an agent to consume (reduces token usage).
     */
    public function getSummary(): string
    {
        $fileCount = count($this->getNodes(NodeType::FILE));
        $agentCount = count($this->getNodes(NodeType::AGENT));
        $decisionCount = count($this->getNodes(NodeType::DECISION));
        $edgeCount = count($this->edges);

        $summary = "Knowledge Graph: {$fileCount} files, {$agentCount} agents, {$decisionCount} decisions, {$edgeCount} relationships\n";

        // Hot files
        $hot = $this->getHotFiles(5);
        if (!empty($hot)) {
            $summary .= "\nFrequently accessed files:\n";
            foreach ($hot as $f) {
                $summary .= "  - {$f['file']} ({$f['access_count']}x)\n";
            }
        }

        // Recent decisions
        $decisions = $this->getDecisions();
        if (!empty($decisions)) {
            $summary .= "\nDecisions made:\n";
            foreach (array_slice($decisions, -5) as $d) {
                $summary .= "  - {$d->label}\n";
            }
        }

        return $summary;
    }

    // ── Lifecycle ──────────────────────────────────────────────────

    /**
     * Clear the entire graph.
     */
    public function clear(): int
    {
        $count = count($this->nodes) + count($this->edges);
        $this->nodes = [];
        $this->edges = [];
        $this->save();

        return $count;
    }

    /**
     * Get statistics.
     */
    public function getStatistics(): array
    {
        $byType = [];
        foreach (NodeType::cases() as $type) {
            $count = count($this->getNodes($type));
            if ($count > 0) {
                $byType[$type->value] = $count;
            }
        }

        $byEdgeType = [];
        foreach (EdgeType::cases() as $type) {
            $count = count($this->getEdgesByType($type));
            if ($count > 0) {
                $byEdgeType[$type->value] = $count;
            }
        }

        return [
            'total_nodes' => count($this->nodes),
            'total_edges' => count($this->edges),
            'nodes_by_type' => $byType,
            'edges_by_type' => $byEdgeType,
        ];
    }

    /**
     * Export the graph.
     */
    public function export(): array
    {
        return [
            'version' => '1.0',
            'exported_at' => date('c'),
            'nodes' => array_map(fn (KnowledgeNode $n) => $n->toArray(), $this->nodes),
            'edges' => array_map(fn (KnowledgeEdge $e) => $e->toArray(), $this->edges),
            'stats' => $this->getStatistics(),
        ];
    }

    /**
     * Import graph data (merges with existing).
     */
    public function import(array $data): int
    {
        $imported = 0;

        foreach ($data['nodes'] ?? [] as $nodeData) {
            $node = KnowledgeNode::fromArray($nodeData);
            if (!isset($this->nodes[$node->id])) {
                $this->nodes[$node->id] = $node;
                $imported++;
            }
        }

        foreach ($data['edges'] ?? [] as $edgeData) {
            $edge = KnowledgeEdge::fromArray($edgeData);
            $key = $edge->getKey();
            $exists = false;
            foreach ($this->edges as $existing) {
                if ($existing->getKey() === $key) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $this->edges[] = $edge;
                $imported++;
            }
        }

        if ($imported > 0) {
            $this->save();
        }

        return $imported;
    }

    // ── Persistence ────────────────────────────────────────────────

    private function load(): void
    {
        if ($this->storagePath === null || !file_exists($this->storagePath)) {
            return;
        }

        $data = json_decode(file_get_contents($this->storagePath), true);
        if (!is_array($data)) {
            return;
        }

        foreach ($data['nodes'] ?? [] as $id => $nodeData) {
            $this->nodes[$id] = KnowledgeNode::fromArray($nodeData);
        }

        foreach ($data['edges'] ?? [] as $edgeData) {
            $this->edges[] = KnowledgeEdge::fromArray($edgeData);
        }
    }

    private function save(): void
    {
        if ($this->storagePath === null) {
            return;
        }

        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->storagePath,
            json_encode([
                'version' => '1.0',
                'nodes' => array_map(fn (KnowledgeNode $n) => $n->toArray(), $this->nodes),
                'edges' => array_map(fn (KnowledgeEdge $e) => $e->toArray(), $this->edges),
                'last_updated' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}
