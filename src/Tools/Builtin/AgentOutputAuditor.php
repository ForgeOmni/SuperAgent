<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Support\LanguageDetector;

/**
 * Scans a sub-agent's output directory after it exits and surfaces
 * contract violations the 0.8.9 productivity instrumentation
 * ({@see AgentTool::buildProductivityInfo}) can't catch.
 *
 * 0.8.9 answered *"did the child run tools and write files?"* — critical
 * for detecting the "model described the plan instead of executing it"
 * failure mode. This class answers the follow-up *"did the child write
 * the *right* files to the *right* places?"* A weak model routinely
 * exits with success=true AND non-empty filesWritten but stitches its
 * deliverables into:
 *
 *   - (a) non-whitelisted extensions — `generate_charts.py` helper
 *         scripts, `.html` dashboards when the pipeline expected `.md`
 *   - (b) consolidator-reserved filenames — `summary.md` / `摘要.md` /
 *         `mindmap.md` inside its own subdir, stepping on the
 *         orchestrator's consolidation pass
 *   - (c) sibling-role sub-directories — `ceo/`, `cfo/`, `marketing/`
 *         under its own output_subdir, fabricating reports for other
 *         agents
 *
 * Each violation class is independent and toggleable via constructor
 * parameters — callers with domain-specific contracts (Python code-gen
 * tasks, SQL-schema output, etc.) pass their own whitelist.
 *
 * The auditor **never modifies disk.** Warnings land in a returned list
 * and the orchestrator decides whether to re-dispatch, warn, or
 * proceed. This is the same "loud, not silent; observational, not
 * prescriptive" policy 0.8.9's {@see AgentTool::buildProductivityInfo}
 * established.
 *
 * Complementary to {@see self::guardBlock()} which *tries to prevent*
 * the violations by injecting a prompt preamble into the child's
 * task_prompt. The two are belt-and-braces: prompt prevents, auditor
 * detects the prompt failing.
 *
 * Lifted in concept from SuperAICore's
 * `AgentSpawn\Orchestrator::auditAgentOutput()` +
 * `AgentSpawn\SpawnPlan::appendGuards()`, generalised so any SDK
 * consumer can use it without the host-side orchestration dependencies.
 */
final class AgentOutputAuditor
{
    /**
     * Default consolidator-reserved filenames. `summary.md` / `摘要.md`
     * are the canonical SuperAgent / SuperAICore consolidation filenames;
     * the rest match the SuperAICore /team pipeline. Callers with
     * different conventions override via constructor.
     *
     * @var list<string>
     */
    public const DEFAULT_RESERVED_FILENAMES = [
        'summary.md', 'mindmap.md', 'flowchart.md',
        '摘要.md', '思维导图.md', '流程图.md',
        'summary.html', 'mindmap.html', 'flowchart.html',
    ];

    /**
     * Default sibling-role names — the classic SaaS exec roster the
     * /team skill uses. Hosts with a different role vocabulary pass
     * their own; pass `[]` to disable the sibling-role check entirely.
     *
     * @var list<string>
     */
    public const DEFAULT_SIBLING_ROLE_NAMES = [
        'ceo', 'cfo', 'cto', 'coo', 'cmo',
        'marketing', 'sales', 'legal', 'hr', 'ops',
        'product', 'qa', 'compliance', 'growth', 'data',
        'social', 'pr', 'review',
    ];

    /**
     * Sub-directories never flagged as sibling-role even if their name
     * matches the heuristic. `_signals` covers the IAP pattern; the
     * `_`-prefix covers meta-directories in general; `.`-prefix covers
     * hidden / tooling directories.
     *
     * @var list<string>
     */
    private const ALWAYS_IGNORED_SUBDIRS = ['_signals', '.git', 'node_modules', 'vendor'];

    /**
     * @param list<string>|null $allowedExtensions  null disables the check
     * @param list<string>      $reservedFilenames  empty list disables the check
     * @param list<string>      $siblingRoleNames   empty list disables the check
     */
    public function __construct(
        private readonly ?array $allowedExtensions = null,
        private readonly array $reservedFilenames = self::DEFAULT_RESERVED_FILENAMES,
        private readonly array $siblingRoleNames = self::DEFAULT_SIBLING_ROLE_NAMES,
    ) {}

    /**
     * Audit `$subdir` against the configured policy. Returns a list of
     * human-readable warning strings. Missing / unreadable directories
     * return `[]` — callers that require the dir to exist should check
     * separately.
     *
     * @return list<string>
     */
    public function audit(string $subdir, string $agentName): array
    {
        if (! is_dir($subdir) || ! is_readable($subdir)) {
            return [];
        }

        $badExt = [];
        $reserved = [];
        $siblingDirs = [];

        try {
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($subdir, \FilesystemIterator::SKIP_DOTS)
            );
        } catch (\UnexpectedValueException) {
            // Permission denied mid-walk — return whatever we gathered
            // pre-throw. Audit is observational; a partial result is
            // still more useful than nothing.
            return [];
        }

        foreach ($rii as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isDir()) continue;

            $rel = ltrim(str_replace($subdir, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR);
            $base = $fileInfo->getFilename();
            $ext = strtolower($fileInfo->getExtension());

            if ($this->allowedExtensions !== null && $ext !== '' && ! in_array($ext, $this->allowedExtensions, true)) {
                $badExt[] = $rel;
            }
            if ($this->reservedFilenames !== [] && in_array($base, $this->reservedFilenames, true)) {
                $reserved[] = $rel;
            }
        }

