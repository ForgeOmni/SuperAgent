<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

/**
 * Comprehensive bash command security validator ported from Claude Code.
 *
 * Implements 23 security checks that detect:
 * - Shell injection via command substitution, IFS injection, proc environ
 * - Parser differential attacks (zsh vs bash, shell-quote vs bash)
 * - Obfuscated flags via ANSI-C quoting, empty quote pairs, etc.
 * - Dangerous patterns: redirections, brace expansion, control chars
 * - Incomplete/fragment commands that suggest injection attempts
 *
 * Each check returns a SecurityCheckResult with a numeric ID for logging.
 */
class BashSecurityValidator
{
    // --- Check IDs (numeric identifiers matching CC) ---
    public const CHECK_INCOMPLETE_COMMANDS = 1;
    public const CHECK_JQ_SYSTEM_FUNCTION = 2;
    public const CHECK_JQ_FILE_ARGUMENTS = 3;
    public const CHECK_OBFUSCATED_FLAGS = 4;
    public const CHECK_SHELL_METACHARACTERS = 5;
    public const CHECK_DANGEROUS_VARIABLES = 6;
    public const CHECK_NEWLINES = 7;
    public const CHECK_COMMAND_SUBSTITUTION = 8;
    public const CHECK_INPUT_REDIRECTION = 9;
    public const CHECK_OUTPUT_REDIRECTION = 10;
    public const CHECK_IFS_INJECTION = 11;
    public const CHECK_GIT_COMMIT_SUBSTITUTION = 12;
    public const CHECK_PROC_ENVIRON_ACCESS = 13;
    public const CHECK_MALFORMED_TOKEN_INJECTION = 14;
    public const CHECK_BACKSLASH_ESCAPED_WHITESPACE = 15;
    public const CHECK_BRACE_EXPANSION = 16;
    public const CHECK_CONTROL_CHARACTERS = 17;
    public const CHECK_UNICODE_WHITESPACE = 18;
    public const CHECK_MID_WORD_HASH = 19;
    public const CHECK_ZSH_DANGEROUS_COMMANDS = 20;
    public const CHECK_BACKSLASH_ESCAPED_OPERATORS = 21;
    public const CHECK_COMMENT_QUOTE_DESYNC = 22;
    public const CHECK_QUOTED_NEWLINE = 23;

    /** Unicode whitespace characters */
    private const UNICODE_WS_PATTERN = '/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u';

    /** Control characters (excluding tab \x09 and newline \x0A, \x0D) */
    private const CONTROL_CHAR_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /** Shell operators */
    private const SHELL_OPERATORS = [';', '|', '&', '<', '>'];

    /** Zsh dangerous commands */
    private const ZSH_DANGEROUS_COMMANDS = [
        'zmodload', 'emulate', 'sysopen', 'sysread', 'syswrite', 'sysseek',
        'zpty', 'ztcp', 'zsocket', 'mapfile', 'zf_rm', 'zf_mv', 'zf_ln',
        'zf_chmod', 'zf_chown', 'zf_mkdir', 'zf_rmdir', 'zf_chgrp',
    ];

    /** Command substitution patterns */
    private const COMMAND_SUBSTITUTION_PATTERNS = [
        '<(' => 'process substitution',
        '>(' => 'process substitution',
        '=(' => 'Zsh process substitution',
        '$(' => '$() command substitution',
        '${' => '${} parameter substitution',
        '$[' => '$[] legacy arithmetic expansion',
        '~[' => 'Zsh-style parameter expansion',
        '(e:' => 'Zsh-style glob qualifiers',
        '(+' => 'Zsh glob qualifier with command execution',
        '} always {' => 'Zsh always block',
        '<#' => 'PowerShell comment syntax',
    ];

