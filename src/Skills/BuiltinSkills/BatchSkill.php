<?php

declare(strict_types=1);

namespace SuperAgent\Skills\BuiltinSkills;

use SuperAgent\Skills\Skill;

/**
 * Batch skill ported from Claude Code.
 *
 * Research and plan a large-scale change, then execute it in parallel
 * across 5–30 isolated worktree agents that each open a PR.
 *
 * Three-phase workflow:
 *  1. Research & Plan — Enter plan mode, launch subagents to research scope,
 *     decompose into independent units, determine e2e test recipe
 *  2. Spawn Workers — One background agent per unit in isolated worktrees,
 *     all launched in a single message block for parallel execution
 *  3. Track Progress — Status table with PR links, updated as agents complete
 *
 * Usage: /batch <instruction>
 * Examples:
 *   /batch migrate from react to vue
 *   /batch replace all uses of lodash with native equivalents
 *   /batch add type annotations to all untyped function parameters
 */
class BatchSkill extends Skill
{
    private const MIN_AGENTS = 5;
    private const MAX_AGENTS = 30;

    public function name(): string
    {
        return 'batch';
    }

    public function description(): string
    {
        return 'Research and plan a large-scale change, then execute it in parallel across 5–30 isolated worktree agents that each open a PR.';
    }

    public function category(): string
    {
        return 'orchestration';
    }

