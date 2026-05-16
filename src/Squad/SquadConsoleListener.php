<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use SuperAgent\Pipeline\PipelineEngine;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Streams squad progress to an `OutputInterface` by subscribing to
 * the existing `PipelineEngine` event bus.
 *
 * Decoupled from the orchestrator: callers either wire it up
 * explicitly or `PeerOrchestrator` does it automatically when an
 * `OutputInterface` is supplied. Keeps verbose-mode noise out of
 * test runs.
 */
final class SquadConsoleListener
{
    public function __construct(private readonly OutputInterface $output) {}

    public function attach(PipelineEngine $engine): void
    {
        $engine->on('pipeline.start', function (array $event): void {
            $this->output->writeln(sprintf(
                '<info>[squad]</info> starting <comment>%s</comment> (%d steps)',
                $event['pipeline'] ?? '?',
                $event['steps'] ?? 0,
            ));
        });

        $engine->on('step.start', function (array $event): void {
            if (!$this->output->isVerbose()) {
                return;
            }
            $this->output->writeln(sprintf(
                '  <fg=cyan>→</> <options=bold>%s</> — %s',
                $event['step'] ?? '?',
                $event['description'] ?? '',
            ));
        });

        $engine->on('step.end', function (array $event): void {
            $status = $event['status'] ?? '?';
            $colour = match ($status) {
                'completed'        => 'green',
                'failed'           => 'red',
                'skipped'          => 'yellow',
                'waiting_approval' => 'magenta',
                default            => 'white',
            };
            $this->output->writeln(sprintf(
                '  <fg=%s>✓</> <options=bold>%s</> [%s, %.0fms]',
                $colour,
                $event['step'] ?? '?',
                $status,
                $event['duration_ms'] ?? 0,
            ));
        });

        $engine->on('step.retry', function (array $event): void {
            $this->output->writeln(sprintf(
                '  <fg=yellow>↻</> retrying <options=bold>%s</> (attempt %d/%d)',
                $event['step'] ?? '?',
                $event['attempt'] ?? 1,
                $event['max_attempts'] ?? 1,
            ));
        });

        $engine->on('pipeline.end', function (array $event): void {
            $this->output->writeln(sprintf(
                '<info>[squad]</info> done — status=<comment>%s</comment> (%.0fms)',
                $event['status'] ?? '?',
                $event['duration_ms'] ?? 0,
            ));
        });
    }
}
