<?php

declare(strict_types=1);

namespace Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\CLI\SuperAgentApplication;

/**
 * Tests the argv parser + sub-command routing logic in
 * SuperAgentApplication without actually executing any command.
 */
class SuperAgentApplicationParseTest extends TestCase
{
    public function test_plain_prompt_becomes_chat(): void
    {
        $opts = $this->parse(['fix', 'the', 'login', 'bug']);
        $this->assertNull($opts['command']);
        $this->assertSame('fix the login bug', $opts['prompt']);
    }

    public function test_init_subcommand_detected(): void
    {
        $opts = $this->parse(['init']);
        $this->assertSame('init', $opts['command']);
        $this->assertNull($opts['prompt']);
    }

    public function test_auth_subcommand_captures_args(): void
    {
        $opts = $this->parse(['auth', 'login', 'claude-code']);
        $this->assertSame('auth', $opts['command']);
        $this->assertSame(['login', 'claude-code'], $opts['auth_args']);
        $this->assertNull($opts['prompt']);
    }

    public function test_auth_status_captures_args(): void
    {
        $opts = $this->parse(['auth', 'status']);
        $this->assertSame('auth', $opts['command']);
        $this->assertSame(['status'], $opts['auth_args']);
    }

    public function test_bare_login_is_rewritten_to_auth_login(): void
    {
        $opts = $this->parse(['login', 'codex']);
        $this->assertSame('login', $opts['command']);
        $this->assertSame(['login', 'codex'], $opts['auth_args']);
    }

    public function test_model_flag_captured(): void
    {
        $opts = $this->parse(['-m', 'claude-opus-4-5', 'hello']);
        $this->assertSame('claude-opus-4-5', $opts['model']);
        $this->assertSame('hello', $opts['prompt']);
    }

    public function test_long_model_flag(): void
    {
        $opts = $this->parse(['--model', 'gpt-5', 'task']);
        $this->assertSame('gpt-5', $opts['model']);
    }

    public function test_provider_flag(): void
    {
        $opts = $this->parse(['-p', 'openai', '--max-turns', '30', 'task']);
        $this->assertSame('openai', $opts['provider']);
        $this->assertSame(30, $opts['max_turns']);
    }

    public function test_json_flag(): void
    {
        $opts = $this->parse(['--json', 'summarise']);
        $this->assertTrue($opts['json']);
    }

    public function test_system_prompt_flag(): void
    {
        $opts = $this->parse(['-s', 'Be terse.', 'explain']);
        $this->assertSame('Be terse.', $opts['system_prompt']);
        $this->assertSame('explain', $opts['prompt']);
    }

    public function test_rich_rendering_toggles(): void
    {
        $default = $this->parse([]);
        $this->assertTrue($default['rich']);
        $this->assertSame('normal', $default['thinking']);

        $disabled = $this->parse(['--no-rich']);
        $this->assertFalse($disabled['rich']);

        $legacy = $this->parse(['--legacy-renderer']);
        $this->assertFalse($legacy['rich']);

        $verbose = $this->parse(['--verbose-thinking']);
        $this->assertSame('verbose', $verbose['thinking']);

        $hidden = $this->parse(['--no-thinking']);
        $this->assertSame('hidden', $hidden['thinking']);

        $plain = $this->parse(['--plain']);
        $this->assertTrue($plain['plain']);
    }

    public function test_unknown_flag_ignored(): void
    {
        $opts = $this->parse(['--nonexistent', 'prompt']);
        // Unknown flags are currently silently ignored; positional 'prompt' still captured.
        $this->assertSame('prompt', $opts['prompt']);
    }

    public function test_empty_argv_starts_chat_with_no_prompt(): void
    {
        $opts = $this->parse([]);
        $this->assertNull($opts['command']);
        $this->assertNull($opts['prompt']);
    }

    public function test_chat_subcommand_explicit(): void
    {
        $opts = $this->parse(['chat', 'hello']);
        $this->assertSame('chat', $opts['command']);
        $this->assertSame('hello', $opts['prompt']);
    }

    // Reach into the private parser via reflection since SuperAgentApplication::run()
    // wants to actually execute a subcommand.
    private function parse(array $args): array
    {
        $app = new SuperAgentApplication();
        $r = new \ReflectionMethod($app, 'parseOptions');
        $r->setAccessible(true);
        return $r->invoke($app, $args);
    }
}
