<?php

declare(strict_types=1);

namespace SuperAgent\KnowledgeGraph;

/**
 * High-level manager for the Cross-Agent Knowledge Graph.
 *
 * Provides the API for querying, managing, and exporting the graph.
 *
 * Commands:
 *   graph:summary  — Get a token-efficient summary for agent consumption
 *   graph:files    — List files tracked in the graph
 *   graph:agents   — List agents that contributed
 *   graph:show     — Show details of a node
 *   graph:clear    — Clear the graph
 *   graph:export   — Export to JSON
 *   graph:import   — Import from JSON
 *   graph:stats    — Show statistics
 */
class KnowledgeGraphManager
{
    public function __construct(
        private readonly KnowledgeGraph $graph,
        private readonly GraphCollector $collector,
    ) {}

    // ── Query ──────────────────────────────────────────────────────

    /**
     * Get a token-efficient summary for agent consumption.
     */
    public function getSummary(): string
    {
        return $this->graph->getSummary();
    }

    /**
     * Get files modified by a specific agent.
     *
     * @return string[]
     */
    public function getFilesModifiedBy(string $agentName): array
    {
        return $this->graph->getFilesModifiedBy($agentName);
    }

    /**
     * Get agents that touched a specific file.
     *
     * @return string[]
     */
    public function getAgentsForFile(string $filePath): array
    {
        return $this->graph->getAgentsForFile($filePath);
    }

    /**
     * Get the most frequently accessed files.
     */
    public function getHotFiles(int $limit = 10): array
    {
        return $this->graph->getHotFiles($limit);
    }

    /**
     * Search nodes by keyword.
     *
     * @return KnowledgeNode[]
     */
    public function search(string $keyword, ?NodeType $type = null): array
    {
        return $this->graph->searchNodes($keyword, $type);
    }

    /**
     * Get all decisions recorded in the graph.
     *
     * @return KnowledgeNode[]
     */
    public function getDecisions(): array
    {
        return $this->graph->getDecisions();
    }

    // ── Lifecycle ──────────────────────────────────────────────────

    /**
     * Clear the graph.
     */
    public function clear(): int
    {
        return $this->graph->clear();
    }

    /**
     * Export the graph to JSON string.
     */
    public function export(): string
    {
        return json_encode(
            $this->graph->export(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Export to file.
     */
    public function exportToFile(string $path): void
    {
        file_put_contents($path, $this->export());
    }

    /**
     * Import from JSON string.
     */
    public function import(string $json): int
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON format');
        }

        return $this->graph->import($data);
    }

    /**
     * Import from file.
     */
    public function importFromFile(string $path): int
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        return $this->import(file_get_contents($path));
    }

    // ── Statistics ─────────────────────────────────────────────────

    /**
     * Get graph statistics.
     */
    public function getStatistics(): array
    {
        return $this->graph->getStatistics();
    }

    // ── Sub-components ─────────────────────────────────────────────

    public function getGraph(): KnowledgeGraph
    {
        return $this->graph;
    }

    public function getCollector(): GraphCollector
    {
        return $this->collector;
    }
}
