<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\YamlSquadLoader;

/**
 * Validation rules pinned here: missing `name` / `steps` blocks load,
 * unknown depends_on targets blocks load, duplicate step names block
 * load, malformed loops blocks load. The point of these checks is to
 * catch typos at config load time, not at run time after a paid
 * model call has fired.
 */
final class YamlSquadLoaderTest extends TestCase
{
    public function test_minimal_valid_yaml_loads(): void
    {
        $plan = (new YamlSquadLoader())->loadString(<<<YAML
        name: hello-team
        steps:
          - name: solo
            prompt: hello
        YAML);

        $this->assertSame('hello-team', $plan->name);
        $this->assertCount(1, $plan->subTasks);
        $this->assertSame('solo', $plan->subTasks[0]->name);
        $this->assertSame(DifficultyClass::MODERATE, $plan->subTasks[0]->difficulty);
    }

    public function test_pause_after_maps_to_requires_review(): void
    {
        $plan = (new YamlSquadLoader())->loadString(<<<YAML
        name: gated-team
        steps:
          - name: write
            prompt: do it
          - name: gate
            prompt: approve?
            depends_on: [write]
            pause_after: true
        YAML);

        $this->assertFalse($plan->subTasks[0]->requiresReview);
        $this->assertTrue($plan->subTasks[1]->requiresReview);
    }

    public function test_loops_block_is_parsed(): void
    {
        $plan = (new YamlSquadLoader())->loadString(<<<YAML
        name: loop-team
        steps:
          - name: write
            prompt: do it
          - name: review
            prompt: check it
            depends_on: [write]
        loops:
          - writer: write
            reviewer: review
            feedback_key: review.feedback
            max_retries: 5
        YAML);
        $this->assertCount(1, $plan->loops);
        $this->assertSame('write', $plan->loops[0]->writer);
        $this->assertSame('review', $plan->loops[0]->reviewer);
        $this->assertSame(5, $plan->loops[0]->maxRetries);
    }

    public function test_missing_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new YamlSquadLoader())->loadString("steps:\n  - name: x\n    prompt: y\n");
    }

    public function test_missing_steps_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new YamlSquadLoader())->loadString("name: empty-team\n");
    }

    public function test_dangling_depends_on_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new YamlSquadLoader())->loadString(<<<YAML
        name: bad-team
        steps:
          - name: write
            prompt: x
            depends_on: [nonexistent]
        YAML);
    }

    public function test_duplicate_step_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new YamlSquadLoader())->loadString(<<<YAML
        name: dup-team
        steps:
          - name: a
            prompt: x
          - name: a
            prompt: y
        YAML);
    }

    public function test_loop_reference_to_missing_step_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new YamlSquadLoader())->loadString(<<<YAML
        name: bad-loop
        steps:
          - name: write
            prompt: x
        loops:
          - writer: write
            reviewer: nonexistent
        YAML);
    }

    public function test_tier_map_overrides_are_captured(): void
    {
        $plan = (new YamlSquadLoader())->loadString(<<<YAML
        name: tier-team
        tier_map:
          hard:
            provider: cli:claude_cli
            model: claude-opus-4-7
        steps:
          - name: solo
            prompt: x
        YAML);
        $this->assertArrayHasKey('hard', $plan->tierMap);
        $this->assertSame('cli:claude_cli', $plan->tierMap['hard']['provider']);
    }
}