    /**
     * Validate a bash command for security.
     *
     * @return SecurityCheckResult 'allow' if safe, 'deny' with check info if dangerous, 'passthrough' if no issues found
     */
    public function validate(string $command): SecurityCheckResult
    {
        $command = $command;

        // Extract context used by multiple validators
        $context = $this->buildContext($command);

        // --- Control characters (always checked first) ---
        if (preg_match(self::CONTROL_CHAR_PATTERN, $command)) {
            return SecurityCheckResult::deny(self::CHECK_CONTROL_CHARACTERS, 'Command contains control characters');
        }

        // --- Early validators (can short-circuit with allow) ---
        $result = $this->validateEmpty($context);
        if ($result !== null) return $result;

        $result = $this->validateIncompleteCommands($context);
        if ($result !== null) return $result;

        $result = $this->validateSafeCommandSubstitution($context);
        if ($result !== null) return $result;

        $result = $this->validateGitCommit($context);
        if ($result !== null) return $result;

        // --- Main validators ---
        // Collect first denial, but defer non-misparsing checks
        $validators = [
            'validateJqCommand',
            'validateObfuscatedFlags',
            'validateShellMetacharacters',
            'validateDangerousVariables',
            'validateCommentQuoteDesync',
            'validateQuotedNewline',
            'validateCarriageReturn',
            'validateNewlines',
            'validateIFSInjection',
            'validateProcEnvironAccess',
            'validateCommandSubstitution',
            'validateInputRedirection',
            'validateOutputRedirection',
            'validateBackslashEscapedWhitespace',
            'validateBackslashEscapedOperators',
            'validateUnicodeWhitespace',
            'validateMidWordHash',
            'validateBraceExpansion',
            'validateZshDangerousCommands',
            'validateMalformedTokenInjection',
        ];

        foreach ($validators as $validator) {
            $result = $this->$validator($context);
            if ($result !== null) {
                return $result;
            }
        }

        return SecurityCheckResult::passthrough();
    }

    // ----------------------------------------------------------------
    // Context building
    // ----------------------------------------------------------------

    private function buildContext(string $command): ValidationContext
    {
        $baseCommand = $this->extractBaseCommand($command);
        $unquoted = $this->extractQuotedContent($command);

        return new ValidationContext(
            originalCommand: $command,
            baseCommand: $baseCommand,
            unquotedContent: $unquoted['withDoubleQuotes'],
            fullyUnquotedContent: $unquoted['fullyUnquoted'],
            unquotedKeepQuoteChars: $unquoted['keepQuoteChars'],
        );
    }

    private function extractBaseCommand(string $command): string
    {
        $trimmed = trim($command);
        // Strip leading env var assignments
        $trimmed = preg_replace('/^(\w+=\S+\s+)+/', '', $trimmed);
        // Get first word
        $parts = preg_split('/\s+/', $trimmed, 2);
        return $parts[0] ?? '';
    }

    /**
     * Extract content with different quoting levels.
     */
    private function extractQuotedContent(string $command): array
    {
        $withDoubleQuotes = '';
        $fullyUnquoted = '';
        $keepQuoteChars = '';

        $inSingle = false;
        $inDouble = false;
        $len = strlen($command);

        for ($i = 0; $i < $len; $i++) {
            $ch = $command[$i];
            $prev = $i > 0 ? $command[$i - 1] : '';

            if ($ch === '\\' && !$inSingle && $i + 1 < $len) {
                $next = $command[$i + 1];
                $withDoubleQuotes .= $next;
                $fullyUnquoted .= $next;
                $keepQuoteChars .= '\\' . $next;
                $i++;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $keepQuoteChars .= $ch;
                continue;
            }

            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $keepQuoteChars .= $ch;
                continue;
            }

            if ($inSingle) {
                // Inside single quotes: add to double-quoted and fully-unquoted
                $withDoubleQuotes .= $ch;
                $fullyUnquoted .= $ch;
                $keepQuoteChars .= $ch;
            } elseif ($inDouble) {
                // Inside double quotes: add to double-quoted version
                $withDoubleQuotes .= $ch;
                $fullyUnquoted .= $ch;
                $keepQuoteChars .= $ch;
            } else {
                // Outside quotes
                $withDoubleQuotes .= $ch;
                $fullyUnquoted .= $ch;
                $keepQuoteChars .= $ch;
            }
        }

