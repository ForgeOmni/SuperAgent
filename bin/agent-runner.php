#!/usr/bin/env php
<?php

/**
 * SuperAgent Process Runner
 * 
 * This script is used by the ProcessBackend to run agents in separate processes.
 * It receives configuration via environment variables and executes the agent.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Agent\Agent;
use SuperAgent\Context\Context;
use Psr\Log\NullLogger;

// Get configuration from environment
$agentId = $_ENV['SUPERAGENT_AGENT_ID'] ?? 'unknown';
$agentName = $_ENV['SUPERAGENT_AGENT_NAME'] ?? 'agent';
$teamName = $_ENV['SUPERAGENT_TEAM_NAME'] ?? null;
$prompt = $_ENV['SUPERAGENT_PROMPT'] ?? '';
$model = $_ENV['SUPERAGENT_MODEL'] ?? null;
$systemPrompt = $_ENV['SUPERAGENT_SYSTEM_PROMPT'] ?? null;
$permissionMode = $_ENV['SUPERAGENT_PERMISSION_MODE'] ?? null;
$allowedTools = $_ENV['SUPERAGENT_ALLOWED_TOOLS'] ?? null;
$planMode = $_ENV['SUPERAGENT_PLAN_MODE'] ?? '0';

// Parse command line arguments
$options = getopt('', ['agent-id:', 'task-id:']);
$agentId = $options['agent-id'] ?? $agentId;
$taskId = $options['task-id'] ?? uniqid('task_');

// Log startup
fwrite(STDERR, "[Agent Runner] Starting agent $agentId ($agentName)\n");

try {
    // Create context
    $context = new Context();
    
    // Set metadata
    $context->setMetadata('agent_id', $agentId);
    $context->setMetadata('agent_name', $agentName);
    $context->setMetadata('task_id', $taskId);
    
    if ($teamName) {
        $context->setMetadata('team_name', $teamName);
    }
    
    if ($permissionMode) {
        $context->setMetadata('permission_mode', $permissionMode);
    }
    
    // Create agent
    $agent = new Agent(
        context: $context,
        logger: new NullLogger(),
    );
    
    // Configure agent
    if ($model) {
        $agent->setModel($model);
    }
    
    if ($systemPrompt) {
        $agent->setSystemPrompt($systemPrompt);
    }
    
    if ($allowedTools) {
        $tools = explode(',', $allowedTools);
        $agent->setAllowedTools($tools);
    }
    
    // Set up message handling
    $stdinOpen = true;
    stream_set_blocking(STDIN, false);
    
    // Main loop
    while ($stdinOpen) {
        // Check for messages from parent
        $input = fgets(STDIN);
        if ($input !== false) {
            $message = json_decode(trim($input), true);
            if ($message) {
                fwrite(STDERR, "[Agent Runner] Received message: " . json_encode($message) . "\n");
                
                // Handle shutdown request
                if (isset($message['content'])) {
                    $content = json_decode($message['content'], true);
                    if (isset($content['type']) && $content['type'] === 'shutdown_request') {
                        fwrite(STDERR, "[Agent Runner] Shutdown requested\n");
                        break;
                    }
                }
                
                // Process message with agent
                if (isset($message['content']) && is_string($message['content'])) {
                    $response = $agent->run($message['content']);
                    fwrite(STDOUT, json_encode([
                        'agent_id' => $agentId,
                        'response' => $response->content,
                        'timestamp' => date('c'),
                    ]) . "\n");
                }
            }
        }
        
        // Check if stdin is closed
        if (feof(STDIN)) {
            $stdinOpen = false;
        }
        
        // Small sleep to avoid busy waiting
        usleep(100000); // 100ms
    }
    
    // Execute initial prompt if no interactive mode
    if ($prompt && !$stdinOpen) {
        fwrite(STDERR, "[Agent Runner] Executing prompt: $prompt\n");
        $response = $agent->run($prompt);
        fwrite(STDOUT, json_encode([
            'agent_id' => $agentId,
            'response' => $response->content,
            'timestamp' => date('c'),
        ]) . "\n");
    }
    
    fwrite(STDERR, "[Agent Runner] Agent $agentId completed\n");
    exit(0);
    
} catch (\Exception $e) {
    fwrite(STDERR, "[Agent Runner] Error: " . $e->getMessage() . "\n");
    exit(1);
}