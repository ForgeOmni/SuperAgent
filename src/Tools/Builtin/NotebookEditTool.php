<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class NotebookEditTool extends Tool
{
    public function name(): string
    {
        return 'notebook_edit';
    }

    public function description(): string
    {
        return 'Edit Jupyter notebook cells. Supports replacing, inserting, and deleting cells in .ipynb files.';
    }

    public function category(): string
    {
        return 'file';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'notebook_path' => [
                    'type' => 'string',
                    'description' => 'Path to the Jupyter notebook file (.ipynb).',
                ],
                'cell_index' => [
                    'type' => 'integer',
                    'description' => 'Zero-based index of the cell to edit.',
                ],
                'cell_id' => [
                    'type' => 'string',
                    'description' => 'ID of the cell to edit (alternative to cell_index).',
                ],
                'new_source' => [
                    'type' => 'string',
                    'description' => 'New source code for the cell (required for replace/insert modes).',
                ],
                'cell_type' => [
                    'type' => 'string',
                    'enum' => ['code', 'markdown', 'raw'],
                    'description' => 'Type of the cell. Default: inherits from existing cell or "code" for new cells.',
                ],
                'edit_mode' => [
                    'type' => 'string',
                    'enum' => ['replace', 'insert', 'delete'],
                    'description' => 'Edit mode: replace (default), insert (add new cell), or delete.',
                ],
            ],
            'required' => ['notebook_path'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $notebookPath = $input['notebook_path'] ?? '';
        $cellIndex = $input['cell_index'] ?? null;
        $cellId = $input['cell_id'] ?? null;
        $newSource = $input['new_source'] ?? '';
        $cellType = $input['cell_type'] ?? null;
        $editMode = $input['edit_mode'] ?? 'replace';

        if (empty($notebookPath)) {
            return ToolResult::error('Notebook path cannot be empty.');
        }

        if (!file_exists($notebookPath)) {
            return ToolResult::error("Notebook file not found: {$notebookPath}");
        }

        if (!str_ends_with($notebookPath, '.ipynb')) {
            return ToolResult::error('File must be a Jupyter notebook (.ipynb).');
        }

        if (!is_readable($notebookPath)) {
            return ToolResult::error("Notebook is not readable: {$notebookPath}");
        }

        if (!is_writable($notebookPath)) {
            return ToolResult::error("Notebook is not writable: {$notebookPath}");
        }

        // Load notebook
        $notebookContent = file_get_contents($notebookPath);
        if ($notebookContent === false) {
            return ToolResult::error("Failed to read notebook: {$notebookPath}");
        }

        $notebook = json_decode($notebookContent, true);
        if ($notebook === null) {
            return ToolResult::error('Invalid notebook format: Failed to parse JSON.');
        }

        if (!isset($notebook['cells']) || !is_array($notebook['cells'])) {
            return ToolResult::error('Invalid notebook format: No cells array found.');
        }

        // Find target cell
        $targetIndex = $this->findCellIndex($notebook['cells'], $cellIndex, $cellId);
        
        if ($editMode !== 'insert' && $targetIndex === null) {
            return ToolResult::error('Cell not found.');
        }

        // Perform the edit operation
        switch ($editMode) {
            case 'delete':
                if ($targetIndex === null) {
                    return ToolResult::error('Cell not found for deletion.');
                }
                array_splice($notebook['cells'], $targetIndex, 1);
                $message = "Deleted cell at index {$targetIndex}";
                break;

            case 'insert':
                if ($editMode === 'insert' && empty($newSource) && $newSource !== '0') {
                    return ToolResult::error('new_source is required for insert mode.');
                }
                
                $newCell = $this->createCell($newSource, $cellType ?? 'code');
                
                if ($targetIndex !== null) {
                    // Insert after the specified cell
                    array_splice($notebook['cells'], $targetIndex + 1, 0, [$newCell]);
                    $message = "Inserted new cell after index {$targetIndex}";
                } else {
                    // Insert at the beginning if no index specified
                    array_unshift($notebook['cells'], $newCell);
                    $message = "Inserted new cell at the beginning";
                }
                break;

            case 'replace':
            default:
                if ($targetIndex === null) {
                    return ToolResult::error('Cell not found for replacement.');
                }
                
                if ($editMode === 'replace' && empty($newSource) && $newSource !== '0') {
                    return ToolResult::error('new_source is required for replace mode.');
                }
                
                // Preserve cell metadata and update source
                $cell = &$notebook['cells'][$targetIndex];
                
                // Update source
                if (is_array($newSource)) {
                    $cell['source'] = $newSource;
                } else {
                    // Convert string to array of lines
                    $lines = explode("\n", $newSource);
                    $cell['source'] = array_map(function($line, $index) use ($lines) {
                        // Add newline except for last line
                        return $index < count($lines) - 1 ? $line . "\n" : $line;
                    }, $lines, array_keys($lines));
                }
                
                // Update cell type if specified
                if ($cellType !== null) {
                    $cell['cell_type'] = $cellType;
                    
                    // Adjust metadata based on cell type
                    if ($cellType === 'code') {
                        if (!isset($cell['execution_count'])) {
                            $cell['execution_count'] = null;
                        }
                        if (!isset($cell['outputs'])) {
                            $cell['outputs'] = [];
                        }
                    } else {
                        // Remove code-specific fields for non-code cells
                        unset($cell['execution_count']);
                        unset($cell['outputs']);
                    }
                }
                
                $message = "Replaced cell at index {$targetIndex}";
                break;
        }

        // Save the notebook
        $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $updatedContent = json_encode($notebook, $jsonOptions);
        
        if ($updatedContent === false) {
            return ToolResult::error('Failed to encode notebook to JSON.');
        }

        // Ensure file ends with newline (Jupyter convention)
        $updatedContent .= "\n";

        if (file_put_contents($notebookPath, $updatedContent) === false) {
            return ToolResult::error("Failed to write notebook: {$notebookPath}");
        }

        return ToolResult::success($message);
    }

    private function findCellIndex(array $cells, ?int $index, ?string $id): ?int
    {
        if ($index !== null) {
            // Use provided index if valid
            if ($index >= 0 && $index < count($cells)) {
                return $index;
            }
            return null;
        }

        if ($id !== null) {
            // Search by cell ID
            foreach ($cells as $i => $cell) {
                if (isset($cell['id']) && $cell['id'] === $id) {
                    return $i;
                }
                // Also check metadata.id for compatibility
                if (isset($cell['metadata']['id']) && $cell['metadata']['id'] === $id) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function createCell(string $source, string $cellType): array
    {
        // Convert source string to array format
        $lines = explode("\n", $source);
        $sourceArray = array_map(function($line, $index) use ($lines) {
            // Add newline except for last line
            return $index < count($lines) - 1 ? $line . "\n" : $line;
        }, $lines, array_keys($lines));

        $cell = [
            'cell_type' => $cellType,
            'metadata' => [],
            'source' => $sourceArray,
        ];

        // Add code-specific fields
        if ($cellType === 'code') {
            $cell['execution_count'] = null;
            $cell['outputs'] = [];
        }

        // Generate a simple ID (in real Jupyter, this would be a UUID)
        $cell['id'] = $this->generateCellId();

        return $cell;
    }

    private function generateCellId(): string
    {
        // Simple ID generation (Jupyter uses UUIDs)
        return 'cell_' . bin2hex(random_bytes(8));
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}