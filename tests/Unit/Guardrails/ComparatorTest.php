<?php

namespace SuperAgent\Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use SuperAgent\Guardrails\Conditions\Comparator;

class ComparatorTest extends TestCase
{
    public function test_gt(): void
    {
        $this->assertTrue(Comparator::compare(10, 'gt', 5));
        $this->assertFalse(Comparator::compare(5, 'gt', 5));
        $this->assertFalse(Comparator::compare(3, 'gt', 5));
    }

    public function test_gte(): void
    {
        $this->assertTrue(Comparator::compare(5, 'gte', 5));
        $this->assertTrue(Comparator::compare(10, 'gte', 5));
        $this->assertFalse(Comparator::compare(3, 'gte', 5));
    }

    public function test_lt(): void
    {
        $this->assertTrue(Comparator::compare(3, 'lt', 5));
        $this->assertFalse(Comparator::compare(5, 'lt', 5));
    }

    public function test_lte(): void
    {
        $this->assertTrue(Comparator::compare(5, 'lte', 5));
        $this->assertTrue(Comparator::compare(3, 'lte', 5));
        $this->assertFalse(Comparator::compare(10, 'lte', 5));
    }

    public function test_eq(): void
    {
        $this->assertTrue(Comparator::compare('hello', 'eq', 'hello'));
        $this->assertFalse(Comparator::compare('hello', 'eq', 'world'));
        $this->assertTrue(Comparator::compare(42, 'eq', 42));
    }

    public function test_contains(): void
    {
        $this->assertTrue(Comparator::compare('/home/.git/config', 'contains', '.git/'));
        $this->assertTrue(Comparator::compare('/path/.ENV', 'contains', '.env'));
        $this->assertFalse(Comparator::compare('/home/user', 'contains', '.git'));
    }

    public function test_starts_with(): void
    {
        $this->assertTrue(Comparator::compare('/home/user/project', 'starts_with', '/home/user'));
        $this->assertFalse(Comparator::compare('/tmp/file', 'starts_with', '/home'));
    }

    public function test_matches_glob(): void
    {
        $this->assertTrue(Comparator::compare('rm -rf /tmp', 'matches', 'rm -rf *'));
        $this->assertTrue(Comparator::compare('config.yaml', 'matches', '*.yaml'));
        $this->assertFalse(Comparator::compare('config.json', 'matches', '*.yaml'));
    }

    public function test_any_of(): void
    {
        $this->assertTrue(Comparator::compare('Bash', 'any_of', ['Bash', 'Read', 'Write']));
        $this->assertFalse(Comparator::compare('Delete', 'any_of', ['Bash', 'Read', 'Write']));
    }

    public function test_unknown_operator_returns_false(): void
    {
        $this->assertFalse(Comparator::compare('a', 'nope', 'b'));
    }

    public function test_non_numeric_gt_returns_false(): void
    {
        $this->assertFalse(Comparator::compare('abc', 'gt', 5));
    }

    public function test_non_string_contains_returns_false(): void
    {
        $this->assertFalse(Comparator::compare(123, 'contains', 'abc'));
    }
}
