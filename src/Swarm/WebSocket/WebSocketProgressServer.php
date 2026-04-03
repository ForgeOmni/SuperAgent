<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * WebSocket server for real-time agent progress broadcasting.
 * Allows browser-based dashboards to monitor agent execution.
 */
class WebSocketProgressServer implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;
    protected ParallelAgentCoordinator $coordinator;
    protected LoggerInterface $logger;
    protected array $subscriptions = [];
    protected bool $broadcasting = false;
    
    public function __construct(
        ?ParallelAgentCoordinator $coordinator = null,
        ?LoggerInterface $logger = null
    ) {
        $this->clients = new \SplObjectStorage();
        $this->coordinator = $coordinator ?? ParallelAgentCoordinator::getInstance();
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $clientId = spl_object_hash($conn);
        $this->subscriptions[$clientId] = [
            'teams' => [],
            'agents' => [],
            'all' => false,
        ];
        
        $this->logger->info("WebSocket client connected", [
            'client_id' => $clientId,
            'total_clients' => count($this->clients),
        ]);
        
        // Send initial state
        $this->sendInitialState($conn);
    }
    
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $clientId = spl_object_hash($from);
        
        try {
            $data = json_decode($msg, true);
            if (!$data || !isset($data['type'])) {
                throw new \InvalidArgumentException('Invalid message format');
            }
            
            switch ($data['type']) {
                case 'subscribe':
                    $this->handleSubscribe($from, $data);
                    break;
                    
                case 'unsubscribe':
                    $this->handleUnsubscribe($from, $data);
                    break;
                    
                case 'get_progress':
                    $this->sendProgress($from, $data['agent_id'] ?? null);
                    break;
                    
                case 'get_hierarchy':
                    $this->sendHierarchy($from);
                    break;
                    
                case 'ping':
                    $from->send(json_encode(['type' => 'pong', 'timestamp' => time()]));
                    break;
                    
                default:
                    $this->logger->warning("Unknown message type", [
                        'client_id' => $clientId,
                        'type' => $data['type'],
                    ]);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error handling message", [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            $from->send(json_encode([
                'type' => 'error',
                'message' => $e->getMessage(),
            ]));
        }
    }
    
    public function onClose(ConnectionInterface $conn): void
    {
        $clientId = spl_object_hash($conn);
        $this->clients->detach($conn);
        unset($this->subscriptions[$clientId]);
        
        $this->logger->info("WebSocket client disconnected", [
            'client_id' => $clientId,
            'remaining_clients' => count($this->clients),
        ]);
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->logger->error("WebSocket connection error", [
            'client_id' => spl_object_hash($conn),
            'error' => $e->getMessage(),
        ]);
        
        $conn->close();
    }
    
    /**
     * Start broadcasting progress updates to all subscribed clients.
     */
    public function startBroadcasting(int $intervalMs = 500): void
    {
        if ($this->broadcasting) {
            return;
        }
        
        $this->broadcasting = true;
        $this->logger->info("Started broadcasting progress updates");
        
        // Use React event loop if available, otherwise fallback to simple loop
        $this->broadcastLoop($intervalMs);
    }
    
    /**
     * Stop broadcasting progress updates.
     */
    public function stopBroadcasting(): void
    {
        $this->broadcasting = false;
        $this->logger->info("Stopped broadcasting progress updates");
    }
    
    /**
     * Handle subscription request.
     */
    protected function handleSubscribe(ConnectionInterface $conn, array $data): void
    {
        $clientId = spl_object_hash($conn);
        
        if (isset($data['all']) && $data['all'] === true) {
            $this->subscriptions[$clientId]['all'] = true;
        }
        
        if (isset($data['team'])) {
            $this->subscriptions[$clientId]['teams'][] = $data['team'];
        }
        
        if (isset($data['agent'])) {
            $this->subscriptions[$clientId]['agents'][] = $data['agent'];
        }
        
        $conn->send(json_encode([
            'type' => 'subscribed',
            'subscription' => $this->subscriptions[$clientId],
        ]));
    }
    
    /**
     * Handle unsubscribe request.
     */
    protected function handleUnsubscribe(ConnectionInterface $conn, array $data): void
    {
        $clientId = spl_object_hash($conn);
        
        if (isset($data['all'])) {
            $this->subscriptions[$clientId] = [
                'teams' => [],
                'agents' => [],
                'all' => false,
            ];
        }
        
        if (isset($data['team'])) {
            $this->subscriptions[$clientId]['teams'] = array_diff(
                $this->subscriptions[$clientId]['teams'],
                [$data['team']]
            );
        }
        
        if (isset($data['agent'])) {
            $this->subscriptions[$clientId]['agents'] = array_diff(
                $this->subscriptions[$clientId]['agents'],
                [$data['agent']]
            );
        }
        
        $conn->send(json_encode([
            'type' => 'unsubscribed',
            'subscription' => $this->subscriptions[$clientId],
        ]));
    }
    
    /**
     * Send initial state to newly connected client.
     */
    protected function sendInitialState(ConnectionInterface $conn): void
    {
        $state = [
            'type' => 'initial_state',
            'progress' => $this->coordinator->getConsolidatedProgress(),
            'hierarchy' => $this->coordinator->getHierarchicalDisplay(),
            'timestamp' => microtime(true),
        ];
        
        $conn->send(json_encode($state));
    }
    
    /**
     * Send progress update to a client.
     */
    protected function sendProgress(ConnectionInterface $conn, ?string $agentId = null): void
    {
        if ($agentId) {
            $tracker = $this->coordinator->getTracker($agentId);
            if ($tracker) {
                $progress = $tracker->getProgress();
            } else {
                $progress = null;
            }
        } else {
            $progress = $this->coordinator->getConsolidatedProgress();
        }
        
        $conn->send(json_encode([
            'type' => 'progress',
            'agent_id' => $agentId,
            'data' => $progress,
            'timestamp' => microtime(true),
        ]));
    }
    
    /**
     * Send hierarchy update to a client.
     */
    protected function sendHierarchy(ConnectionInterface $conn): void
    {
        $conn->send(json_encode([
            'type' => 'hierarchy',
            'data' => $this->coordinator->getHierarchicalDisplay(),
            'timestamp' => microtime(true),
        ]));
    }
    
    /**
     * Broadcast updates to all subscribed clients.
     */
    protected function broadcastUpdates(): void
    {
        $progress = $this->coordinator->getConsolidatedProgress();
        $hierarchy = $this->coordinator->getHierarchicalDisplay();
        
        $update = json_encode([
            'type' => 'update',
            'progress' => $progress,
            'hierarchy' => $hierarchy,
            'timestamp' => microtime(true),
        ]);
        
        foreach ($this->clients as $client) {
            $clientId = spl_object_hash($client);
            
            // Check if client should receive this update
            if ($this->shouldReceiveUpdate($clientId, $progress, $hierarchy)) {
                $client->send($update);
            }
        }
    }
    
    /**
     * Check if a client should receive an update based on subscriptions.
     */
    protected function shouldReceiveUpdate(string $clientId, array $progress, array $hierarchy): bool
    {
        $sub = $this->subscriptions[$clientId] ?? null;
        if (!$sub) {
            return false;
        }
        
        // Subscribe to all
        if ($sub['all']) {
            return true;
        }
        
        // Check team subscriptions
        foreach ($hierarchy as $group) {
            if ($group['type'] === 'team' && in_array($group['name'], $sub['teams'])) {
                return true;
            }
        }
        
        // Check agent subscriptions
        foreach ($progress['agents'] as $agent) {
            if (in_array($agent['agentId'], $sub['agents'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Simple broadcast loop (fallback when React event loop not available).
     */
    protected function broadcastLoop(int $intervalMs): void
    {
        while ($this->broadcasting) {
            $this->broadcastUpdates();
            usleep($intervalMs * 1000);
        }
    }
}