<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Context\ContextInterface;
use SuperAgent\Context\Context;
use SuperAgent\LLM\Response;

/**
 * Basic Agent implementation for swarm functionality.
 */
class Agent
{
    private ContextInterface $context;
    private LoggerInterface $logger;
    private ?string $model = null;
    private ?string $systemPrompt = null;
    private ?array $allowedTools = null;
    
    public function __construct(
        ?ContextInterface $context = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->context = $context ?? new Context();
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function getContext(): ContextInterface
    {
        return $this->context;
    }
    
    public function setModel(string $model): void
    {
        $this->model = $model;
    }
    
    public function getModel(): ?string
    {
        return $this->model;
    }
    
    public function setSystemPrompt(string $prompt): void
    {
        $this->systemPrompt = $prompt;
    }
    
    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }
    
    public function setAllowedTools(array $tools): void
    {
        $this->allowedTools = $tools;
    }
    
    public function getAllowedTools(): ?array
    {
        return $this->allowedTools;
    }
    
    /**
     * Run the agent with a given prompt.
     */
    public function run(string $prompt): Response
    {
        $this->logger->info("Agent running", [
            'prompt' => $prompt,
            'model' => $this->model,
            'tools' => $this->allowedTools,
        ]);
        
        // Check if cancelled
        if ($this->context->getMetadata('cancelled')) {
            return new Response(
                content: "Agent execution cancelled",
                usage: [],
            );
        }

        // Simulated response for testing
        // In a real implementation, this would call the LLM provider
        return new Response(
            content: "Agent processed: " . $prompt,
            usage: [],
        );
    }
}