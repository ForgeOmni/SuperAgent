<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Capabilities;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\JobHandle;
use SuperAgent\Providers\JobStatus;

class JobHandleTest extends TestCase
{
    public function test_new_factory_sets_created_at_to_now(): void
    {
        $before = time();
        $h = JobHandle::new('kimi', 'job_abc', 'swarm', ['foo' => 'bar']);
        $after = time();

        $this->assertSame('kimi', $h->provider);
        $this->assertSame('job_abc', $h->jobId);
        $this->assertSame('swarm', $h->kind);
        $this->assertGreaterThanOrEqual($before, $h->createdAt);
        $this->assertLessThanOrEqual($after, $h->createdAt);
        $this->assertSame(['foo' => 'bar'], $h->meta);
    }

    public function test_round_trip_via_array(): void
    {
        $original = new JobHandle('minimax', 'vid_123', 'video', 1_700_000_000, ['group_id' => 'gp']);
        $restored = JobHandle::fromArray($original->toArray());

        $this->assertEquals($original, $restored);
        $this->assertSame($original->createdAt, $restored->createdAt);
        $this->assertSame($original->meta, $restored->meta);
    }

    public function test_from_array_defaults_missing_meta_and_timestamp(): void
    {
        $h = JobHandle::fromArray([
            'provider' => 'glm',
            'job_id' => 'img_1',
            'kind' => 'image',
        ]);
        $this->assertSame('glm', $h->provider);
        $this->assertSame([], $h->meta);
        $this->assertGreaterThan(0, $h->createdAt);
    }

    public function test_job_status_terminal_detection(): void
    {
        $this->assertFalse(JobStatus::Pending->isTerminal());
        $this->assertFalse(JobStatus::Running->isTerminal());
        $this->assertTrue(JobStatus::Done->isTerminal());
        $this->assertTrue(JobStatus::Failed->isTerminal());
        $this->assertTrue(JobStatus::Canceled->isTerminal());
    }

    public function test_job_status_string_values_are_stable(): void
    {
        // External persistence may rely on these raw values — lock them down.
        $this->assertSame('pending', JobStatus::Pending->value);
        $this->assertSame('running', JobStatus::Running->value);
        $this->assertSame('done', JobStatus::Done->value);
        $this->assertSame('failed', JobStatus::Failed->value);
        $this->assertSame('canceled', JobStatus::Canceled->value);
    }
}
