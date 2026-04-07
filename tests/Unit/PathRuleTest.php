<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Permissions\CommandDenyPattern;
use SuperAgent\Permissions\PathRule;
use SuperAgent\Permissions\PathRuleEvaluator;
use SuperAgent\Permissions\PermissionBehavior;

class PathRuleTest extends TestCase
{
    // ─── PathRule::matches ───

    public function test_matches_exact_filename_glob(): void
    {
        $rule = new PathRule('*.env', true);
        $this->assertTrue($rule->matches('.env'));
        $this->assertTrue($rule->matches('production.env'));
        $this->assertTrue($rule->matches('/var/www/.env'));
    }

    public function test_matches_directory_glob_with_double_star(): void
    {
        $rule = new PathRule('/tmp/**', true);
        $this->assertTrue($rule->matches('/tmp/foo'));
        $this->assertTrue($rule->matches('/tmp/foo/bar'));
        $this->assertFalse($rule->matches('/var/tmp/foo'));
    }

    public function test_matches_relative_path_glob(): void
    {
        $rule = new PathRule('database/migrations/*', true);
        $this->assertTrue($rule->matches('database/migrations/001_create_users.php'));
        // Relative pattern with */* prefix matching deeper paths
        $this->assertTrue($rule->matches('project/database/migrations/001_create_users.php'));
    }

    public function test_matches_relative_path_with_deep_nesting(): void
    {
        $rule = new PathRule('database/migrations/**', true);
        $this->assertTrue($rule->matches('/app/database/migrations/001_create_users.php'));
        $this->assertTrue($rule->matches('database/migrations/sub/file.php'));
    }

    public function test_matches_basename_fallback(): void
    {
        $rule = new PathRule('*.log', true);
        $this->assertTrue($rule->matches('/var/log/app.log'));
        $this->assertTrue($rule->matches('error.log'));
    }

    public function test_does_not_match_unrelated_path(): void
    {
        $rule = new PathRule('*.env', true);
        $this->assertFalse($rule->matches('/var/www/index.php'));
        $this->assertFalse($rule->matches('README.md'));
    }

    public function test_normalizes_backslashes(): void
    {
        $rule = new PathRule('src\\*', true);
        $this->assertTrue($rule->matches('src/file.php'));
        $this->assertTrue($rule->matches('src\\file.php'));
    }

    public function test_root_pattern_only_matches_from_root(): void
    {
        $rule = new PathRule('/etc/passwd', true);
        $this->assertTrue($rule->matches('/etc/passwd'));
        $this->assertFalse($rule->matches('etc/passwd'));
    }

    // ─── PathRule factories ───

    public function test_allow_factory(): void
    {
        $rule = PathRule::allow('*.php');
        $this->assertSame('*.php', $rule->pattern);
        $this->assertTrue($rule->allow);
    }

    public function test_deny_factory(): void
    {
        $rule = PathRule::deny('*.env');
        $this->assertSame('*.env', $rule->pattern);
        $this->assertFalse($rule->allow);
    }

    // ─── PathRule serialization ───

    public function test_to_array(): void
    {
        $rule = PathRule::deny('*.env');
        $this->assertSame(['pattern' => '*.env', 'allow' => false], $rule->toArray());
    }

    public function test_from_array(): void
    {
        $rule = PathRule::fromArray(['pattern' => '/tmp/**', 'allow' => true]);
        $this->assertSame('/tmp/**', $rule->pattern);
        $this->assertTrue($rule->allow);
    }

    public function test_from_array_defaults_allow_to_false(): void
    {
        $rule = PathRule::fromArray(['pattern' => '*.secret']);
        $this->assertFalse($rule->allow);
    }

    // ─── CommandDenyPattern ───

    public function test_command_deny_pattern_matches_exact(): void
    {
        $pattern = new CommandDenyPattern('rm -rf /');
        $this->assertTrue($pattern->matches('rm -rf /'));
        $this->assertFalse($pattern->matches('rm -rf ./build'));
    }

    public function test_command_deny_pattern_matches_wildcard(): void
    {
        $pattern = new CommandDenyPattern('rm -rf *');
        $this->assertTrue($pattern->matches('rm -rf /'));
        $this->assertTrue($pattern->matches('rm -rf ./build'));
    }

    public function test_command_deny_pattern_matches_trimmed(): void
    {
        $pattern = new CommandDenyPattern('DROP TABLE*');
        $this->assertTrue($pattern->matches('  DROP TABLE users  '));
    }

