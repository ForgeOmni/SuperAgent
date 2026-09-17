<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Optimization\ModelRouter;
use SuperAgent\Optimization\PromptCachePinning;
use SuperAgent\Optimization\ResponsePrefill;
use SuperAgent\Optimization\ToolResultCompactor;
use SuperAgent\Optimization\ToolSchemaFilter;
use SuperAgent\Performance\AdaptiveMaxTokens;
use SuperAgent\Performance\ConnectionPool;
use SuperAgent\Performance\LocalToolZeroCopy;
use SuperAgent\Performance\ParallelToolExecutor;
use SuperAgent\Performance\SpeculativePrefetch;
use SuperAgent\Performance\StreamingBashExecutor;
use SuperAgent\Performance\StreamingToolDispatch;

/**
 * Building an agent outside a booted application is quiet.
 *
 * Twelve `fromConfig()` factories each caught their own config failure and
 * wrote `[SuperAgent] Config unavailable for …` — once per object, per turn.
 * Running without a framework is this SDK's documented standalone mode, not an
 * error, and in a host's test suite that output is enough to mark tests risky
 * under `beStrictAboutOutputDuringTests`. They read through Support\Config now,
 * which answers with the default and says nothing.
 */
class QuietConfigFallbackTest extends TestCase
{
    public function test_no_factory_announces_that_config_is_absent(): void
    {
        // expectOutputString() rather than ob_start(): one of these factories
        // leaves an output buffer of its own open, and nesting ours inside it
        // makes the test risky for a reason that has nothing to do with what
        // it is checking.
        $this->expectOutputString('');

        // A couple of these factories take an argument; the point is the
        // construction, not the signature.
        $this->assertInstanceOf(ModelRouter::class, ModelRouter::fromConfig('claude-sonnet-5'));

        foreach ([
            PromptCachePinning::class,
            ResponsePrefill::class,
            ToolResultCompactor::class,
            ToolSchemaFilter::class,
            AdaptiveMaxTokens::class,
            ConnectionPool::class,
            LocalToolZeroCopy::class,
            ParallelToolExecutor::class,
            SpeculativePrefetch::class,
            StreamingBashExecutor::class,
            StreamingToolDispatch::class,
        ] as $class) {
            $this->assertInstanceOf($class, $class::fromConfig());
        }

    }

    public function test_the_defaults_still_arrive(): void
    {
        // Quiet is only right if the fallback still produces a usable object.
        $this->assertIsBool(ToolResultCompactor::fromConfig()->isEnabled());
        $this->assertIsBool(ParallelToolExecutor::fromConfig()->isEnabled());
    }
}