    public function template(): string
    {
        $min = self::MIN_AGENTS;
        $max = self::MAX_AGENTS;

        return <<<TEMPLATE
# Batch: Parallel Work Orchestration

You are orchestrating a large, parallelizable change across this codebase.

## User Instruction

{{instruction}}

## Phase 1: Research and Plan (Plan Mode)

Call the `enter_plan_mode` tool now to enter plan mode, then:

1. **Understand the scope.** Launch one or more subagents (in the foreground — you need their results) to deeply research what this instruction touches. Find all the files, patterns, and call sites that need to change. Understand the existing conventions so the migration is consistent.

2. **Decompose into independent units.** Break the work into {$min}–{$max} self-contained units. Each unit must:
   - Be independently implementable in an isolated git worktree (no shared state with sibling units)
   - Be mergeable on its own without depending on another unit's PR landing first
   - Be roughly uniform in size (split large units, merge trivial ones)

   Scale the count to the actual work: few files → closer to {$min}; hundreds of files → closer to {$max}. Prefer per-directory or per-module slicing over arbitrary file lists.

3. **Determine the e2e test recipe.** Figure out how a worker can verify its change actually works end-to-end — not just that unit tests pass. Look for:
   - A browser-automation tool or skill (for UI changes: click through the affected flow, screenshot the result)
   - A CLI-verifier skill (for CLI changes: launch the app interactively, exercise the changed behavior)
   - A dev-server + curl pattern (for API changes: start the server, hit the affected endpoints)
   - An existing e2e/integration test suite the worker can run

   If you cannot find a concrete e2e path, use the `AskUserQuestion` tool to ask the user how to verify this change end-to-end. Offer 2–3 specific options based on what you found. Do not skip this — the workers cannot ask the user themselves.

   Write the recipe as a short, concrete set of steps that a worker can execute autonomously. Include any setup (start a dev server, build first) and the exact command/interaction to verify.

4. **Write the plan.** In your plan file, include:
   - A summary of what you found during research
   - A numbered list of work units — for each: a short title, the list of files/directories it covers, and a one-line description of the change
   - The e2e test recipe (or "skip e2e because …" if the user chose that)
   - The exact worker instructions you will give each agent (the shared template)

5. Call `exit_plan_mode` to present the plan for approval.

## Phase 2: Spawn Workers (After Plan Approval)

Once the plan is approved, spawn one background agent per work unit using the `Agent` tool. **All agents must use `isolation: "worktree"` and `run_in_background: true`.** Launch them all in a single message block so they run in parallel.

For each agent, the prompt must be fully self-contained. Include:
- The overall goal (the user's instruction)
- This unit's specific task (title, file list, change description — copied verbatim from your plan)
- Any codebase conventions you discovered that the worker needs to follow
- The e2e test recipe from your plan (or "skip e2e because …")
- The worker instructions below, copied verbatim:

```
After you finish implementing the change:
1. **Simplify** — Invoke the `Skill` tool with `skill: "simplify"` to review and clean up your changes.
2. **Run unit tests** — Run the project's test suite (check for package.json scripts, Makefile targets, or common commands like `npm test`, `bun test`, `pytest`, `go test`). If tests fail, fix them.
3. **Test end-to-end** — Follow the e2e test recipe from the coordinator's prompt (below). If the recipe says to skip e2e for this unit, skip it.
4. **Commit and push** — Commit all changes with a clear message, push the branch, and create a PR with `gh pr create`. Use a descriptive title. If `gh` is not available or the push fails, note it in your final message.
5. **Report** — End with a single line: `PR: <url>` so the coordinator can track it. If no PR was created, end with `PR: none — <reason>`.
```

Use `subagent_type: "general-purpose"` unless a more specific agent type fits.

## Phase 3: Track Progress

After launching all workers, render an initial status table:

| # | Unit | Status | PR |
|---|------|--------|----|
| 1 | <title> | running | — |
| 2 | <title> | running | — |

As background-agent completion notifications arrive, parse the `PR: <url>` line from each agent's result and re-render the table with updated status (`done` / `failed`) and PR links. Keep a brief failure note for any agent that did not produce a PR.

When all agents have reported, render the final table and a one-line summary (e.g., "22/24 units landed as PRs").
TEMPLATE;
    }

    public function parameters(): array
    {
        return [
            [
                'name' => 'instruction',
                'type' => 'string',
                'required' => true,
                'description' => 'Description of the batch change to make across the codebase',
            ],
        ];
    }

    public function requiredTools(): array
    {
        return [
            'enter_plan_mode',
            'exit_plan_mode',
            'Agent',
            'AskUserQuestion',
            'Skill',
        ];
    }

    public function example(): string
    {
        return "/batch instruction=\"migrate from react to vue\"\n"
            . "/batch instruction=\"replace all uses of lodash with native equivalents\"\n"
            . "/batch instruction=\"add type annotations to all untyped function parameters\"";
    }

    /**
     * Override execute to handle the free-form argument style.
     * /batch <instruction> maps to {{instruction}} in the template.
     */
    public function execute(array $args = []): string
    {
        // Support free-form: /batch migrate from react to vue
        if (isset($args['$ARGUMENTS']) && !isset($args['instruction'])) {
            $args['instruction'] = $args['$ARGUMENTS'];
        }

        if (empty($args['instruction'] ?? '')) {
            return $this->getMissingInstructionMessage();
        }

        // Check git repo
        if (!$this->isGitRepo()) {
            return $this->getNotGitRepoMessage();
        }

        return parent::execute($args);
    }

    private function isGitRepo(): bool
    {
        $cwd = getcwd() ?: '.';
        // Walk up to find .git
        $dir = $cwd;
        while ($dir !== '/' && $dir !== '') {
            if (is_dir($dir . '/.git')) {
                return true;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return false;
    }

    private function getNotGitRepoMessage(): string
    {
        return "This is not a git repository. The `/batch` command requires a git repo because it "
            . "spawns agents in isolated git worktrees and creates PRs from each. Initialize a repo "
            . "first, or run this from inside an existing one.";
    }

    private function getMissingInstructionMessage(): string
    {
        return "Provide an instruction describing the batch change you want to make.\n\n"
            . "Examples:\n"
            . "  /batch migrate from react to vue\n"
            . "  /batch replace all uses of lodash with native equivalents\n"
            . "  /batch add type annotations to all untyped function parameters";
    }
}
