<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Permissions;

use PHPUnit\Framework\TestCase;
use SuperAgent\Permissions\BashSecurityValidator;
use SuperAgent\Permissions\SecurityCheckResult;

class BashSecurityValidatorTest extends TestCase
{
    private BashSecurityValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BashSecurityValidator();
    }

    // ----------------------------------------------------------------
    // Passthrough (benign commands)
    // ----------------------------------------------------------------

    public function testPlainCommandPassesThrough(): void
    {
        $result = $this->validator->validate('ls -la');
        $this->assertTrue($result->isPassthrough());
    }

    public function testCommandWithFlagsPassesThrough(): void
    {
        $result = $this->validator->validate('grep -r "foo" src/');
        $this->assertTrue($result->isPassthrough());
    }

    public function testSafeRedirectToDevNullPassesThrough(): void
    {
        $result = $this->validator->validate('some_command 2>&1');
        $this->assertTrue($result->isPassthrough());

        $result = $this->validator->validate('cmd >/dev/null');
        $this->assertTrue($result->isPassthrough());

        $result = $this->validator->validate('cmd </dev/null');
        $this->assertTrue($result->isPassthrough());
    }

    public function testFindWithPatternAllowsMetacharacters(): void
    {
        $result = $this->validator->validate('find . -name "*.php"');
        $this->assertTrue($result->isPassthrough());
    }

    // ----------------------------------------------------------------
    // Allow (explicitly safe)
    // ----------------------------------------------------------------

    public function testEmptyCommandAllows(): void
    {
        $result = $this->validator->validate('');
        $this->assertTrue($result->isAllowed());

        $result = $this->validator->validate('   ');
        $this->assertTrue($result->isAllowed());
    }

    public function testSafeHeredocSubstitutionAllows(): void
    {
        $result = $this->validator->validate("git commit -m \"\$(cat <<'EOF'\nmessage\nEOF\n)\"");
        $this->assertTrue($result->isAllowed());
    }

    // ----------------------------------------------------------------
    // Control characters (check 17)
    // ----------------------------------------------------------------

    public function testControlCharacterDenies(): void
    {
        $result = $this->validator->validate("ls\x00file");
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_CONTROL_CHARACTERS, $result->checkId);
    }

    public function testBellCharacterDenies(): void
    {
        $result = $this->validator->validate("ls\x07");
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_CONTROL_CHARACTERS, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Incomplete commands (check 1)
    // ----------------------------------------------------------------

    public function testCommandStartingWithTabDenies(): void
    {
        $result = $this->validator->validate("\tls");
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_INCOMPLETE_COMMANDS, $result->checkId);
    }

    public function testCommandStartingWithFlagDenies(): void
    {
        $result = $this->validator->validate('-rf /');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_INCOMPLETE_COMMANDS, $result->checkId);
    }

    public function testCommandStartingWithOperatorDenies(): void
    {
        foreach (['&& ls', '|| ls', '; ls', '>> out', '< input'] as $cmd) {
            $result = $this->validator->validate($cmd);
            $this->assertTrue($result->isDenied(), "Should deny: $cmd");
            $this->assertSame(BashSecurityValidator::CHECK_INCOMPLETE_COMMANDS, $result->checkId);
        }
    }

    // ----------------------------------------------------------------
    // jq injection (checks 2, 3)
    // ----------------------------------------------------------------

    public function testJqSystemFunctionDenies(): void
    {
        $result = $this->validator->validate('jq \'system("rm -rf /")\'');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_JQ_SYSTEM_FUNCTION, $result->checkId);
    }

    public function testJqRawfileArgumentDenies(): void
    {
        $result = $this->validator->validate('jq --rawfile x /etc/passwd .');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_JQ_FILE_ARGUMENTS, $result->checkId);
    }

    public function testJqFromFileArgumentDenies(): void
    {
        $result = $this->validator->validate('jq --from-file script.jq');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_JQ_FILE_ARGUMENTS, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Obfuscated flags (check 4)
    // ----------------------------------------------------------------

    public function testAnsiCQuotingDenies(): void
    {
        $result = $this->validator->validate("rm \$'\\x2drf' /");
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_OBFUSCATED_FLAGS, $result->checkId);
    }

    public function testLocaleQuotingDenies(): void
    {
        $result = $this->validator->validate('echo $"text"');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_OBFUSCATED_FLAGS, $result->checkId);
    }

    public function testEmptyQuotesFollowedByFlagDenies(): void
    {
        $result = $this->validator->validate('rm ""-rf /');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_OBFUSCATED_FLAGS, $result->checkId);
    }

    public function testExcessiveConsecutiveQuotesDenies(): void
    {
        $result = $this->validator->validate('echo """hello');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_OBFUSCATED_FLAGS, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Shell metacharacters (check 5)
    // ----------------------------------------------------------------

    public function testUnquotedSemicolonDenies(): void
    {
        $result = $this->validator->validate('echo hi; rm -rf /');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_SHELL_METACHARACTERS, $result->checkId);
    }

    public function testUnquotedPipeDenies(): void
    {
        $result = $this->validator->validate('echo hi | grep x');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_SHELL_METACHARACTERS, $result->checkId);
    }

    public function testUnquotedAmpersandDenies(): void
    {
        $result = $this->validator->validate('cmd & background');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_SHELL_METACHARACTERS, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Dangerous variables (check 6)
    // ----------------------------------------------------------------

    public function testVariableInPipeDenies(): void
    {
        $result = $this->validator->validate('cat | $VAR');
        $this->assertTrue($result->isDenied());
        // Could be metacharacters first (|) or dangerous variables — validate correct deny family
        $this->assertContains(
            $result->checkId,
            [
                BashSecurityValidator::CHECK_DANGEROUS_VARIABLES,
                BashSecurityValidator::CHECK_SHELL_METACHARACTERS,
            ],
        );
    }

    // ----------------------------------------------------------------
    // Newlines (check 7)
    // ----------------------------------------------------------------

    public function testNewlineSeparatingCommandsDenies(): void
    {
        $result = $this->validator->validate("ls\nrm -rf /");
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_NEWLINES, $result->checkId);
    }

    public function testCarriageReturnDenies(): void
    {
        $result = $this->validator->validate("ls\rrm");
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_NEWLINES, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Command substitution (check 8)
    // ----------------------------------------------------------------

    public function testBacktickSubstitutionDenies(): void
    {
        $result = $this->validator->validate('echo `whoami`');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_COMMAND_SUBSTITUTION, $result->checkId);
    }

    public function testDollarParenSubstitutionDenies(): void
    {
        $result = $this->validator->validate('echo $(whoami)');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_COMMAND_SUBSTITUTION, $result->checkId);
    }

    public function testProcessSubstitutionDenies(): void
    {
        $result = $this->validator->validate('diff <(ls) <(ls /tmp)');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_COMMAND_SUBSTITUTION, $result->checkId);
    }

    public function testParameterExpansionDenies(): void
    {
        $result = $this->validator->validate('echo ${HOME}');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_COMMAND_SUBSTITUTION, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Redirections (checks 9, 10)
    // ----------------------------------------------------------------

    public function testInputRedirectionDenies(): void
    {
        $result = $this->validator->validate('cat < /etc/passwd');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_INPUT_REDIRECTION, $result->checkId);
    }

    public function testOutputRedirectionDenies(): void
    {
        $result = $this->validator->validate('echo hi > /tmp/out');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_OUTPUT_REDIRECTION, $result->checkId);
    }

    // ----------------------------------------------------------------
    // IFS injection (check 11)
    // ----------------------------------------------------------------

    public function testIFSInjectionDenies(): void
    {
        $result = $this->validator->validate('cmd$IFS-rf');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_IFS_INJECTION, $result->checkId);
    }

    public function testIFSInBracesDenies(): void
    {
        $result = $this->validator->validate('cmd${IFS}arg');
        $this->assertTrue($result->isDenied());
        $this->assertContains(
            $result->checkId,
            [
                BashSecurityValidator::CHECK_IFS_INJECTION,
                BashSecurityValidator::CHECK_COMMAND_SUBSTITUTION,
            ],
        );
    }

    // ----------------------------------------------------------------
    // Git commit substitution (check 12)
    // ----------------------------------------------------------------

    public function testGitCommitWithCommandSubstitutionDenies(): void
    {
        $result = $this->validator->validate('git commit -m "msg $(whoami)"');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_GIT_COMMIT_SUBSTITUTION, $result->checkId);
    }

    public function testGitCommitWithBacktickDenies(): void
    {
        $result = $this->validator->validate('git commit -m "msg `whoami`"');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_GIT_COMMIT_SUBSTITUTION, $result->checkId);
    }

    // ----------------------------------------------------------------
    // /proc/*/environ access (check 13)
    // ----------------------------------------------------------------

    public function testProcEnvironDenies(): void
    {
        $result = $this->validator->validate('cat /proc/1/environ');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_PROC_ENVIRON_ACCESS, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Backslash-escaped whitespace / operators (checks 15, 21)
    // ----------------------------------------------------------------

    public function testBackslashEscapedSpaceDenies(): void
    {
        $result = $this->validator->validate('ls /tmp\\ /other');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_BACKSLASH_ESCAPED_WHITESPACE, $result->checkId);
    }

    public function testBackslashEscapedOperatorDenies(): void
    {
        $result = $this->validator->validate('ls \\; rm');
        $this->assertTrue($result->isDenied());
        // Metacharacter check may fire first because the unquoted-content view
        // strips the escaping backslash and exposes the ; to the earlier scan.
        $this->assertContains(
            $result->checkId,
            [
                BashSecurityValidator::CHECK_BACKSLASH_ESCAPED_OPERATORS,
                BashSecurityValidator::CHECK_SHELL_METACHARACTERS,
            ],
        );
    }

    // ----------------------------------------------------------------
    // Brace expansion (check 16)
    // ----------------------------------------------------------------

    public function testCommaBraceExpansionDenies(): void
    {
        $result = $this->validator->validate('echo {a,b,c}');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_BRACE_EXPANSION, $result->checkId);
    }

    public function testSequenceBraceExpansionDenies(): void
    {
        $result = $this->validator->validate('echo {1..5}');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_BRACE_EXPANSION, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Unicode whitespace (check 18)
    // ----------------------------------------------------------------

    public function testUnicodeNonBreakingSpaceDenies(): void
    {
        $result = $this->validator->validate("ls\u{00A0}/tmp");
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_UNICODE_WHITESPACE, $result->checkId);
    }

    public function testUnicodeZeroWidthDenies(): void
    {
        $result = $this->validator->validate("ls\u{FEFF}arg");
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_UNICODE_WHITESPACE, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Mid-word hash (check 19)
    // ----------------------------------------------------------------

    public function testMidWordHashDenies(): void
    {
        $result = $this->validator->validate('echo foo#bar');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_MID_WORD_HASH, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Zsh dangerous commands (check 20)
    // ----------------------------------------------------------------

    public function testZmodloadDenies(): void
    {
        $result = $this->validator->validate('zmodload zsh/system');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_ZSH_DANGEROUS_COMMANDS, $result->checkId);
    }

    public function testCommandPrefixStrippedForZshCheck(): void
    {
        $result = $this->validator->validate('command zmodload x');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_ZSH_DANGEROUS_COMMANDS, $result->checkId);
    }

    public function testFcEditorExecutionDenies(): void
    {
        $result = $this->validator->validate('fc -e vi');
        $this->assertTrue($result->isDenied());
        $this->assertSame(BashSecurityValidator::CHECK_ZSH_DANGEROUS_COMMANDS, $result->checkId);
    }

    // ----------------------------------------------------------------
    // Malformed token injection (check 14)
    // ----------------------------------------------------------------

    public function testUnbalancedQuotesWithSeparatorDenies(): void
    {
        $result = $this->validator->validate("echo 'unterminated; rm");
        $this->assertTrue($result->isDenied());
        // Could be caught by metacharacters first
        $this->assertContains(
            $result->checkId,
            [
                BashSecurityValidator::CHECK_MALFORMED_TOKEN_INJECTION,
                BashSecurityValidator::CHECK_SHELL_METACHARACTERS,
            ],
        );
    }

    // ----------------------------------------------------------------
    // Comment / quote desync (check 22) and quoted newline (check 23)
    // ----------------------------------------------------------------

    public function testCommentWithQuoteDesyncDenies(): void
    {
        $result = $this->validator->validate("echo 'safe'\n# with 'quote'\necho more");
        $this->assertTrue($result->isDenied());
    }

    public function testQuotedNewlineWithHashDenies(): void
    {
        $result = $this->validator->validate("echo \"text\n# comment\"");
        $this->assertTrue($result->isDenied());
    }

    // ----------------------------------------------------------------
    // Read-only classifier
    // ----------------------------------------------------------------

    public function testReadOnlyGitStatus(): void
    {
        $this->assertTrue($this->validator->isCommandReadOnly('git status'));
        $this->assertTrue($this->validator->isCommandReadOnly('git diff HEAD'));
        $this->assertTrue($this->validator->isCommandReadOnly('git log --oneline'));
    }

    public function testReadOnlyNotGitCommit(): void
    {
        $this->assertFalse($this->validator->isCommandReadOnly('git commit -m msg'));
        $this->assertFalse($this->validator->isCommandReadOnly('git push'));
    }

    public function testReadOnlyFilesystemCommands(): void
    {
        foreach (['ls -la', 'cat file', 'head -n 5 a', 'wc -l', 'pwd', 'whoami'] as $cmd) {
            $this->assertTrue(
                $this->validator->isCommandReadOnly($cmd),
                "Should be read-only: $cmd",
            );
        }
    }

    public function testReadOnlyNotRm(): void
    {
        $this->assertFalse($this->validator->isCommandReadOnly('rm -rf /tmp/x'));
        $this->assertFalse($this->validator->isCommandReadOnly('mv a b'));
    }

    public function testReadOnlyStripsEnvVarPrefix(): void
    {
        $this->assertTrue($this->validator->isCommandReadOnly('FOO=bar ls'));
        $this->assertTrue($this->validator->isCommandReadOnly('A=1 B=2 git status'));
    }

    public function testReadOnlyNotMatchedWhenEmbedded(): void
    {
        $this->assertFalse($this->validator->isCommandReadOnly('./ls'));
        $this->assertFalse($this->validator->isCommandReadOnly('myls'));
    }

    // ----------------------------------------------------------------
    // SecurityCheckResult API
    // ----------------------------------------------------------------

    public function testSecurityCheckResultAllowState(): void
    {
        $r = SecurityCheckResult::allow();
        $this->assertTrue($r->isAllowed());
        $this->assertFalse($r->isDenied());
        $this->assertFalse($r->isPassthrough());
        $this->assertNull($r->checkId);
        $this->assertNull($r->reason);
        $this->assertSame('allow', $r->decision);
    }

    public function testSecurityCheckResultDenyState(): void
    {
        $r = SecurityCheckResult::deny(7, 'because');
        $this->assertTrue($r->isDenied());
        $this->assertFalse($r->isAllowed());
        $this->assertFalse($r->isPassthrough());
        $this->assertSame(7, $r->checkId);
        $this->assertSame('because', $r->reason);
        $this->assertSame('deny', $r->decision);
    }

    public function testSecurityCheckResultPassthroughState(): void
    {
        $r = SecurityCheckResult::passthrough();
        $this->assertTrue($r->isPassthrough());
        $this->assertFalse($r->isAllowed());
        $this->assertFalse($r->isDenied());
        $this->assertSame('passthrough', $r->decision);
    }
}
