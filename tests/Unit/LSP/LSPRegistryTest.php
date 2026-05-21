<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\LSP;

use PHPUnit\Framework\TestCase;
use SuperAgent\LSP\LanguageExtensions;
use SuperAgent\LSP\ServerInfo;
use SuperAgent\LSP\ServerRegistry;

class LSPRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ServerRegistry::reset();
    }

    public function test_defaults_include_common_servers(): void
    {
        $all = ServerRegistry::all();
        $this->assertArrayHasKey('gopls', $all);
        $this->assertArrayHasKey('rust-analyzer', $all);
        $this->assertArrayHasKey('typescript-language-server', $all);
        $this->assertArrayHasKey('pyright', $all);
        $this->assertArrayHasKey('phpactor', $all);
        $this->assertArrayHasKey('intelephense', $all);
    }

    public function test_for_extension_php(): void
    {
        $servers = ServerRegistry::forExtension('.php');
        $ids = array_map(static fn (ServerInfo $s) => $s->id, $servers);
        $this->assertContains('phpactor', $ids);
        $this->assertContains('intelephense', $ids);
    }

    public function test_for_extension_typescript_handles_jsx(): void
    {
        $ts = ServerRegistry::forExtension('.ts');
        $tsx = ServerRegistry::forExtension('.tsx');
        $this->assertNotEmpty($ts);
        $this->assertNotEmpty($tsx);
    }

    public function test_for_extension_unknown_returns_empty(): void
    {
        $this->assertSame([], ServerRegistry::forExtension('.xyz123'));
    }

    public function test_register_and_unregister_custom_server(): void
    {
        $custom = new ServerInfo(
            id: 'fake-ls',
            extensions: ['.fake'],
            rootFinder: fn (string $f, string $w) => $w,
            spawn: fn (string $r) => ['fake-ls', '--stdio'],
        );
        ServerRegistry::register($custom);
        $this->assertSame($custom, ServerRegistry::get('fake-ls'));
        $this->assertNotEmpty(ServerRegistry::forExtension('.fake'));

        ServerRegistry::unregister('fake-ls');
        $this->assertNull(ServerRegistry::get('fake-ls'));
    }

    public function test_language_extensions_map(): void
    {
        $this->assertSame('php', LanguageExtensions::forPath('/a/b/c.php'));
        $this->assertSame('typescript', LanguageExtensions::forPath('foo.TS'));
        $this->assertSame('go', LanguageExtensions::forPath('main.go'));
        $this->assertSame('rust', LanguageExtensions::forPath('lib.rs'));
        $this->assertNull(LanguageExtensions::forPath('no-extension'));
        $this->assertNull(LanguageExtensions::forPath('img.xyz'));
    }

    public function test_spawn_returns_false_when_binary_missing(): void
    {
        // Force PATH to be a directory that surely contains none of these binaries.
        $oldPath = getenv('PATH');
        $tmp = sys_get_temp_dir() . '/lsp-empty-path-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        putenv("PATH={$tmp}");
        try {
            $gopls = ServerRegistry::all()['gopls'];
            $this->assertFalse(($gopls->spawn)('/tmp'));
        } finally {
            putenv("PATH={$oldPath}");
            rmdir($tmp);
        }
    }
}
