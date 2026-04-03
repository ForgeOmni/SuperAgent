<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Communication;

use SuperAgent\Swarm\AgentMessage;
use SuperAgent\Swarm\ParallelAgentCoordinator;

/**
 * Agent Communication Protocol for inter-agent messaging.
 * Supports direct messaging, broadcasts, and request-response patterns.
 */
class AgentCommunicationProtocol
{
    private ParallelAgentCoordinator $coordinator;
    private array $messageHandlers = [];
    private array $pendingRequests = [];
    private array $messageLog = [];
    
    public function __construct(?ParallelAgentCoordinator $coordinator = null)
    {
        $this->coordinator = $coordinator ?? ParallelAgentCoordinator::getInstance();
    }
    
    /**
     * Send a direct message from one agent to another.
     */
    public function sendMessage(
        string $fromAgentId,
        string $toAgentId,
        string $type,
        mixed $payload,
        ?string $correlationId = null
    ): string {
        $messageId = $this->generateMessageId();
        $correlationId = $correlationId ?? $messageId;
        
        $message = new AgentMessage(
            from: $fromAgentId,
            to: $toAgentId,
            content: json_encode([
                'id' => $messageId,
                'correlation_id' => $correlationId,
                'type' => $type,
                'payload' => $payload,
                'timestamp' => microtime(true),
            ]),
            summary: "Message: $type",
        );
        
        // Log the message
        $this->logMessage($message);
        
        // Queue message for recipient
        $this->coordinator->queueMessage($toAgentId, $message->toArray());
        
        // Track activity
        $fromTracker = $this->coordinator->getTracker($fromAgentId);
        if ($fromTracker) {
            $fromTracker->setCurrentActivity("Sending message to $toAgentId");
        }
        
        return $messageId;
    }
    
    /**
     * Broadcast a message to all agents in a team.
     */
    public function broadcast(
        string $fromAgentId,
        string $teamName,
        string $type,
        mixed $payload
    ): array {
        $team = $this->coordinator->getTeam($teamName);
        if (!$team) {
            throw new \RuntimeException("Team not found: $teamName");
        }
        
        $messageIds = [];
        foreach ($team->getMembers() as $member) {
            if ($member->agentId !== $fromAgentId) {
                $messageIds[] = $this->sendMessage(
                    $fromAgentId,
                    $member->agentId,
                    $type,
                    $payload
                );
            }
        }
        
        return $messageIds;
    }
    
    /**
     * Send a request and wait for response.
     */
    public function sendRequest(
        string $fromAgentId,
        string $toAgentId,
        string $type,
        mixed $payload,
        int $timeoutMs = 5000
    ): mixed {
        $requestId = $this->generateMessageId();
        
        // Create a promise for the response
        $this->pendingRequests[$requestId] = [
            'from' => $fromAgentId,
            'to' => $toAgentId,
            'type' => $type,
            'timestamp' => microtime(true),
            'timeout' => $timeoutMs,
            'response' => null,
        ];
        
        // Send the request
        $this->sendMessage(
            $fromAgentId,
            $toAgentId,
            "request:$type",
            [
                'request_id' => $requestId,
                'data' => $payload,
            ],
            $requestId
        );
        
        // Wait for response (simplified - in real implementation would use async)
        $startTime = microtime(true) * 1000;
        while ((microtime(true) * 1000 - $startTime) < $timeoutMs) {
            if (isset($this->pendingRequests[$requestId]['response'])) {
                $response = $this->pendingRequests[$requestId]['response'];
                unset($this->pendingRequests[$requestId]);
                return $response;
            }
            usleep(100000); // 100ms
        }
        
        // Timeout
        unset($this->pendingRequests[$requestId]);
        throw new \RuntimeException("Request timeout: $requestId");
    }
    
    /**
     * Send a response to a request.
     */
    public function sendResponse(
        string $fromAgentId,
        string $toAgentId,
        string $requestId,
        mixed $payload
    ): void {
        $this->sendMessage(
            $fromAgentId,
            $toAgentId,
            'response',
            [
                'request_id' => $requestId,
                'data' => $payload,
            ],
            $requestId
        );
        
        // Check if this completes a pending request
        if (isset($this->pendingRequests[$requestId])) {
            $this->pendingRequests[$requestId]['response'] = $payload;
        }
    }
    
    /**
     * Register a message handler for a specific type.
     */
    public function registerHandler(string $agentId, string $type, callable $handler): void
    {
        if (!isset($this->messageHandlers[$agentId])) {
            $this->messageHandlers[$agentId] = [];
        }
        
        $this->messageHandlers[$agentId][$type] = $handler;
    }
    
    /**
     * Process messages for an agent.
     */
    public function processMessages(string $agentId): array
    {
        $processed = [];
        
        // Get pending messages from coordinator
        // In real implementation, would get from message queue
        
        foreach ($this->getMessagesForAgent($agentId) as $message) {
            $data = json_decode($message['content'], true);
            
            if (isset($this->messageHandlers[$agentId][$data['type']])) {
                $handler = $this->messageHandlers[$agentId][$data['type']];
                $result = $handler($data['payload'], $message['from']);
                
                $processed[] = [
                    'message_id' => $data['id'],
                    'type' => $data['type'],
                    'result' => $result,
                ];
            }
        }
        
        return $processed;
    }
    
    /**
     * Get message log for debugging/monitoring.
     */
    public function getMessageLog(
        ?string $agentId = null,
        ?int $limit = 100
    ): array {
        $log = $this->messageLog;
        
        if ($agentId) {
            $log = array_filter($log, function($entry) use ($agentId) {
                return $entry['from'] === $agentId || $entry['to'] === $agentId;
            });
        }
        
        if ($limit) {
            $log = array_slice($log, -$limit);
        }
        
        return $log;
    }
    
    /**
     * Clear message log.
     */
    public function clearMessageLog(): void
    {
        $this->messageLog = [];
    }
    
    /**
     * Generate unique message ID.
     */
    private function generateMessageId(): string
    {
        return uniqid('msg_', true);
    }
    
    /**
     * Log a message for debugging.
     */
    private function logMessage(AgentMessage $message): void
    {
        $this->messageLog[] = [
            'from' => $message->from,
            'to' => $message->to,
            'summary' => $message->summary,
            'timestamp' => microtime(true),
        ];
        
        // Keep only last 1000 messages
        if (count($this->messageLog) > 1000) {
            array_shift($this->messageLog);
        }
    }
    
    /**
     * Get messages for a specific agent (stub).
     */
    private function getMessagesForAgent(string $agentId): array
    {
        // In real implementation, would fetch from message queue
        return [];
    }
}