<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Swarm\AgentMessage;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\Backends\BackendInterface;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\Backends\ProcessBackend;
use SuperAgent\Swarm\PlanApprovalResponseMessage;
use SuperAgent\Swarm\ShutdownRequestMessage;
use SuperAgent\Swarm\ShutdownResponseMessage;
use SuperAgent\Swarm\StructuredMessage;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Tool for sending messages between agents.
 */
class SendMessageTool extends Tool
{
    private const TEAM_LEAD_NAME = 'team-lead';
    
    private ?TeamContext $teamContext = null;
    private LoggerInterface $logger;
    private array $backends = [];
    
    public function __construct(
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->initializeBackends();
    }
    
    public function name(): string
    {
        return 'send_message';
    }
    
    public function description(): string
    {
        return 'Send messages to agent teammates for coordination and communication. ' .
               'Supports plain text messages and structured commands.';
    }
    
    public function category(): string
    {
        return 'communication';
    }
    
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type' => 'string',
                    'description' => 'Recipient: teammate name, "*" for broadcast to all teammates',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'A 5-10 word summary shown as a preview (required for plain text messages)',
                ],
                'message' => [
                    'oneOf' => [
                        [
                            'type' => 'string',
                            'description' => 'Plain text message content',
                        ],
                        [
                            'type' => 'object',
                            'description' => 'Structured message (shutdown_request, shutdown_response, plan_approval_response)',
                        ],
                    ],
                ],
            ],
            'required' => ['to', 'message'],
        ];
    }
    
    public function isReadOnly(): bool
    {
        // Sending messages is a read-only operation (doesn't modify files/system)
        return true;
    }
    
    public function execute(array $input): ToolResult
    {
        try {
            $to = $input['to'];
            $message = $input['message'];
            $summary = $input['summary'] ?? null;
            
            // Validate input
            if (is_string($message) && empty($summary)) {
                return ToolResult::failure('Summary is required for plain text messages');
            }
            
            // Get sender information
            $senderName = $this->getSenderName();
            $senderColor = $this->getSenderColor();
            
            // Handle structured messages
            if (is_array($message)) {
                return $this->handleStructuredMessage($to, $message, $senderName);
            }
            
            // Handle broadcast
            if ($to === '*') {
                return $this->handleBroadcast($message, $summary, $senderName, $senderColor);
            }
            
            // Handle direct message
            return $this->handleDirectMessage($to, $message, $summary, $senderName, $senderColor);
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to send message", [
                'error' => $e->getMessage(),
                'to' => $input['to'] ?? null,
            ]);
            
            return ToolResult::failure("Failed to send message: " . $e->getMessage());
        }
    }
    
    /**
     * Set the team context for this tool.
     */
    public function setTeamContext(TeamContext $context): void
    {
        $this->teamContext = $context;
    }
    
    /**
     * Handle structured message (shutdown, plan approval, etc).
     */
    private function handleStructuredMessage(
        string $to,
        array $message,
        string $senderName
    ): ToolResult {
        $type = $message['type'] ?? '';
        
        switch ($type) {
            case 'shutdown_request':
                return $this->handleShutdownRequest($to, $message, $senderName);
                
            case 'shutdown_response':
                return $this->handleShutdownResponse($message, $senderName);
                
            case 'plan_approval_response':
                return $this->handlePlanApprovalResponse($to, $message, $senderName);
                
            default:
                return ToolResult::failure("Unknown structured message type: {$type}");
        }
    }
    
    /**
     * Handle shutdown request message.
     */
    private function handleShutdownRequest(
        string $to,
        array $message,
        string $senderName
    ): ToolResult {
        $requestId = 'shutdown_' . uniqid();
        $reason = $message['reason'] ?? null;
        
        $shutdownMessage = new ShutdownRequestMessage($requestId, $senderName, $reason);
        
        $agentMessage = new AgentMessage(
            from: $senderName,
            to: $to,
            content: json_encode($shutdownMessage->toArray()),
            summary: 'Shutdown request',
            requestId: $requestId,
        );
        
        $this->sendToAgent($to, $agentMessage);
        
        return ToolResult::success([
            'success' => true,
            'message' => "Shutdown request sent to {$to}",
            'request_id' => $requestId,
            'target' => $to,
        ]);
    }
    
    /**
     * Handle shutdown response message.
     */
    private function handleShutdownResponse(
        array $message,
        string $senderName
    ): ToolResult {
        $requestId = $message['request_id'] ?? '';
        $approve = $message['approve'] ?? false;
        $reason = $message['reason'] ?? null;
        
        if (empty($requestId)) {
            return ToolResult::failure('request_id is required for shutdown_response');
        }
        
        $responseMessage = new ShutdownResponseMessage($requestId, $senderName, $approve, $reason);
        
        $agentMessage = new AgentMessage(
            from: $senderName,
            to: self::TEAM_LEAD_NAME,
            content: json_encode($responseMessage->toArray()),
            summary: $approve ? 'Shutdown approved' : 'Shutdown rejected',
            requestId: $requestId,
        );
        
        $this->sendToAgent(self::TEAM_LEAD_NAME, $agentMessage);
        
        if ($approve) {
            // Initiate self shutdown
            $this->initiateShutdown($senderName);
            
            return ToolResult::success([
                'success' => true,
                'message' => "Shutdown approved. Agent {$senderName} is now exiting.",
                'request_id' => $requestId,
            ]);
        }
        
        return ToolResult::success([
            'success' => true,
            'message' => "Shutdown rejected. Reason: \"{$reason}\". Continuing to work.",
            'request_id' => $requestId,
        ]);
    }
    
    /**
     * Handle plan approval response message.
     */
    private function handlePlanApprovalResponse(
        string $to,
        array $message,
        string $senderName
    ): ToolResult {
        // Only team lead can approve/reject plans
        if (!$this->teamContext || !$this->teamContext->isLeader($senderName)) {
            return ToolResult::failure(
                'Only the team lead can approve plans. Teammates cannot approve their own or other plans.'
            );
        }
        
        $requestId = $message['request_id'] ?? '';
        $approve = $message['approve'] ?? false;
        $feedback = $message['feedback'] ?? null;
        
        if (empty($requestId)) {
            return ToolResult::failure('request_id is required for plan_approval_response');
        }
        
        $responseMessage = new PlanApprovalResponseMessage(
            $requestId,
            $senderName,
            $approve,
            $feedback
        );
        
        $agentMessage = new AgentMessage(
            from: $senderName,
            to: $to,
            content: json_encode($responseMessage->toArray()),
            summary: $approve ? 'Plan approved' : 'Plan rejected',
            requestId: $requestId,
        );
        
        $this->sendToAgent($to, $agentMessage);
        
        if ($approve) {
            return ToolResult::success([
                'success' => true,
                'message' => "Plan approved for {$to}. They will receive the approval and can proceed with implementation.",
                'request_id' => $requestId,
            ]);
        }
        
        return ToolResult::success([
            'success' => true,
            'message' => "Plan rejected for {$to} with feedback: \"{$feedback}\"",
            'request_id' => $requestId,
        ]);
    }
    
    /**
     * Handle broadcast message to all team members.
     */
    private function handleBroadcast(
        string $content,
        ?string $summary,
        string $senderName,
        ?string $senderColor
    ): ToolResult {
        if (!$this->teamContext) {
            return ToolResult::failure(
                'Not in a team context. Create a team with Agent tool first.'
            );
        }
        
        $recipients = [];
        foreach ($this->teamContext->getMembers() as $member) {
            if ($member->name === $senderName) {
                continue; // Don't send to self
            }
            
            $agentMessage = new AgentMessage(
                from: $senderName,
                to: $member->name,
                content: $content,
                summary: $summary,
                color: $senderColor,
            );
            
            $this->sendToAgent($member->agentId, $agentMessage);
            $recipients[] = $member->name;
        }
        
        if (empty($recipients)) {
            return ToolResult::success([
                'success' => true,
                'message' => 'No teammates to broadcast to (you are the only team member)',
                'recipients' => [],
            ]);
        }
        
        return ToolResult::success([
            'success' => true,
            'message' => "Message broadcast to " . count($recipients) . " teammate(s): " . implode(', ', $recipients),
            'recipients' => $recipients,
            'routing' => [
                'sender' => $senderName,
                'sender_color' => $senderColor,
                'target' => '@team',
                'summary' => $summary,
                'content' => $content,
            ],
        ]);
    }
    
    /**
     * Handle direct message to a specific agent.
     */
    private function handleDirectMessage(
        string $to,
        string $content,
        ?string $summary,
        string $senderName,
        ?string $senderColor
    ): ToolResult {
        // Resolve recipient
        $recipientId = $this->resolveRecipient($to);
        if (!$recipientId) {
            return ToolResult::failure("Recipient '{$to}' not found");
        }
        
        $agentMessage = new AgentMessage(
            from: $senderName,
            to: $to,
            content: $content,
            summary: $summary,
            color: $senderColor,
        );
        
        $this->sendToAgent($recipientId, $agentMessage);
        
        $recipientColor = $this->getRecipientColor($recipientId);
        
        return ToolResult::success([
            'success' => true,
            'message' => "Message sent to {$to}'s inbox",
            'routing' => [
                'sender' => $senderName,
                'sender_color' => $senderColor,
                'target' => "@{$to}",
                'target_color' => $recipientColor,
                'summary' => $summary,
                'content' => $content,
            ],
        ]);
    }
    
    /**
     * Send message to an agent via appropriate backend.
     */
    private function sendToAgent(string $agentId, AgentMessage $message): void
    {
        // Find the agent's backend
        $backend = $this->findAgentBackend($agentId);
        if ($backend) {
            $backend->sendMessage($agentId, $message);
        } else {
            // Fallback to mailbox file
            $this->writeToMailbox($agentId, $message);
        }
    }
    
    /**
     * Write message to mailbox file (fallback).
     */
    private function writeToMailbox(string $agentId, AgentMessage $message): void
    {
        $mailboxDir = sys_get_temp_dir() . '/superagent_mailboxes';
        if (!is_dir($mailboxDir)) {
            mkdir($mailboxDir, 0755, true);
        }
        
        $mailboxPath = $mailboxDir . '/' . $agentId . '.mailbox';
        
        $messages = [];
        if (file_exists($mailboxPath)) {
            $content = file_get_contents($mailboxPath);
            if ($content) {
                $messages = json_decode($content, true) ?? [];
            }
        }
        
        $messages[] = $message->toArray();
        
        // Keep only last 100 messages
        if (count($messages) > 100) {
            $messages = array_slice($messages, -100);
        }
        
        file_put_contents($mailboxPath, json_encode($messages, JSON_PRETTY_PRINT));
    }
    
    /**
     * Find the backend managing a specific agent.
     */
    private function findAgentBackend(string $agentId): ?BackendInterface
    {
        foreach ($this->backends as $backend) {
            if ($backend->isRunning($agentId)) {
                return $backend;
            }
        }
        
        return null;
    }
    
    /**
     * Resolve recipient name to agent ID.
     */
    private function resolveRecipient(string $nameOrId): ?string
    {
        if ($this->teamContext) {
            return $this->teamContext->resolveAgentId($nameOrId);
        }
        
        // Assume it's already an agent ID
        return $nameOrId;
    }
    
    /**
     * Get sender name.
     */
    private function getSenderName(): string
    {
        // Try to get from context metadata
        if ($this->teamContext) {
            // Would need access to current agent context
            return 'agent';
        }
        
        return self::TEAM_LEAD_NAME;
    }
    
    /**
     * Get sender color.
     */
    private function getSenderColor(): ?string
    {
        // Would need access to current agent context
        return null;
    }
    
    /**
     * Get recipient color.
     */
    private function getRecipientColor(string $agentId): ?string
    {
        if ($this->teamContext) {
            $member = $this->teamContext->getMember($agentId);
            return $member?->color;
        }
        
        return null;
    }
    
    /**
     * Initiate shutdown for the sending agent.
     */
    private function initiateShutdown(string $agentName): void
    {
        // This would typically signal the agent to shutdown
        // Implementation depends on the agent framework
        $this->logger->info("Agent shutdown initiated", [
            'agent' => $agentName,
        ]);
    }
    
    /**
     * Initialize backends.
     */
    private function initializeBackends(): void
    {
        $this->backends[] = new InProcessBackend($this->logger);
        $this->backends[] = new ProcessBackend(null, $this->logger);
    }
}