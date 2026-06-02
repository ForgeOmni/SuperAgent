<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAgent\SmartFlow\FlowEngine;
use SuperAgent\SmartFlow\FlowOptions;
use SuperAgent\SmartFlow\FlowRegistry;

/**
 * Every shipped static flow must rehearse end-to-end under the fake provider at
 * zero token cost — the "11 套静态 flow + 零成本演练" guarantee.
 */
class FlowsRehearsalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/smartflow-rehearse-' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** A superset of args covering every template variable used by the flows. */
    private function args(): array
    {
        return [
            'goal' => 'a tiny todo CLI',
            'idea' => 'a habit tracker',
            'question' => 'does X cause Y?',
            'diff' => "--- a\n+++ b\n+ echo 1;",
            'subject' => 'the widget API',
            'audience' => 'backend developers',
            'text' => 'Hello world.',
            'target_language' => 'French',
            'locale' => 'fr-FR',
            'topic' => 'why the sky is blue',
            'language' => 'English',
            'platform' => 'Reels',
            'seconds' => 30,
            'ticker' => 'EXMPL',
            'context' => 'recent earnings beat',
            'month' => 'May 2026',
            'universe' => 'US large cap',
            'notes' => 'tech led, energy lagged',
            'style' => 'wry and concise',
        ];
    }

    public function test_all_shipped_flows_are_discoverable(): void
    {
        $registry = new FlowRegistry();
        $flows = $registry->list();
        $this->assertGreaterThanOrEqual(11, count($flows), 'expected at least 11 built-in flows');
        foreach (['dev-from-scratch', 'product-trio', 'research-trio', 'code-review-council',
                  'video-creator', 'mp-article', 'stock-trio', 'stock-monthly-style',
                  'stock-veggie', 'doc-writer', 'translate-localize'] as $name) {
            $this->assertArrayHasKey($name, $flows, "missing flow: {$name}");
        }
    }

    public function test_every_flow_rehearses_green_at_zero_cost(): void
    {
        $registry = new FlowRegistry();
        $engine = new FlowEngine();

        foreach ($registry->list() as $name => $meta) {
            $def = $registry->get($name);
            $this->assertNotNull($def, "could not load {$name}");

            $result = $engine->run($def, $this->args(), new FlowOptions(
                rehearse: true,
                ledgerDir: $this->dir,
            ));

            $this->assertTrue(
                $result->isSuccessful(),
                "flow '{$name}' failed during rehearsal: " . ($result->error ?? 'unknown')
            );
            $this->assertSame(0.0, $result->costUsd(), "flow '{$name}' was not zero-cost");
            $this->assertGreaterThan(0, $result->ledger['calls'], "flow '{$name}' made no agent calls");
        }
    }
}
