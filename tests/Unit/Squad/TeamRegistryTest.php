<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\SquadPlan;
use SuperAgent\Squad\SubTask;
use SuperAgent\Squad\TeamRegistry;

/**
 * The team registry is the single source of truth: bundled YAMLs
 * shipped with the SDK, host-registered overlay directories, and
 * programmatic runtime registrations. Tests pin the precedence
 * (runtime > directories > bundled), lazy parsing semantics
 * (broken YAML in an unrequested team doesn't break the registry),
 * and the lookup / listing contract.
 */
final class TeamRegistryTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/squad-teams-test-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($this->tmpDir);
        }
    }

    public function test_bundled_dir_is_discovered_automatically(): void
    {
        $r = new TeamRegistry();
        $names = $r->list();
        // SDK ships many production teams + a couple of examples;
        // exact count varies but the bundle is never empty.
        $this->assertGreaterThan(2, count($names));
        $this->assertContains('example-writer-reviewer-loop', $names);
    }

    public function test_load_returns_squad_plan(): void
    {
        $r = new TeamRegistry();
        $plan = $r->load('example-writer-reviewer-loop');
        $this->assertNotNull($plan);
        $this->assertSame('example-writer-reviewer-loop', $plan->name);
        $this->assertNotEmpty($plan->subTasks);
    }

    public function test_unknown_name_returns_null_and_require_throws(): void
    {
        $r = new TeamRegistry();
        $this->assertNull($r->load('definitely-does-not-exist'));
        $this->expectException(\InvalidArgumentException::class);
        $r->require('definitely-does-not-exist');
    }

    public function test_runtime_registration_wins_over_bundled(): void
    {
        $r = new TeamRegistry();
        $overridePlan = new SquadPlan(
            name: 'example-writer-reviewer-loop',
            description: 'host override',
            subTasks: [new SubTask(
                name:       'only-step',
                role:       'writer',
                prompt:     '{{task}}',
                difficulty: DifficultyClass::MODERATE,
            )],
        );
        $r->register('example-writer-reviewer-loop', $overridePlan);

        $loaded = $r->require('example-writer-reviewer-loop');
        $this->assertSame('host override', $loaded->description);
        $this->assertCount(1, $loaded->subTasks);

        $origin = $r->origin('example-writer-reviewer-loop');
        $this->assertNotNull($origin);
        $this->assertSame('runtime', $origin['tier']);
    }

    public function test_overlay_directory_wins_over_bundled(): void
    {
        // Drop an overlay file with the same name as a bundled team.
        file_put_contents(
            $this->tmpDir . '/example-writer-reviewer-loop.yaml',
            "name: example-writer-reviewer-loop\n"
            . "description: overlay description\n"
            . "steps:\n"
            . "  - name: solo\n"
            . "    prompt: hello\n"
            . "    difficulty: easy\n",
        );

        $r = new TeamRegistry();
        $r->addDirectory($this->tmpDir);
        $plan = $r->require('example-writer-reviewer-loop');
        $this->assertSame('overlay description', $plan->description);
        $origin = $r->origin('example-writer-reviewer-loop');
        $this->assertSame('directory', $origin['tier']);
    }

    public function test_broken_yaml_in_overlay_only_throws_when_loaded(): void
    {
        // Broken file: missing required 'name'.
        file_put_contents(
            $this->tmpDir . '/broken-team.yaml',
            "description: oops\nsteps:\n  - name: x\n    prompt: y\n",
        );
        // Good file alongside the broken one.
        file_put_contents(
            $this->tmpDir . '/good-team.yaml',
            "name: good-team\nsteps:\n  - name: solo\n    prompt: hello\n",
        );

        $r = new TeamRegistry();
        $r->addDirectory($this->tmpDir);

        // Listing both works — parsing is lazy.
        $names = $r->list();
        $this->assertContains('broken-team', $names);
        $this->assertContains('good-team', $names);

        // Loading the good one succeeds.
        $this->assertNotNull($r->load('good-team'));

        // Loading the broken one throws.
        $this->expectException(\InvalidArgumentException::class);
        $r->load('broken-team');
    }

    public function test_add_directory_is_idempotent(): void
    {
        $r = new TeamRegistry();
        $r->addDirectory($this->tmpDir);
        $r->addDirectory($this->tmpDir);
        // No exception; second call is a no-op.
        $this->assertIsArray($r->list());
    }

    public function test_unregister_removes_runtime_entry(): void
    {
        $r = new TeamRegistry();
        $plan = new SquadPlan(
            name: 'tmp-team',
            description: null,
            subTasks: [new SubTask('s', 'r', 'p', DifficultyClass::EASY)],
        );
        $r->register('tmp-team', $plan);
        $this->assertNotNull($r->load('tmp-team'));
        $r->unregister('tmp-team');
        $this->assertNull($r->load('tmp-team'));
    }
}