    public function test_command_deny_pattern_from_array(): void
    {
        $patterns = CommandDenyPattern::fromArray(['rm -rf *', 'chmod 777*']);
        $this->assertCount(2, $patterns);
        $this->assertInstanceOf(CommandDenyPattern::class, $patterns[0]);
        $this->assertSame('rm -rf *', $patterns[0]->pattern);
        $this->assertSame('chmod 777*', $patterns[1]->pattern);
    }

    // ─── PathRuleEvaluator::evaluatePath ───

    public function test_evaluator_deny_takes_precedence_over_allow(): void
    {
        $evaluator = new PathRuleEvaluator([
            PathRule::allow('*.php'),
            PathRule::deny('config/*.php'),
        ]);

        $decision = $evaluator->evaluatePath('config/database.php');
        $this->assertNotNull($decision);
        $this->assertSame(PermissionBehavior::DENY, $decision->behavior);
        $this->assertStringContainsString('config/*.php', $decision->message);
    }

    public function test_evaluator_returns_null_when_no_match(): void
    {
        $evaluator = new PathRuleEvaluator([
            PathRule::deny('*.env'),
        ]);

        $this->assertNull($evaluator->evaluatePath('/app/index.php'));
    }

    public function test_evaluator_returns_null_for_allow_only_matches(): void
    {
        $evaluator = new PathRuleEvaluator([
            PathRule::allow('*.php'),
        ]);

        $this->assertNull($evaluator->evaluatePath('index.php'));
    }

    // ─── PathRuleEvaluator::evaluateCommand ───

    public function test_evaluator_command_matches_deny_pattern(): void
    {
        $evaluator = new PathRuleEvaluator([], [
            new CommandDenyPattern('rm -rf *'),
        ]);

        $decision = $evaluator->evaluateCommand('rm -rf /home');
        $this->assertNotNull($decision);
        $this->assertSame(PermissionBehavior::DENY, $decision->behavior);
    }

    public function test_evaluator_command_returns_null_when_no_match(): void
    {
        $evaluator = new PathRuleEvaluator([], [
            new CommandDenyPattern('rm -rf *'),
        ]);

        $this->assertNull($evaluator->evaluateCommand('ls -la'));
    }

    // ─── PathRuleEvaluator::fromConfig ───

    public function test_evaluator_from_config(): void
    {
        $evaluator = PathRuleEvaluator::fromConfig([
            'path_rules' => [
                ['pattern' => '*.env', 'allow' => false],
                ['pattern' => 'src/**', 'allow' => true],
            ],
            'denied_commands' => [
                'rm -rf *',
                'DROP TABLE*',
            ],
        ]);

        $this->assertCount(2, $evaluator->getRules());
        $this->assertCount(2, $evaluator->getCommandDenyPatterns());
    }

    public function test_evaluator_from_empty_config(): void
    {
        $evaluator = PathRuleEvaluator::fromConfig([]);
        $this->assertCount(0, $evaluator->getRules());
        $this->assertCount(0, $evaluator->getCommandDenyPatterns());
    }

    // ─── PathRuleEvaluator add methods ───

    public function test_evaluator_add_rule(): void
    {
        $evaluator = new PathRuleEvaluator();
        $this->assertCount(0, $evaluator->getRules());

        $evaluator->addRule(PathRule::deny('*.secret'));
        $this->assertCount(1, $evaluator->getRules());

        // Verify the added rule is effective
        $decision = $evaluator->evaluatePath('app.secret');
        $this->assertNotNull($decision);
        $this->assertSame(PermissionBehavior::DENY, $decision->behavior);
    }

    public function test_evaluator_add_command_deny_pattern(): void
    {
        $evaluator = new PathRuleEvaluator();
        $this->assertCount(0, $evaluator->getCommandDenyPatterns());

        $evaluator->addCommandDenyPattern(new CommandDenyPattern('sudo *'));
        $this->assertCount(1, $evaluator->getCommandDenyPatterns());

        $decision = $evaluator->evaluateCommand('sudo rm -rf /');
        $this->assertNotNull($decision);
        $this->assertSame(PermissionBehavior::DENY, $decision->behavior);
    }

    // ─── Edge cases ───

    public function test_multiple_deny_rules_first_wins(): void
    {
        $evaluator = new PathRuleEvaluator([
            PathRule::deny('*.env'),
            PathRule::deny('*.secret'),
        ]);

        $decision = $evaluator->evaluatePath('app.env');
        $this->assertNotNull($decision);
        $this->assertStringContainsString('*.env', $decision->message);
    }

    public function test_command_deny_pattern_chmod_777(): void
    {
        $pattern = new CommandDenyPattern('chmod 777*');
        $this->assertTrue($pattern->matches('chmod 777 /var/www'));
        $this->assertFalse($pattern->matches('chmod 755 /var/www'));
    }
}
