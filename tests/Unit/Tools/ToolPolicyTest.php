<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Tests\Helpers\FakePolicyTool;
use SuperAgent\Tools\ToolPolicy;
use SuperAgent\Tools\ToolResult;

/**
 * ToolPolicy (1.2.0): what an agent may hold, judged by what a tool is.
 */
class ToolPolicyTest extends TestCase
{
    public function test_no_restrictions_is_recognised_as_an_empty_spec(): void
    {
        $this->assertTrue(ToolPolicy::isEmptySpec([]));
        $this->assertTrue(ToolPolicy::isEmptySpec([
            'allow_list' => null,
            'deny_list' => [],
            'deny_categories' => [],
            'read_only_only' => false,
        ]));
        $this->assertFalse(ToolPolicy::isEmptySpec(['deny_categories' => ['file']]));
    }

    public function test_deny_categories_refuses_that_category_only(): void
    {
        $policy = ToolPolicy::fromArray(['deny_categories' => ['execution']]);

        $this->assertFalse($policy->permits(new FakePolicyTool('shell', 'execution')));
        $this->assertTrue($policy->permits(new FakePolicyTool('get_order', 'general')));
    }

    public function test_category_matching_ignores_case_and_padding(): void
    {
        $policy = ToolPolicy::fromArray(['deny_categories' => ['  ExEcUtIoN ']]);

        $this->assertFalse($policy->permits(new FakePolicyTool('shell', 'Execution')));
    }

    public function test_host_safe_refuses_every_host_reaching_category(): void
    {
        $policy = ToolPolicy::hostSafe();

        foreach (ToolPolicy::HOST_CATEGORIES as $category) {
            $this->assertFalse(
                $policy->permits(new FakePolicyTool('t_' . $category, $category)),
                "category {$category} should be refused by the host-safe policy"
            );
        }

        $this->assertTrue($policy->permits(new FakePolicyTool('get_order', 'general')));
    }

    public function test_allow_list_refuses_everything_else(): void
    {
        $policy = ToolPolicy::fromArray(['allow_list' => ['get_order']]);

        $this->assertTrue($policy->permits(new FakePolicyTool('get_order', 'general')));
        $this->assertFalse($policy->permits(new FakePolicyTool('cancel_order', 'general')));
    }

    public function test_deny_list_wins_over_allow_list(): void
    {
        $policy = ToolPolicy::fromArray([
            'allow_list' => ['get_order'],
            'deny_list' => ['get_order'],
        ]);

        $this->assertFalse($policy->permits(new FakePolicyTool('get_order', 'general')));
    }

    public function test_read_only_only_refuses_a_writing_tool(): void
    {
        $policy = ToolPolicy::fromArray(['read_only_only' => true]);

        $this->assertTrue($policy->permits(new FakePolicyTool('get_order', 'general', true)));
        $this->assertFalse($policy->permits(new FakePolicyTool('cancel_order', 'general', false)));
    }

    public function test_a_tool_that_declares_no_category_counts_as_general(): void
    {
        // category() lives on the Tool base, not on ToolInterface, so a host
        // may hand in a tool that has none. It is read as 'general', which no
        // deny list names — the category gate is a second line of defence,
        // not the only one, which is why the allow list and read_only_only
        // still apply to it.
        $bare = new class implements ToolInterface {
            public function name(): string
            {
                return 'bare';
            }

            public function description(): string
            {
                return 'a tool that implements only the interface';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success('ok');
            }

            public function isReadOnly(): bool
            {
                return false;
            }
        };

        $this->assertTrue(ToolPolicy::hostSafe()->permits($bare));
        $this->assertFalse(ToolPolicy::fromArray(['read_only_only' => true])->permits($bare));
        $this->assertFalse(ToolPolicy::fromArray(['allow_list' => ['something_else']])->permits($bare));
    }

    public function test_refusal_reason_names_the_rule_that_refused(): void
    {
        $policy = ToolPolicy::fromArray(['deny_categories' => ['execution']]);

        $this->assertStringContainsString(
            "category 'execution'",
            (string) $policy->refusalReason(new FakePolicyTool('shell', 'execution'))
        );
        $this->assertNull($policy->refusalReason(new FakePolicyTool('get_order', 'general')));
    }

    public function test_filter_keeps_only_permitted_tools(): void
    {
        $policy = ToolPolicy::hostSafe();

        $kept = $policy->filter([
            new FakePolicyTool('shell', 'execution'),
            new FakePolicyTool('get_order', 'general'),
            new FakePolicyTool('read_file', 'file'),
        ]);

        $this->assertCount(1, $kept);
        $this->assertSame('get_order', $kept[0]->name());
    }

    public function test_to_array_round_trips(): void
    {
        $spec = [
            'allow_list' => ['a'],
            'deny_list' => ['b'],
            'deny_categories' => ['file'],
            'read_only_only' => true,
        ];

        $this->assertSame($spec, ToolPolicy::fromArray($spec)->toArray());
    }
}
