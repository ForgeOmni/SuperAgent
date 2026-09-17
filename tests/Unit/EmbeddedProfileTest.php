<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;
use SuperAgent\Config\Profile;
use SuperAgent\Exceptions\ToolPolicyException;
use SuperAgent\Providers\FakeProvider;
use SuperAgent\QueryEngine;
use SuperAgent\Tests\Helpers\FakePolicyTool;
use SuperAgent\Tools\Builtin\BashTool;
use SuperAgent\Tools\ToolPolicy;

/**
 * The `embedded` profile (1.2.0) and its two enforcement points.
 *
 * The question these tests answer is not "can a host configure this safely"
 * — it always could — but "does the safe posture survive a host that forgets
 * an option, an SDK upgrade that adds a builtin, or a tool that arrives after
 * the agent was built".
 */
class EmbeddedProfileTest extends TestCase
{
    private function agent(array $config = []): Agent
    {
        return new Agent(['provider' => new FakeProvider()] + $config);
    }

    public function test_workstation_is_the_default_and_still_loads_the_default_tools(): void
    {
        $agent = $this->agent();

        $this->assertSame(Profile::WORKSTATION, $agent->getProfile());
        $this->assertNull($agent->getToolPolicy());
        $this->assertNotEmpty($agent->getTools(), 'the historical default must not change');
    }

    public function test_embedded_loads_nothing_the_host_did_not_hand_over(): void
    {
        $agent = Agent::embedded(['provider' => new FakeProvider()]);

        $this->assertSame(Profile::EMBEDDED, $agent->getProfile());
        $this->assertSame([], $agent->getTools());
        $this->assertNotNull($agent->getToolPolicy());
    }

    public function test_embedded_keeps_the_tools_the_host_does_hand_over(): void
    {
        $agent = Agent::embedded([
            'provider' => new FakeProvider(),
            'tools' => [new FakePolicyTool('get_order', 'general', true)],
        ]);

        $this->assertCount(1, $agent->getTools());
        $this->assertSame('get_order', $agent->getTools()[0]->name());
    }

    public function test_embedded_refuses_a_host_reaching_tool_named_by_the_caller(): void
    {
        $this->expectException(ToolPolicyException::class);
        $this->expectExceptionMessageMatches("/category 'execution'/");

        Agent::embedded([
            'provider' => new FakeProvider(),
            'tools' => [new BashTool()],
        ]);
    }

    public function test_embedded_refuses_a_host_reaching_tool_added_later(): void
    {
        $agent = Agent::embedded(['provider' => new FakeProvider()]);

        $this->expectException(ToolPolicyException::class);

        $agent->addTool(new BashTool());
    }

    public function test_a_denied_tool_that_reaches_the_engine_anyway_is_refused_at_call_time(): void
    {
        // The engine is handed the tool directly — the shape of a tool that
        // arrived after the agent was assembled (a plugin, an MCP catalog, a
        // builtin introduced by an upgrade). Assembly-time filtering never
        // saw it; the call-time check is what has to refuse it.
        $engine = new QueryEngine(
            provider: new FakeProvider(),
            tools: [new FakePolicyTool('shell', 'execution')],
            toolPolicy: ToolPolicy::hostSafe(),
        );

        $reason = (new \ReflectionMethod($engine, 'toolRefusalReason'))->invoke($engine, 'shell');

        $this->assertNotNull($reason);
        $this->assertStringContainsString("category 'execution'", $reason);
    }

    public function test_call_time_check_lets_a_permitted_tool_through(): void
    {
        $engine = new QueryEngine(
            provider: new FakeProvider(),
            tools: [new FakePolicyTool('get_order', 'general')],
            toolPolicy: ToolPolicy::hostSafe(),
        );

        $this->assertNull(
            (new \ReflectionMethod($engine, 'toolRefusalReason'))->invoke($engine, 'get_order')
        );
    }

    public function test_the_host_can_opt_out_of_the_profile_default_policy(): void
    {
        $agent = Agent::embedded([
            'provider' => new FakeProvider(),
            'tool_policy' => false,
            'tools' => [new BashTool()],
        ]);

        $this->assertNull($agent->getToolPolicy());
        $this->assertCount(1, $agent->getTools());
    }

    public function test_tightening_the_policy_keeps_the_profile_floor_underneath(): void
    {
        // The caller adds a rule; it does not silently drop the ones the
        // profile put there. Opting out of the floor is `tool_policy => false`,
        // which is explicit — see the test above.
        $agent = Agent::embedded([
            'provider' => new FakeProvider(),
            'tool_policy' => ['read_only_only' => true],
            'tools' => [new FakePolicyTool('get_order', 'general', true)],
        ]);

        $spec = $agent->getToolPolicy()->toArray();

        $this->assertTrue($spec['read_only_only'], "the caller's rule is applied");
        $this->assertSame(ToolPolicy::HOST_CATEGORIES, $spec['deny_categories'], 'and the profile floor remains');

        $this->expectException(ToolPolicyException::class);
        $agent->addTool(new FakePolicyTool('cancel_order', 'general', false));
    }

    public function test_a_workstation_agent_can_be_given_a_policy_too(): void
    {
        $agent = $this->agent([
            'tool_policy' => ['deny_categories' => ['execution']],
            'load_tools' => 'none',
        ]);

        $this->assertSame(Profile::WORKSTATION, $agent->getProfile());
        $this->assertNotNull($agent->getToolPolicy());

        $this->expectException(ToolPolicyException::class);
        $agent->addTool(new BashTool());
    }

    public function test_loader_produced_tools_are_filtered_rather_than_fatal(): void
    {
        // Nobody named these tools, the loader produced them — a policy that
        // refuses half of the default set is a configuration, not a mistake,
        // so the refused ones are dropped instead of aborting construction.
        $agent = $this->agent([
            'load_tools' => true,
            'tool_policy' => ['deny_categories' => ToolPolicy::HOST_CATEGORIES],
        ]);

        foreach ($agent->getTools() as $tool) {
            $this->assertNotContains(
                strtolower($tool->category()),
                ToolPolicy::HOST_CATEGORIES,
                "{$tool->name()} should have been filtered out"
            );
        }
    }

    public function test_profile_defaults_do_not_override_what_the_caller_set(): void
    {
        $config = Profile::apply([
            'profile' => Profile::EMBEDDED,
            'tool_loader' => ['auto_load' => true, 'lazy_load' => false],
        ]);

        $this->assertTrue($config['tool_loader']['auto_load'], "the caller's value must win");
        $this->assertFalse($config['tool_loader']['lazy_load']);
        $this->assertSame(ToolPolicy::HOST_CATEGORIES, $config['tool_policy']['deny_categories']);
    }
}