        return [
            'withDoubleQuotes' => $withDoubleQuotes,
            'fullyUnquoted' => $fullyUnquoted,
            'keepQuoteChars' => $keepQuoteChars,
        ];
    }

    // ----------------------------------------------------------------
    // Early validators
    // ----------------------------------------------------------------

    private function validateEmpty(ValidationContext $ctx): ?SecurityCheckResult
    {
        if (trim($ctx->originalCommand) === '') {
            return SecurityCheckResult::allow();
        }
        return null;
    }

    private function validateIncompleteCommands(ValidationContext $ctx): ?SecurityCheckResult
    {
        $cmd = $ctx->originalCommand;

        // Commands starting with tab (likely fragment)
        if (str_starts_with($cmd, "\t")) {
            return SecurityCheckResult::deny(self::CHECK_INCOMPLETE_COMMANDS, 'Command starts with tab (likely fragment)');
        }

        // Commands starting with a flag (no command)
        if (preg_match('/^\s*-/', $cmd)) {
            return SecurityCheckResult::deny(self::CHECK_INCOMPLETE_COMMANDS, 'Command starts with flag (no command name)');
        }

        // Commands starting with operators
        if (preg_match('/^\s*(&&|\|\||;|>>|<)/', $cmd)) {
            return SecurityCheckResult::deny(self::CHECK_INCOMPLETE_COMMANDS, 'Command starts with operator (likely fragment)');
        }

        return null;
    }

    private function validateSafeCommandSubstitution(ValidationContext $ctx): ?SecurityCheckResult
    {
        // Check for safe heredoc pattern: $(cat <<'DELIM'...DELIM)
        if ($this->hasSafeHeredocSubstitution($ctx->originalCommand)) {
            return SecurityCheckResult::allow();
        }
        return null;
    }

    private function validateGitCommit(ValidationContext $ctx): ?SecurityCheckResult
    {
        if (!preg_match('/^\s*git\s+commit\b/', $ctx->originalCommand)) {
            return null;
        }

        // Check for command substitution in commit message
        if (preg_match('/\$\(/', $ctx->unquotedContent) ||
            preg_match('/`/', $ctx->unquotedContent) ||
            preg_match('/\$\{/', $ctx->unquotedContent)) {
            return SecurityCheckResult::deny(
                self::CHECK_GIT_COMMIT_SUBSTITUTION,
                'Git commit message contains command substitution',
            );
        }

        return null;
    }

    // ----------------------------------------------------------------
    // Main validators
    // ----------------------------------------------------------------

    private function validateJqCommand(ValidationContext $ctx): ?SecurityCheckResult
    {
        if ($ctx->baseCommand !== 'jq') {
            return null;
        }

        // Block system() function
        if (preg_match('/\bsystem\s*\(/', $ctx->originalCommand)) {
            return SecurityCheckResult::deny(self::CHECK_JQ_SYSTEM_FUNCTION, 'jq system() function detected');
        }

        // Block dangerous file flags
        if (preg_match('/\s(-f|--from-file|--rawfile|--slurpfile|-L|--library-path)\b/', $ctx->originalCommand)) {
            return SecurityCheckResult::deny(self::CHECK_JQ_FILE_ARGUMENTS, 'jq file argument flags detected');
        }

        return null;
    }

    private function validateObfuscatedFlags(ValidationContext $ctx): ?SecurityCheckResult
    {
        $cmd = $ctx->originalCommand;

        // ANSI-C quoting: $'...'
        if (preg_match("/\\$'[^']*'/", $cmd)) {
            return SecurityCheckResult::deny(self::CHECK_OBFUSCATED_FLAGS, 'ANSI-C quoting detected ($\'...\')');
        }

        // Locale quoting: $"..."
        if (preg_match('/\$"[^"]*"/', $cmd)) {
            return SecurityCheckResult::deny(self::CHECK_OBFUSCATED_FLAGS, 'Locale quoting detected ($"...")');
        }

        // Empty quote pairs followed by dash (flag obfuscation)
        // e.g., """-rf" or ''-rf
        if (preg_match('/(?:\'\'|"")\s*-/', $cmd)) {
            return SecurityCheckResult::deny(self::CHECK_OBFUSCATED_FLAGS, 'Empty quotes followed by flag');
        }

        // Quoted flags: detect flags split across quote boundaries
        // e.g., "-"rf or '-'rf
        if (preg_match('/["\']+-[a-zA-Z]/', $cmd) && !preg_match('/cut\s+-d/', $cmd)) {
            // Exclude cut -d'<delimiter>' pattern
            if (!preg_match('/(-d\s*["\'][^"\']*["\'])/', $cmd)) {
                return SecurityCheckResult::deny(self::CHECK_OBFUSCATED_FLAGS, 'Quote-obfuscated flag detected');
            }
        }

        // 3+ consecutive quotes at word start
        if (preg_match('/(?:^|\s)(?:["\']){3,}/', $cmd)) {
            return SecurityCheckResult::deny(self::CHECK_OBFUSCATED_FLAGS, 'Excessive consecutive quotes');
        }

        return null;
    }

    private function validateShellMetacharacters(ValidationContext $ctx): ?SecurityCheckResult
    {
        $cmd = $ctx->originalCommand;

        // Check for metacharacters in find-style arguments
        if (preg_match('/(-name|-path|-iname|-regex)\s+/', $cmd)) {
            // These flags accept glob patterns, metacharacters are expected
            return null;
        }

        // Check for unquoted semicolons, pipes, ampersands in arguments
        $stripped = $this->stripSafeRedirections($ctx->unquotedContent);
        if (preg_match('/[;&|]/', $stripped)) {
            return SecurityCheckResult::deny(
                self::CHECK_SHELL_METACHARACTERS,
                'Shell metacharacters in arguments',
            );
        }

        return null;
    }

    private function validateDangerousVariables(ValidationContext $ctx): ?SecurityCheckResult
    {
        $content = $ctx->unquotedContent;

        // Variables in redirections or pipes: | $VAR or $VAR |
        if (preg_match('/[<>|]\s*\$\w/', $content) || preg_match('/\$\w+\s*[|<>]/', $content)) {
            return SecurityCheckResult::deny(
                self::CHECK_DANGEROUS_VARIABLES,
                'Variable in redirection or pipe context',
            );
        }

        return null;
    }

    private function validateCommentQuoteDesync(ValidationContext $ctx): ?SecurityCheckResult
    {
        $cmd = $ctx->originalCommand;

        // Check for quote characters inside # comments (defense against quote tracker desync)
        // Match lines that start with # and contain ' or "
        if (preg_match('/(?:^|\n)\s*#[^\n]*[\'"]/', $cmd)) {
            // Only flag if there are also non-comment quotes in the command
            $withoutComments = preg_replace('/(?:^|\n)\s*#[^\n]*/', '', $cmd);
            if (preg_match('/[\'"]/', $withoutComments)) {
                return SecurityCheckResult::deny(
                    self::CHECK_COMMENT_QUOTE_DESYNC,
                    'Quote characters in comment may desync quote tracking',
                );
            }
        }

        return null;
    }

    private function validateQuotedNewline(ValidationContext $ctx): ?SecurityCheckResult
    {
        $cmd = $ctx->originalCommand;

        // Newlines inside quotes followed by #-prefixed lines
        if (preg_match('/["\'][^"\']*\n\s*#/', $cmd)) {
            return SecurityCheckResult::deny(
                self::CHECK_QUOTED_NEWLINE,
                'Quoted newline followed by comment line',
            );
        }

        return null;
    }

    private function validateCarriageReturn(ValidationContext $ctx): ?SecurityCheckResult
    {
        // Carriage return outside double quotes (parser differential)
        if (str_contains($ctx->fullyUnquotedContent, "\r")) {
            return SecurityCheckResult::deny(
                self::CHECK_NEWLINES,
                'Carriage return detected (parser differential risk)',
            );
        }

        return null;
    }

    private function validateNewlines(ValidationContext $ctx): ?SecurityCheckResult
    {
        $content = $ctx->fullyUnquotedContent;

        // Allow backslash-newline continuations
        $stripped = preg_replace('/\\\\\n/', '', $content);

        // Flag remaining newlines followed by non-whitespace
        if (preg_match('/\n\s*\S/', $stripped)) {
            return SecurityCheckResult::deny(
                self::CHECK_NEWLINES,
                'Newline separating commands detected',
            );
        }

        return null;
    }

    private function validateIFSInjection(ValidationContext $ctx): ?SecurityCheckResult
    {
        $cmd = $ctx->originalCommand;

        if (preg_match('/\$IFS/', $cmd) || preg_match('/\$\{[^}]*IFS[^}]*\}/', $cmd)) {
            return SecurityCheckResult::deny(self::CHECK_IFS_INJECTION, '$IFS injection detected');
        }

        return null;
    }

    private function validateProcEnvironAccess(ValidationContext $ctx): ?SecurityCheckResult
    {
        if (preg_match('#/proc/[^/]*/environ#', $ctx->originalCommand)) {
            return SecurityCheckResult::deny(
                self::CHECK_PROC_ENVIRON_ACCESS,
                '/proc/*/environ access detected',
            );
        }

        return null;
    }

    private function validateCommandSubstitution(ValidationContext $ctx): ?SecurityCheckResult
    {
        $content = $ctx->unquotedContent;

        // Check for backticks
        if (str_contains($content, '`')) {
            return SecurityCheckResult::deny(
                self::CHECK_COMMAND_SUBSTITUTION,
                'Backtick command substitution detected',
            );
        }

        // Check all command substitution patterns
        foreach (self::COMMAND_SUBSTITUTION_PATTERNS as $pattern => $description) {
            if (str_contains($content, $pattern)) {
                return SecurityCheckResult::deny(
                    self::CHECK_COMMAND_SUBSTITUTION,
                    "{$description} detected",
                );
            }
        }

        return null;
    }

    private function validateInputRedirection(ValidationContext $ctx): ?SecurityCheckResult
    {
        $stripped = $this->stripSafeRedirections($ctx->unquotedContent);

        if (preg_match('/<(?![<(])/', $stripped)) {
            return SecurityCheckResult::deny(
                self::CHECK_INPUT_REDIRECTION,
                'Input redirection detected',
            );
        }

        return null;
    }

    private function validateOutputRedirection(ValidationContext $ctx): ?SecurityCheckResult
    {
        $stripped = $this->stripSafeRedirections($ctx->unquotedContent);

        if (preg_match('/>(?![>(])/', $stripped)) {
            return SecurityCheckResult::deny(
                self::CHECK_OUTPUT_REDIRECTION,
                'Output redirection detected',
            );
        }

        return null;
    }

    private function validateBackslashEscapedWhitespace(ValidationContext $ctx): ?SecurityCheckResult
    {
        if ($this->hasBackslashEscapedWhitespace($ctx->originalCommand)) {
            return SecurityCheckResult::deny(
                self::CHECK_BACKSLASH_ESCAPED_WHITESPACE,
                'Backslash-escaped whitespace detected (path traversal risk)',
            );
        }

        return null;
    }

    private function validateBackslashEscapedOperators(ValidationContext $ctx): ?SecurityCheckResult
    {
        if ($this->hasBackslashEscapedOperator($ctx->originalCommand)) {
            return SecurityCheckResult::deny(
                self::CHECK_BACKSLASH_ESCAPED_OPERATORS,
                'Backslash-escaped shell operator detected',
            );
        }

        return null;
    }

    private function validateUnicodeWhitespace(ValidationContext $ctx): ?SecurityCheckResult
    {
        if (preg_match(self::UNICODE_WS_PATTERN, $ctx->originalCommand)) {
            return SecurityCheckResult::deny(
                self::CHECK_UNICODE_WHITESPACE,
                'Unicode whitespace character detected',
            );
        }

        return null;
    }

    private function validateMidWordHash(ValidationContext $ctx): ?SecurityCheckResult
    {
        // # preceded by non-whitespace (not ${#)
        $content = $ctx->fullyUnquotedContent;
        if (preg_match('/\S#(?!\{)/', $content) && !preg_match('/\$\{#/', $content)) {
            return SecurityCheckResult::deny(
                self::CHECK_MID_WORD_HASH,
                'Mid-word # detected (shell parsing differential)',
            );
        }

        return null;
    }

    private function validateBraceExpansion(ValidationContext $ctx): ?SecurityCheckResult
    {
        $content = $ctx->fullyUnquotedContent;

        // Comma-separated brace expansion: {a,b,c}
        if (preg_match('/\{[^}]*,[^}]*\}/', $content)) {
            return SecurityCheckResult::deny(
                self::CHECK_BRACE_EXPANSION,
                'Brace expansion with comma detected',
            );
        }

        // Sequence brace expansion: {1..5}
        if (preg_match('/\{[^}]*\.\.[^}]*\}/', $content)) {
            return SecurityCheckResult::deny(
                self::CHECK_BRACE_EXPANSION,
                'Sequence brace expansion detected',
            );
        }

        return null;
    }

    private function validateZshDangerousCommands(ValidationContext $ctx): ?SecurityCheckResult
    {
        $base = $ctx->baseCommand;

        // Strip zsh precommand modifiers
        $stripped = preg_replace('/^(command|builtin|exec|noglob|nocorrect)\s+/', '', trim($ctx->originalCommand));
        $parts = preg_split('/\s+/', $stripped, 2);
        $effectiveCmd = $parts[0] ?? '';

        if (in_array($effectiveCmd, self::ZSH_DANGEROUS_COMMANDS, true)) {
            return SecurityCheckResult::deny(
                self::CHECK_ZSH_DANGEROUS_COMMANDS,
                "Dangerous Zsh command: {$effectiveCmd}",
            );
        }

        // fc -e (editor execution)
        if ($effectiveCmd === 'fc' && preg_match('/\s-e\b/', $ctx->originalCommand)) {
            return SecurityCheckResult::deny(
                self::CHECK_ZSH_DANGEROUS_COMMANDS,
                'fc -e (editor execution) detected',
            );
        }

        return null;
    }

    private function validateMalformedTokenInjection(ValidationContext $ctx): ?SecurityCheckResult
    {
        $cmd = $ctx->originalCommand;

        // Check for unbalanced delimiters combined with command separators
        $singleQuotes = substr_count($cmd, "'");
        $doubleQuotes = substr_count($cmd, '"');
        $openParens = substr_count($cmd, '(');
        $closeParens = substr_count($cmd, ')');

        $hasUnbalanced = ($singleQuotes % 2 !== 0)
            || ($doubleQuotes % 2 !== 0)
            || ($openParens !== $closeParens);

        if ($hasUnbalanced) {
            // Only flag if there are also command separators
            if (preg_match('/[;&|]/', $cmd)) {
                return SecurityCheckResult::deny(
                    self::CHECK_MALFORMED_TOKEN_INJECTION,
                    'Unbalanced delimiters with command separators',
                );
            }
        }

        return null;
    }

    // ----------------------------------------------------------------
    // Helper methods
    // ----------------------------------------------------------------

    /**
     * Strip safe redirections that don't need security checks.
     */
    private function stripSafeRedirections(string $content): string
    {
        // 2>&1
        $content = preg_replace('/2>&1(?=\s|$)/', '', $content);
        // >/dev/null, [012]>/dev/null
        $content = preg_replace('/[012]?>\s*\/dev\/null(?=\s|$)/', '', $content);
        // </dev/null
        $content = preg_replace('/<\s*\/dev\/null(?=\s|$)/', '', $content);

        return $content;
    }

    /**
     * Detect backslash-escaped whitespace outside quotes.
     */
    private function hasBackslashEscapedWhitespace(string $command): bool
    {
        $inSingle = false;
        $inDouble = false;
        $len = strlen($command);

        for ($i = 0; $i < $len; $i++) {
            $ch = $command[$i];

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if (!$inSingle && !$inDouble && $ch === '\\' && $i + 1 < $len) {
                $next = $command[$i + 1];
                if ($next === ' ' || $next === "\t") {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect backslash-escaped shell operators outside quotes.
     */
    private function hasBackslashEscapedOperator(string $command): bool
    {
        $operators = [';', '|', '&', '<', '>'];
        $inSingle = false;
        $inDouble = false;
        $len = strlen($command);

        for ($i = 0; $i < $len; $i++) {
            $ch = $command[$i];

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if (!$inSingle && !$inDouble && $ch === '\\' && $i + 1 < $len) {
                $next = $command[$i + 1];
                if (in_array($next, $operators, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check for safe heredoc-in-substitution pattern: $(cat <<'DELIM'...DELIM)
     */
    private function hasSafeHeredocSubstitution(string $command): bool
    {
        // Match pattern: $(cat <<'DELIM' or $(cat <<"DELIM" or $(cat <<\DELIM
        return (bool) preg_match('/\$\(\s*cat\s+<<\\\\?[\'"][A-Za-z_]+[\'"]/', $command);
    }

    // ----------------------------------------------------------------
    // Read-only command classification
    // ----------------------------------------------------------------

    /** Prefixes of commands considered read-only (safe to auto-allow) */
    private const READ_ONLY_PREFIXES = [
        // Git read-only
        'git status', 'git diff', 'git log', 'git show', 'git branch',
        'git tag', 'git remote', 'git describe', 'git rev-parse',
        'git rev-list', 'git shortlog', 'git stash list',
        // Package managers (read-only)
        'npm list', 'npm view', 'npm info', 'npm outdated', 'npm ls',
        'yarn list', 'yarn info', 'yarn why',
        'composer show', 'composer info',
        'pip list', 'pip show', 'pip freeze',
        'cargo metadata',
        // Container (read-only)
        'docker ps', 'docker images', 'docker logs', 'docker inspect',
        // GitHub CLI (read-only)
        'gh pr list', 'gh pr view', 'gh pr status', 'gh pr checks',
        'gh issue list', 'gh issue view', 'gh issue status',
        'gh run list', 'gh run view',
        'gh api',
        // Type checkers / linters (read-only)
        'pyright', 'mypy', 'tsc --noEmit', 'eslint', 'phpstan', 'psalm',
        // Basic read-only commands
        'ls', 'cat', 'head', 'tail', 'less', 'more',
        'grep', 'rg', 'find', 'fd', 'ag',
        'wc', 'sort', 'uniq', 'diff', 'comm',
        'file', 'stat', 'du', 'df',
        'echo', 'printf', 'pwd', 'which', 'where', 'type',
        'whoami', 'id', 'date', 'uname',
        'env', 'printenv', 'locale',
        'test', '[',
        'true', 'false',
        'jq',
    ];

    /**
     * Check if a command is read-only (no side effects).
     * Used by permission system to auto-allow safe commands.
     */
    public function isCommandReadOnly(string $command): bool
    {
        $trimmed = trim($command);

        // Strip leading env var assignments
        $stripped = preg_replace('/^(\w+=\S+\s+)+/', '', $trimmed);

        foreach (self::READ_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($stripped, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Context passed to each validator.
 */
class ValidationContext
{
    public function __construct(
        public readonly string $originalCommand,
        public readonly string $baseCommand,
        /** Content with single-quote contents exposed, double-quote contents exposed */
        public readonly string $unquotedContent,
        /** Content with all quotes removed */
        public readonly string $fullyUnquotedContent,
        /** Content with quote characters preserved but not acting as delimiters */
        public readonly string $unquotedKeepQuoteChars,
    ) {}
}

/**
 * Result of a security check.
 */
class SecurityCheckResult
{
    private function __construct(
        /** 'allow', 'deny', or 'passthrough' */
        public readonly string $decision,
        /** Numeric check ID (matches CC check IDs) */
        public readonly ?int $checkId = null,
        /** Human-readable reason for deny */
        public readonly ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(decision: 'allow');
    }

    public static function deny(int $checkId, string $reason): self
    {
        return new self(decision: 'deny', checkId: $checkId, reason: $reason);
    }

    public static function passthrough(): self
    {
        return new self(decision: 'passthrough');
    }

    public function isDenied(): bool
    {
        return $this->decision === 'deny';
    }

    public function isAllowed(): bool
    {
        return $this->decision === 'allow';
    }

    public function isPassthrough(): bool
    {
        return $this->decision === 'passthrough';
    }
}
