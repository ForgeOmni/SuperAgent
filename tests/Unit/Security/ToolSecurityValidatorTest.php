<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SuperAgent\Security\CostLimiter;
use SuperAgent\Security\NetworkPolicy;
use SuperAgent\Security\ToolSecurityValidator;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class ToolSecurityValidatorTest extends TestCase
{
    private string $ledger;

    protected function setUp(): void
    {
        $this->ledger = sys_get_temp_dir() . '/superagent_tsv_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        NetworkPolicy::forceOffline(null);
        putenv('SUPERAGENT_OFFLINE');
        if (is_file($this->ledger)) @unlink($this->ledger);
    }

    public function test_bash_tool_delegated_and_allowed(): void
    {
        NetworkPolicy::forceOffline(true);  // even offline, bash must still pass here
        $v = ToolSecurityValidator::default();
        $result = $v->validate(new FakeBashTool());
        $this->assertTrue($result->isAllow());
        $this->assertStringContainsString('BashSecurityValidator', $result->reason);
    }

    public function test_tool_without_attributes_is_allowed(): void
    {
        $v = ToolSecurityValidator::default();
        $this->assertTrue($v->validate(new NoAttrTool())->isAllow());
    }

    public function test_network_tool_blocked_when_offline(): void
    {
        NetworkPolicy::forceOffline(true);
        $v = ToolSecurityValidator::default();
        $d = $v->validate(new FakeProviderTool(['network']));
        $this->assertTrue($d->isDeny());
        $this->assertStringContainsString('network', $d->reason);
    }

    public function test_cost_tool_blocked_by_daily_cap(): void
    {
        NetworkPolicy::forceOffline(false);
        $cost = new CostLimiter([
            'ledger_path' => $this->ledger,
            'global_daily_usd' => 1.00,
        ]);
        $cost->record('fake_cost_tool', 0.80);

        $v = new ToolSecurityValidator([], null, $cost);
        $d = $v->validate(new FakeProviderTool(['network', 'cost'], 'fake_cost_tool'), [], 0.50);
        $this->assertTrue($d->isDeny());
        $this->assertStringContainsString('global daily', $d->reason);
    }

    public function test_sensitive_default_is_ask(): void
    {
        NetworkPolicy::forceOffline(false);
        $v = new ToolSecurityValidator(['cost' => ['ledger_path' => $this->ledger]]);
        $d = $v->validate(new FakeProviderTool(['sensitive']));
        $this->assertTrue($d->isAsk());
        $this->assertStringContainsString('approve', $d->reason);
    }

    public function test_sensitive_can_be_auto_allowed_by_policy(): void
    {
        NetworkPolicy::forceOffline(false);
        $v = new ToolSecurityValidator([
            'sensitive_default' => 'allow',
            'cost' => ['ledger_path' => $this->ledger],
        ]);
        $this->assertTrue($v->validate(new FakeProviderTool(['sensitive']))->isAllow());
    }

    public function test_sensitive_can_be_denied_by_policy(): void
    {
        NetworkPolicy::forceOffline(false);
        $v = new ToolSecurityValidator([
            'sensitive_default' => 'deny',
            'cost' => ['ledger_path' => $this->ledger],
        ]);
        $this->assertTrue($v->validate(new FakeProviderTool(['sensitive']))->isDeny());
    }

    public function test_network_wins_over_cost_when_offline(): void
    {
        NetworkPolicy::forceOffline(true);
        $v = new ToolSecurityValidator([
            'cost' => ['ledger_path' => $this->ledger, 'per_call_usd' => 100.00],
        ]);
        $d = $v->validate(new FakeProviderTool(['network', 'cost']), [], 0.01);
        $this->assertTrue($d->isDeny());
        $this->assertSame('network', $d->context['attribute']);
    }

    public function test_record_cost_updates_ledger(): void
    {
        $cost = new CostLimiter(['ledger_path' => $this->ledger]);
        $v = new ToolSecurityValidator([], null, $cost);
        $v->recordCost('my_tool', 0.42);
        $this->assertSame(0.42, $cost->snapshot()['spend']['my_tool']);
    }
}

// ── fakes ────────────────────────────────────────────────────────

class FakeBashTool extends Tool
{
    public function name(): string { return 'bash'; }
    public function description(): string { return 'run bash'; }
    public function inputSchema(): array { return ['type' => 'object']; }
    public function execute(array $input): ToolResult { return ToolResult::success(''); }
    public function isReadOnly(): bool { return false; }
}

class NoAttrTool extends Tool
{
    public function name(): string { return 'noattr'; }
    public function description(): string { return 'x'; }
    public function inputSchema(): array { return ['type' => 'object']; }
    public function execute(array $input): ToolResult { return ToolResult::success(''); }
    public function isReadOnly(): bool { return true; }
}

/**
 * Stand-in for a ProviderToolBase descendant that advertises a custom
 * attribute set — lets us drive the validator without building a real
 * provider-backed Guzzle client for every test case.
 */
class FakeProviderTool extends Tool
{
    public function __construct(
        private readonly array $attrs,
        private readonly string $name = 'fake_provider_tool',
    ) {}

    public function name(): string { return $this->name; }
    public function description(): string { return 'fake'; }
    public function inputSchema(): array { return ['type' => 'object']; }
    public function execute(array $input): ToolResult { return ToolResult::success(''); }
    public function isReadOnly(): bool { return true; }

    /** Matches the duck-typed `method_exists($tool, 'attributes')` path. */
    public function attributes(): array { return $this->attrs; }
}
