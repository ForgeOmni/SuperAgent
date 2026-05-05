<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Conversation;

use PHPUnit\Framework\TestCase;
use SuperAgent\Conversation\Fork;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\UserMessage;

class ForkTest extends TestCase
{
    public function test_from_takes_immutable_snapshot_of_parent(): void
    {
        $parent = [new UserMessage('p1')];
        $fork = Fork::from($parent);
        $this->assertSame($parent, $fork->parentSnapshot);
        $this->assertSame([], $fork->sideMessages());
    }

    public function test_extend_appends_to_side_only(): void
    {
        $parent = [new UserMessage('p1')];
        $fork = Fork::from($parent);
        $fork->extend(new UserMessage('s1'), new UserMessage('s2'));
        $this->assertCount(2, $fork->sideMessages());
        // Parent must be untouched.
        $this->assertCount(1, $fork->parentSnapshot);
    }

    public function test_assembled_returns_parent_plus_side(): void
    {
        $fork = Fork::from([new UserMessage('p1'), new UserMessage('p2')]);
        $fork->extend(new UserMessage('s1'));
        $this->assertCount(3, $fork->assembled());
    }

    public function test_discard_returns_parent_unchanged(): void
    {
        $parent = [new UserMessage('p1')];
        $fork = Fork::from($parent);
        $fork->extend(new UserMessage('s1'));
        // Discarding the side: parent is what we get back, no side
        // turns leak into it.
        $this->assertSame($parent, $fork->discard());
    }

    public function test_promote_brings_specific_side_messages_back(): void
    {
        $a = function (string $text): AssistantMessage {
            $m = new AssistantMessage();
            $m->content = [ContentBlock::text($text)];
            return $m;
        };
        $parent = [new UserMessage('parent question')];
        $fork = Fork::from($parent);
        $fork->extend(
            $a('side reasoning step 1'),
            $a('side reasoning step 2'),
            $a('FINAL ANSWER: 42'),
        );
        // Promote only the final answer — drop the side reasoning.
        $merged = $fork->promote(2);
        $this->assertCount(2, $merged);
        $this->assertSame('FINAL ANSWER: 42', $merged[1]->text());
    }

    public function test_promote_skips_out_of_range_indexes(): void
    {
        $fork = Fork::from([new UserMessage('p')]);
        $fork->extend(new UserMessage('s'));
        // Index 5 doesn't exist — must not crash.
        $merged = $fork->promote(5);
        $this->assertCount(1, $merged);
    }

    public function test_promote_all_returns_full_concat(): void
    {
        $fork = Fork::from([new UserMessage('p')]);
        $fork->extend(new UserMessage('s1'), new UserMessage('s2'));
        $this->assertCount(3, $fork->promoteAll());
    }
}
