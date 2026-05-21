<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Format;

use PHPUnit\Framework\TestCase;
use SuperAgent\Format\FormatterContext;
use SuperAgent\Format\FormatterInfo;
use SuperAgent\Format\Formatters;

class FormattersTest extends TestCase
{
    protected function tearDown(): void
    {
        Formatters::reset();
    }

    public function test_defaults_loaded_on_demand(): void
    {
        $all = Formatters::all();
        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('gofmt', $all);
        $this->assertArrayHasKey('prettier', $all);
        $this->assertArrayHasKey('pint', $all);
        $this->assertArrayHasKey('rustfmt', $all);
    }

    public function test_for_extension_returns_matching_formatters(): void
    {
        $php = Formatters::forExtension('.php');
        $names = array_map(static fn (FormatterInfo $i) => $i->name, $php);
        $this->assertContains('pint', $names);

        $go = Formatters::forExtension('.go');
        $this->assertSame(['gofmt'], array_map(static fn (FormatterInfo $i) => $i->name, $go));

        $ts = Formatters::forExtension('.ts');
        $tsNames = array_map(static fn (FormatterInfo $i) => $i->name, $ts);
        $this->assertContains('prettier', $tsNames);
        $this->assertContains('biome', $tsNames);
    }

    public function test_for_extension_case_insensitive(): void
    {
        $upper = Formatters::forExtension('.GO');
        $lower = Formatters::forExtension('.go');
        $this->assertCount(count($lower), $upper);
    }

    public function test_register_and_unregister(): void
    {
        Formatters::all(); // load defaults
        $custom = new FormatterInfo(
            'fake-fmt',
            ['.fake'],
            static fn (FormatterContext $c) => ['fake-fmt', '$FILE'],
        );
        Formatters::register($custom);
        $this->assertArrayHasKey('fake-fmt', Formatters::all());

        $matched = Formatters::forExtension('.fake');
        $this->assertCount(1, $matched);
        $this->assertSame('fake-fmt', $matched[0]->name);

        Formatters::unregister('fake-fmt');
        $this->assertArrayNotHasKey('fake-fmt', Formatters::all());
    }

    public function test_pint_probe_returns_false_without_composer_dep(): void
    {
        $tmp = sys_get_temp_dir() . '/sa-pint-test-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        file_put_contents("$tmp/composer.json", json_encode(['require' => []]));

        $all = Formatters::all();
        $pint = $all['pint'];
        $ctx = new FormatterContext($tmp, $tmp);
        $this->assertFalse(($pint->probe)($ctx));

        unlink("$tmp/composer.json");
        rmdir($tmp);
    }

    public function test_prettier_probe_returns_false_without_package_dep(): void
    {
        $tmp = sys_get_temp_dir() . '/sa-prettier-test-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        file_put_contents("$tmp/package.json", json_encode(['name' => 'x']));

        $all = Formatters::all();
        $prettier = $all['prettier'];
        $ctx = new FormatterContext($tmp, $tmp);
        $this->assertFalse(($prettier->probe)($ctx));

        unlink("$tmp/package.json");
        rmdir($tmp);
    }
}