        // Sibling-role check operates on the *direct* children of the
        // agent's subdir only — deeply-nested role-named dirs under
        // legitimate content (e.g. a report that has a `reviews/` or
        // `sales/` section) are fine.
        if ($this->siblingRoleNames !== []) {
            foreach (new \DirectoryIterator($subdir) as $entry) {
                if (! $entry->isDir() || $entry->isDot()) continue;
                $name = $entry->getFilename();
                if ($name === $agentName) continue;
                if (in_array($name, self::ALWAYS_IGNORED_SUBDIRS, true)) continue;
                if (str_starts_with($name, '_') || str_starts_with($name, '.')) continue;

                if (
                    in_array(strtolower($name), $this->siblingRoleNames, true)
                    || preg_match('/^[a-z][a-z0-9-]*-[a-z0-9-]+$/', $name) === 1
                ) {
                    $siblingDirs[] = $name;
                }
            }
        }

        $warnings = [];
        if ($badExt)      $warnings[] = 'non-whitelisted extensions: ' . implode(', ', array_slice($badExt, 0, 10));
        if ($reserved)    $warnings[] = 'consolidator-reserved filenames inside agent subdir: ' . implode(', ', $reserved);
        if ($siblingDirs) $warnings[] = 'sibling-role sub-directories under agent output_subdir: ' . implode(', ', $siblingDirs);

        return $warnings;
    }

    /**
     * Marker sentinel prepended to the guard block. Lets the same
     * `task_prompt` flow through {@see self::guardBlock()} twice without
     * double-appending.
     */
    public const GUARD_MARKER = '## [SuperAgent host-injected per-agent guard]';

    /**
     * Build the CJK-aware guard block text to prepend / append to a
     * sub-agent's `task_prompt`. Idempotent via {@see self::GUARD_MARKER}.
     *
     * Callers typically inject this into the child's prompt as soon as
     * the agent is dispatched — before the SDK's
     * {@see AgentTool::execute()} fires the spawn. The four rules:
     *
     *   1. Stay in your lane — no sibling-role sub-directories, no
     *      writing files for other agents
     *   2. No consolidation — reserved filenames are the orchestrator's
     *      contract, not the agent's
     *   3. Language uniformity — body, headings, filenames all in one
     *      language; proper nouns / URLs / numbers stay original
     *   4. Extension whitelist — explicit list when the caller
     *      configured one, otherwise the default `.md / .csv / .png` trio
     *
     * Returns empty string when `$taskPrompt` already contains the
     * marker — prevents runaway growth if the same prompt is piped
     * through several times.
     */
    public static function guardBlock(
        string $taskPrompt,
        string $agentName,
        array $allowedExtensions = ['md', 'csv', 'png'],
        array $reservedFilenames = ['summary.md', '摘要.md', 'mindmap.md', '思维导图.md', 'flowchart.md', '流程图.md'],
    ): string {
        if (str_contains($taskPrompt, self::GUARD_MARKER)) {
            return '';
        }

        $extStr = '`' . implode('` / `', array_map(
            static fn(string $e): string => '.' . ltrim($e, '.'),
            $allowedExtensions
        )) . '`';

        $reservedStr = '`' . implode('` / `', $reservedFilenames) . '`';

        $isChinese = LanguageDetector::isCjk($taskPrompt);

        if ($isChinese) {
            return sprintf(
                self::GUARD_MARKER . "\n\n" .
                "以下规则为宿主强制注入，不可忽略。你是 `%s`，专注你自己的分析。\n\n" .
                "- **角色边界**：只写自己 `output_subdir` 下的文件，不要创建其它角色子目录（ceo/cfo/cto/marketing/ 等），不要替别人写报告。\n" .
                "- **不写整合**：%s 由宿主之后的 consolidation 统一写，你不碰。\n" .
                "- **语言一致（包含文件名）**：markdown 正文、section 标题、CSV 表头、非专有名词与文件名——全部用中文。公司名、URL、数字可保留原文。\n" .
                "- **扩展名只有** %s。图表直接渲染成 PNG 用 write 工具保存。\n" .
                "- **不要为工具失败道歉**：如果某个工具报错或限流，换其它工具重试。不要在报告开头写\"由于工具限制\"这类元信息道歉——那会污染分析正文。",
                $agentName,
                $reservedStr,
                $extStr
            );
        }

        return sprintf(
            self::GUARD_MARKER . "\n\n" .
            "Host-injected rules, non-negotiable. You are `%s`, focused on your own analysis.\n\n" .
            "- **Stay in your lane**: only files under your own `output_subdir`; no sibling-role sub-dirs (ceo/cfo/cto/marketing/…); no writing for other agents.\n" .
            "- **No consolidation**: %s are the consolidator pass's job, not yours.\n" .
            "- **Language uniformity (filenames too)**: markdown body, section headings, CSV headers and non-proper-noun cells, AND filenames — all in one language. Proper nouns, URLs, numbers stay original.\n" .
            "- **Extensions allowed**: %s only. Render charts directly to PNG via the write tool.\n" .
            "- **Don't apologize for tool failures**: if one tool errors or hits rate limits, switch to another and retry. Do NOT open your report with \"due to tool limitations…\" — that contaminates the analysis.",
            $agentName,
            $reservedStr,
            $extStr
        );
    }
}
