<?php

namespace SuperAgent\Optimization;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Tools\Tool;

/**
 * Dynamically selects a subset of tools to send to the LLM based on the
 * current task context, saving ~10K tokens per request by omitting tool
 * schemas that are unlikely to be needed in the next turn.
 */
class ToolSchemaFilter
{
    /** Tools typically needed during exploration/search phases. */
    private const EXPLORE_TOOLS = ['read', 'grep', 'glob', 'bash', 'web_search', 'web_fetch'];

    /** Tools typically needed during editing/writing phases. */
    private const EDIT_TOOLS = ['read', 'write', 'edit', 'bash', 'grep', 'glob'];

    /** Tools typically needed during planning phases. */
    private const PLAN_TOOLS = ['read', 'grep', 'glob', 'agent', 'enter_plan_mode', 'exit_plan_mode'];

    /** Tools that are always included regardless of phase detection. */
    private const ALWAYS_INCLUDE = ['read', 'bash'];

    /** Minimum result size — if filtering would leave fewer tools, skip filtering entirely. */
    private const MIN_TOOLS_THRESHOLD = 5;

    /** Map of tool names to the phase tool set they indicate. */
    private const TOOL_PHASE_MAP = [
        'edit'            => self::EDIT_TOOLS,
        'write'           => self::EDIT_TOOLS,
        'grep'            => self::EXPLORE_TOOLS,
        'glob'            => self::EXPLORE_TOOLS,
        'read'            => self::EXPLORE_TOOLS,
        'web_search'      => self::EXPLORE_TOOLS,
        'web_fetch'       => self::EXPLORE_TOOLS,
        'agent'           => self::PLAN_TOOLS,
        'enter_plan_mode' => self::PLAN_TOOLS,
        'exit_plan_mode'  => self::PLAN_TOOLS,
    ];

    public function __construct(
        private bool $enabled = true,
        private int $maxTools = 20,
    ) {
    }

    /**
     * Create an instance from the application configuration.
     *
     * Reads from config('superagent.optimization.selective_tool_schema').
     */
    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.optimization.selective_tool_schema') ?? []) : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxTools: (int) ($config['max_tools'] ?? 20),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Filter tools based on recent message history to predict what the model
     * needs next.
     *
     * Analyses the last 2 messages (looking for AssistantMessage tool-use
     * blocks) to determine the current phase (explore, edit, plan) and
     * returns only the tools relevant to that phase, plus any tools the
     * model recently used.
     *
     * @param  Tool[]    $allTools  All available Tool objects
     * @param  Message[] $messages  Current message history
     * @return Tool[]    Filtered subset of Tool objects
     */
    public function filter(array $allTools, array $messages): array
    {
        if (! $this->enabled || empty($allTools)) {
            return $allTools;
        }

        // Collect tool names used in the last 2 assistant messages.
        $recentToolNames = $this->extractRecentToolNames($messages, 2);

        // If there are no recent tool uses (first turn or text-only), return all tools.
        if (empty($recentToolNames)) {
            return $allTools;
        }

        // Determine which phase tool sets apply based on recently used tools.
        $allowedNames = $this->buildAllowedSet($recentToolNames);

        // Filter tools to the allowed set.
        $filtered = array_values(array_filter(
            $allTools,
            fn (Tool $tool) => isset($allowedNames[$tool->name()])
        ));

        // If filtering is too aggressive, return all tools unchanged.
        if (count($filtered) < self::MIN_TOOLS_THRESHOLD) {
            return $allTools;
        }

        // Cap at maxTools — tools in the allowed set are already sorted by
        // relevance (ALWAYS_INCLUDE first, then phase tools, then recent).
        if (count($filtered) > $this->maxTools) {
            $filtered = $this->capByRelevance($filtered, $allowedNames);
        }

        return $filtered;
    }

    /**
     * Walk backwards through messages and collect tool names from the last N
     * AssistantMessages.
     *
     * @return string[] Tool names used recently
     */
    private function extractRecentToolNames(array $messages, int $lookback): array
    {
        $toolNames = [];
        $assistantCount = 0;

        for ($i = count($messages) - 1; $i >= 0 && $assistantCount < $lookback; $i--) {
            $message = $messages[$i];

            if (! $message instanceof AssistantMessage) {
                continue;
            }

            $assistantCount++;

            foreach ($message->toolUseBlocks() as $block) {
                if ($block->toolName !== null) {
                    $toolNames[] = $block->toolName;
                }
            }
        }

        return $toolNames;
    }

    /**
     * Build the full set of allowed tool names from the detected phase(s) and
     * recent usage.
     *
     * @param  string[] $recentToolNames
     * @return array<string, true>  Map of tool name => true for fast lookup
     */
    private function buildAllowedSet(array $recentToolNames): array
    {
        $allowed = [];

        // Always include the baseline tools.
        foreach (self::ALWAYS_INCLUDE as $name) {
            $allowed[$name] = true;
        }

        // Include tools from every phase that recent tool use indicates.
        foreach ($recentToolNames as $toolName) {
            // The recently used tool itself should always be available.
            $allowed[$toolName] = true;

            // If this tool maps to a phase, include the entire phase set.
            if (isset(self::TOOL_PHASE_MAP[$toolName])) {
                foreach (self::TOOL_PHASE_MAP[$toolName] as $phaseToolName) {
                    $allowed[$phaseToolName] = true;
                }
            }
        }

        return $allowed;
    }

    /**
     * Cap the filtered tools at maxTools, prioritising ALWAYS_INCLUDE, then
     * phase-relevant tools, then recently-used tools.
     *
     * @param  Tool[]              $filtered
     * @param  array<string, true> $allowedNames
     * @return Tool[]
     */
    private function capByRelevance(array $filtered, array $allowedNames): array
    {
        $alwaysIncludeSet = array_flip(self::ALWAYS_INCLUDE);

        // Sort: ALWAYS_INCLUDE first, then the rest in original order.
        usort($filtered, function (Tool $a, Tool $b) use ($alwaysIncludeSet) {
            $aAlways = isset($alwaysIncludeSet[$a->name()]) ? 0 : 1;
            $bAlways = isset($alwaysIncludeSet[$b->name()]) ? 0 : 1;

            return $aAlways <=> $bAlways;
        });

        return array_slice($filtered, 0, $this->maxTools);
    }
}
