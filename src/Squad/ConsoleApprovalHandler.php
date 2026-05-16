<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\Steps\ApprovalStep;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Real Human-in-the-Loop gate: shows the approval message and prompts
 * the operator via the Symfony Console question helper.
 *
 * Wired into `PeerOrchestrator` only when the host has an InputInterface
 * (i.e. a CLI run). Tests and library callers stick with the default
 * auto-approve handler.
 */
final class ConsoleApprovalHandler
{
    public function __construct(
        private readonly InputInterface $input,
        private readonly OutputInterface $output,
        private readonly QuestionHelper $helper = new QuestionHelper(),
        private readonly bool $defaultAnswer = false,
    ) {}

    /**
     * `PipelineEngine` calls this with the step and the live context.
     * Returning `true` lets the pipeline continue past the gate.
     */
    public function __invoke(ApprovalStep $step, PipelineContext $context): bool
    {
        $message = $context->resolveTemplate($step->getMessage());

        $this->output->writeln('');
        $this->output->writeln('<fg=magenta>══ Human review required ══</>');
        $this->output->writeln($message);
        $this->output->writeln('');

        $question = new ConfirmationQuestion(
            sprintf('Approve step "%s"? [%s] ', $step->getName(), $this->defaultAnswer ? 'Y/n' : 'y/N'),
            $this->defaultAnswer,
        );

        return (bool) $this->helper->ask($this->input, $this->output, $question);
    }
}
