<?php

declare(strict_types=1);

namespace SuperAgent\KnowledgeGraph;

/**
 * Captures tool execution events and builds the knowledge graph.
 *
 * Listens to tool calls (Read, Edit, Write, Grep, Glob, Bash) and
 * records nodes (files, agents) and edges (read, modified, searched).
 * Designed to be called from PreToolUse/PostToolUse hooks or directly
 * from the QueryEngine after tool execution.
 *
 * Usage:
 *   $collector = new GraphCollector($graph, 'agent-name');
 *   $collector->recordToolCall('Read', ['file_path' => '/src/App.php'], 'file content');
 *   $collector->recordDecision('Chose singleton pattern for UserService');
 */
class GraphCollector
{
    public function __construct(
        private readonly KnowledgeGraph $graph,
        private string $agentName = 'default',
    ) {}

    /**
     * Set the current agent name (changes as different agents execute).
     */
    public function setAgentName(string $name): void
    {
        $this->agentName = $name;
    }

    /**
     * Record a tool call and update the graph accordingly.
     */
    public function recordToolCall(string $toolName, array $toolInput, string $toolOutput = '', bool $isError = false): void
    {
        if ($isError) {
            return; // Don't record failed tool calls
        }

        // Ensure agent node exists
        $this->graph->addNode(NodeType::AGENT, $this->agentName, ['type' => 'agent']);

        $agentId = KnowledgeNode::makeId(NodeType::AGENT, $this->agentName);

        match ($toolName) {
            'Read' => $this->recordRead($toolInput, $agentId),
            'Edit' => $this->recordEdit($toolInput, $agentId),
            'Write' => $this->recordWrite($toolInput, $agentId),
            'Grep' => $this->recordGrep($toolInput, $toolOutput, $agentId),
            'Glob' => $this->recordGlob($toolInput, $toolOutput, $agentId),
            'Bash' => $this->recordBash($toolInput, $agentId),
            default => null,
        };
    }

    /**
     * Record a decision made by the current agent.
     */
    public function recordDecision(string $description, array $metadata = []): void
    {
        $agentId = KnowledgeNode::makeId(NodeType::AGENT, $this->agentName);

        $this->graph->addNode(NodeType::AGENT, $this->agentName);
        $decisionNode = $this->graph->addNode(NodeType::DECISION, $description, $metadata);

        $this->graph->addEdge($agentId, $decisionNode->id, EdgeType::DECIDED, $this->agentName);
    }

    /**
     * Record a file dependency (e.g., file A imports file B).
     */
    public function recordDependency(string $sourceFile, string $targetFile): void
    {
        $sourceNode = $this->graph->addNode(NodeType::FILE, $sourceFile);
        $targetNode = $this->graph->addNode(NodeType::FILE, $targetFile);

        $this->graph->addEdge($sourceNode->id, $targetNode->id, EdgeType::DEPENDS_ON, $this->agentName);
    }

    /**
     * Record a symbol definition in a file.
     */
    public function recordSymbol(string $symbolName, string $filePath, array $metadata = []): void
    {
        $symbolNode = $this->graph->addNode(NodeType::SYMBOL, $symbolName, $metadata);
        $fileNode = $this->graph->addNode(NodeType::FILE, $filePath);

        $this->graph->addEdge($symbolNode->id, $fileNode->id, EdgeType::DEFINED_IN, $this->agentName);
    }

    private function recordRead(array $input, string $agentId): void
    {
        $filePath = $input['file_path'] ?? null;
        if ($filePath === null) {
            return;
        }

        $fileNode = $this->graph->addNode(NodeType::FILE, $filePath);
        $this->graph->addEdge($agentId, $fileNode->id, EdgeType::READ, $this->agentName);
    }

    private function recordEdit(array $input, string $agentId): void
    {
        $filePath = $input['file_path'] ?? null;
        if ($filePath === null) {
            return;
        }

        $fileNode = $this->graph->addNode(NodeType::FILE, $filePath);
        $this->graph->addEdge($agentId, $fileNode->id, EdgeType::MODIFIED, $this->agentName);
    }

    private function recordWrite(array $input, string $agentId): void
    {
        $filePath = $input['file_path'] ?? null;
        if ($filePath === null) {
            return;
        }

        $fileNode = $this->graph->addNode(NodeType::FILE, $filePath);
        $this->graph->addEdge($agentId, $fileNode->id, EdgeType::CREATED, $this->agentName);
    }

    private function recordGrep(array $input, string $output, string $agentId): void
    {
        $pattern = $input['pattern'] ?? null;
        if ($pattern === null) {
            return;
        }

        // Extract file paths from grep output (lines like "path/to/file.php:123:content")
        $files = $this->extractFilesFromGrepOutput($output);
        foreach ($files as $file) {
            $fileNode = $this->graph->addNode(NodeType::FILE, $file);
            $this->graph->addEdge($agentId, $fileNode->id, EdgeType::SEARCHED, $this->agentName, [
                'pattern' => $pattern,
            ]);
        }
    }

    private function recordGlob(array $input, string $output, string $agentId): void
    {
        $pattern = $input['pattern'] ?? null;
        if ($pattern === null) {
            return;
        }

        // Glob output is typically a list of file paths
        $files = array_filter(array_map('trim', explode("\n", $output)));
        foreach (array_slice($files, 0, 20) as $file) { // Limit to avoid huge graphs
            if (!empty($file) && !str_starts_with($file, 'Error')) {
                $this->graph->addNode(NodeType::FILE, $file);
            }
        }
    }

    private function recordBash(array $input, string $agentId): void
    {
        $command = $input['command'] ?? '';
        if (empty($command)) {
            return;
        }

        $this->graph->addNode(NodeType::TOOL, "bash:{$command}", [
            'command' => $command,
        ]);

        $toolId = KnowledgeNode::makeId(NodeType::TOOL, "bash:{$command}");
        $this->graph->addEdge($agentId, $toolId, EdgeType::EXECUTED, $this->agentName);
    }

    /**
     * Extract file paths from grep-style output.
     *
     * @return string[]
     */
    private function extractFilesFromGrepOutput(string $output): array
    {
        $files = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            // Format: "path/to/file.php" or "path/to/file.php:123:content"
            $parts = explode(':', $line, 3);
            $file = $parts[0];
            if (!empty($file) && str_contains($file, '.') && !str_starts_with($file, 'Error')) {
                $files[] = $file;
            }
        }

        return array_unique(array_slice($files, 0, 20));
    }
}
