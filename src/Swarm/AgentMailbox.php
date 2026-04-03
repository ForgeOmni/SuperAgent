<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Mailbox system for agent communication.
 * Provides persistent message queues for each agent.
 */
class AgentMailbox
{
    private string $mailboxDir;
    private LoggerInterface $logger;
    private array $messageCache = [];
    private int $maxMessages = 100;
    
    public function __construct(
        ?string $mailboxDir = null,
        ?LoggerInterface $logger = null
    ) {
        $this->mailboxDir = $mailboxDir ?? sys_get_temp_dir() . '/superagent_mailboxes';
        $this->logger = $logger ?? new NullLogger();
        
        // Ensure mailbox directory exists
        if (!is_dir($this->mailboxDir)) {
            mkdir($this->mailboxDir, 0755, true);
        }
    }
    
    /**
     * Write a message to an agent's mailbox.
     */
    public function writeMessage(string $agentId, AgentMessage $message): bool
    {
        try {
            $mailboxPath = $this->getMailboxPath($agentId);
            
            // Load existing messages
            $messages = $this->readMessages($agentId);
            
            // Add new message with type serialized
            $messageData = $message->toArray();
            if (isset($message->type)) {
                $messageData['type'] = $message->type instanceof MessageType ? $message->type->value : $message->type;
            }
            $messages[] = $messageData;
            
            // Trim to max messages (keep only recent)
            if (count($messages) > $this->maxMessages) {
                $messages = array_slice($messages, -$this->maxMessages);
            }
            
            // Write to file
            $json = json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($mailboxPath, $json, LOCK_EX);
            
            // Update cache
            $this->messageCache[$agentId] = $messages;
            
            $this->logger->debug("Message written to mailbox", [
                'agent_id' => $agentId,
                'from' => $message->from,
                'summary' => $message->summary,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to write to mailbox", [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * Read all messages for an agent.
     */
    public function readMessages(string $agentId): array
    {
        // Check cache first
        if (isset($this->messageCache[$agentId])) {
            return $this->messageCache[$agentId];
        }
        
        $mailboxPath = $this->getMailboxPath($agentId);
        
        if (!file_exists($mailboxPath)) {
            return [];
        }
        
        $content = file_get_contents($mailboxPath);
        if (empty($content)) {
            return [];
        }
        
        $messages = json_decode($content, true) ?? [];
        
        // Cache for future reads
        $this->messageCache[$agentId] = $messages;
        
        return $messages;
    }
    
    /**
     * Read and remove messages from mailbox (consume).
     */
    public function consumeMessages(string $agentId, ?int $limit = null): array
    {
        $messages = $this->readMessages($agentId);
        
        if (empty($messages)) {
            return [];
        }
        
        // Take messages up to limit
        if ($limit !== null && $limit > 0) {
            $consumed = array_slice($messages, 0, $limit);
            $remaining = array_slice($messages, $limit);
        } else {
            $consumed = $messages;
            $remaining = [];
        }
        
        // Update mailbox with remaining messages
        if (empty($remaining)) {
            $this->clearMailbox($agentId);
        } else {
            $mailboxPath = $this->getMailboxPath($agentId);
            file_put_contents(
                $mailboxPath,
                json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            $this->messageCache[$agentId] = $remaining;
        }
        
        // Convert to AgentMessage objects
        $result = [];
        foreach ($consumed as $data) {
            $result[] = AgentMessage::fromArray($data);
        }
        
        return $result;
    }
    
    /**
     * Peek at messages without removing them.
     */
    public function peekMessages(string $agentId, ?int $limit = null): array
    {
        $messages = $this->readMessages($agentId);
        
        if ($limit !== null && $limit > 0) {
            $messages = array_slice($messages, 0, $limit);
        }
        
        // Convert to AgentMessage objects
        $result = [];
        foreach ($messages as $data) {
            $result[] = AgentMessage::fromArray($data);
        }
        
        return $result;
    }
    
    /**
     * Check if agent has pending messages.
     */
    public function hasMessages(string $agentId): bool
    {
        $messages = $this->readMessages($agentId);
        return !empty($messages);
    }
    
    /**
     * Get count of pending messages.
     */
    public function getMessageCount(string $agentId): int
    {
        return count($this->readMessages($agentId));
    }
    
    /**
     * Clear all messages for an agent.
     */
    public function clearMailbox(string $agentId): void
    {
        $mailboxPath = $this->getMailboxPath($agentId);
        
        if (file_exists($mailboxPath)) {
            unlink($mailboxPath);
        }
        
        unset($this->messageCache[$agentId]);
        
        $this->logger->debug("Mailbox cleared", [
            'agent_id' => $agentId,
        ]);
    }
    
    /**
     * Clear all mailboxes.
     */
    public function clearAllMailboxes(): void
    {
        $files = glob($this->mailboxDir . '/*.mailbox');
        
        foreach ($files as $file) {
            unlink($file);
        }
        
        $this->messageCache = [];
        
        $this->logger->info("All mailboxes cleared");
    }
    
    /**
     * Get list of all agents with mailboxes.
     */
    public function getActiveMailboxes(): array
    {
        $files = glob($this->mailboxDir . '/*.mailbox');
        $agents = [];
        
        foreach ($files as $file) {
            $agentId = basename($file, '.mailbox');
            $agents[$agentId] = $this->getMessageCount($agentId);
        }
        
        return $agents;
    }
    
    /**
     * Get mailbox file path for an agent.
     */
    private function getMailboxPath(string $agentId): string
    {
        // Sanitize agent ID for filesystem
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $agentId);
        return $this->mailboxDir . '/' . $safeId . '.mailbox';
    }
    
    /**
     * Broadcast a message to multiple agents.
     */
    public function broadcastMessage(AgentMessage $message, array $agentIds): int
    {
        $successCount = 0;
        
        foreach ($agentIds as $agentId) {
            if ($agentId === $message->from) {
                continue; // Don't send to self
            }
            
            // Create new message for each recipient
            $broadcastMsg = new AgentMessage(
                from: $message->from,
                to: $agentId,
                content: $message->content,
                summary: $message->summary,
                timestamp: $message->timestamp,
                requestId: $message->requestId,
                color: $message->color,
                metadata: array_merge($message->metadata, ['type' => 'broadcast'])
            );
            
            if ($this->writeMessage($agentId, $broadcastMsg)) {
                $successCount++;
            }
        }
        
        return $successCount;
    }
    
    /**
     * Filter messages by criteria.
     */
    public function filterMessages(
        string $agentId,
        ?string $from = null,
        ?string $type = null,
        ?int $sinceTimestamp = null
    ): array {
        $messages = $this->peekMessages($agentId);
        
        if ($from !== null) {
            $messages = array_filter($messages, fn($m) => $m->from === $from);
        }
        
        if ($type !== null) {
            $messages = array_filter($messages, fn($m) => ($m->metadata['type'] ?? null) === $type);
        }
        
        if ($sinceTimestamp !== null) {
            $messages = array_filter($messages, fn($m) => strtotime($m->timestamp) >= $sinceTimestamp);
        }
        
        return array_values($messages);
    }
    
    /**
     * Archive old messages to a separate file.
     */
    public function archiveMessages(string $agentId, int $olderThanTimestamp): int
    {
        $messages = $this->readMessages($agentId);
        $archived = [];
        $remaining = [];
        
        foreach ($messages as $data) {
            if (($data['timestamp'] ?? time()) < $olderThanTimestamp) {
                $archived[] = $data;
            } else {
                $remaining[] = $data;
            }
        }
        
        if (empty($archived)) {
            return 0;
        }
        
        // Write archive
        $archivePath = $this->mailboxDir . '/archive/' . $agentId . '_' . date('Y-m-d_H-i-s') . '.archive';
        $archiveDir = dirname($archivePath);
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }
        
        file_put_contents(
            $archivePath,
            json_encode($archived, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        
        // Update mailbox with remaining
        if (empty($remaining)) {
            $this->clearMailbox($agentId);
        } else {
            $mailboxPath = $this->getMailboxPath($agentId);
            file_put_contents(
                $mailboxPath,
                json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            $this->messageCache[$agentId] = $remaining;
        }
        
        $this->logger->info("Messages archived", [
            'agent_id' => $agentId,
            'archived_count' => count($archived),
            'archive_path' => $archivePath,
        ]);
        
        return count($archived);
    }
}