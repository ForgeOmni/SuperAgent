<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent\AgentManager;

/**
 * Locks down the user/project agents-dir auto-load path — the same
 * convention SkillManager / MCPManager use. Without this, YAML or
 * Markdown agents dropped into `~/.superagent/agents/` would be
 * silently ignored and the bundled `resources/agents/README.md`
 * instruction ("drop here to auto-load") would be false.
 */
class UserAgentsDirLoadTest extends TestCase
{
    private string $tmpHome;
    private ?string $origPhpunitEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpHome = sys_get_temp_dir() . '/superagent-agent-dir-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome . '/.superagent/agents', 0755, true);
        putenv('HOME=' . $this->tmpHome);
        // The constructor skips disk auto-load under PHPUNIT_RUNNING, so
        // clear that env var for this test — otherwise our user-dir
        // agent never gets registered.
        $this->origPhpunitEnv = getenv('PHPUNIT_RUNNING') ?: null;
        putenv('PHPUNIT_RUNNING');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpHome);
        putenv('HOME');
        if ($this->origPhpunitEnv !== null) {
            putenv('PHPUNIT_RUNNING=' . $this->origPhpunitEnv);
        }
        parent::tearDown();
    }

    public function test_yaml_agent_in_user_dir_is_auto_loaded(): void
    {
        file_put_contents(
            $this->tmpHome . '/.superagent/agents/my-custom.yaml',
            "name: my-custom\ndescription: Custom user agent\nsystem_prompt: Hello from user dir.\n",
        );

        $mgr = new AgentManager();
        $agent = $mgr->get('my-custom');

        $this->assertNotNull($agent, 'YAML agent in ~/.superagent/agents/ must auto-load');
        $this->assertSame('Hello from user dir.', $agent->systemPrompt());
    }

    public function test_markdown_agent_in_user_dir_is_auto_loaded(): void
    {
        file_put_contents(
            $this->tmpHome . '/.superagent/agents/md-custom.md',
            "---\nname: md-custom\ndescription: Markdown user agent\n---\nBody from user dir.\n",
        );

        $mgr = new AgentManager();
        $agent = $mgr->get('md-custom');

        $this->assertNotNull($agent);
        $this->assertSame('Body from user dir.', $agent->systemPrompt());
    }

    public function test_auto_load_disabled_when_constructor_flag_false(): void
    {
        file_put_contents(
            $this->tmpHome . '/.superagent/agents/skip-me.yaml',
            "name: skip-me\nsystem_prompt: Should not load.\n",
        );

        $mgr = new AgentManager(autoLoadDisk: false);
        $this->assertNull($mgr->get('skip-me'));
    }

    public function test_absent_user_dir_is_silent_no_op(): void
    {
        // Recreate tmpHome without the agents dir — the constructor
        // must not blow up on a fresh install where the dir was
        // never created.
        $this->rrmdir($this->tmpHome . '/.superagent/agents');

        $mgr = new AgentManager();  // must not throw
        $this->assertInstanceOf(AgentManager::class, $mgr);
    }

    public function test_user_agents_dir_path_uses_home(): void
    {
        $this->assertSame(
            $this->tmpHome . '/.superagent/agents',
            AgentManager::userAgentsDir(),
        );
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
