<?php

/**
 * Example: Simple Interactive Chat
 * 
 * This example demonstrates a basic interactive chat session with SuperAgent.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Tools\ToolRegistry;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Create configuration
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => $_ENV['ANTHROPIC_API_KEY'],
        'model' => 'claude-3-haiku-20240307',
    ],
    'tools' => ToolRegistry::getInstance()->getAllTools(),
    'streaming' => true,
]);

// Create provider and agent
$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

// Welcome message
echo "🤖 SuperAgent Chat Example\n";
echo "Type 'exit' to quit, 'clear' to reset conversation\n";
echo str_repeat('-', 50) . "\n\n";

// Chat loop
while (true) {
    // Get user input
    echo "You: ";
    $input = trim(fgets(STDIN));
    
    if (strtolower($input) === 'exit') {
        echo "Goodbye! 👋\n";
        break;
    }
    
    if (strtolower($input) === 'clear') {
        $agent = new Agent($provider, $config); // Reset agent
        echo "Conversation cleared.\n\n";
        continue;
    }
    
    // Process with agent
    echo "\nAssistant: ";
    
    try {
        // Stream the response
        $stream = $agent->stream($input);
        
        foreach ($stream as $chunk) {
            if (isset($chunk['content'])) {
                echo $chunk['content'];
                flush(); // Ensure immediate output
            }
            
            if (isset($chunk['tool_use'])) {
                echo "\n[Using tool: {$chunk['tool_use']['name']}]\n";
            }
        }
        
        echo "\n\n";
        
        // Show cost if tracking
        $cost = $agent->getCostTracker()->getTotalCost();
        if ($cost > 0) {
            echo sprintf("(Cost: $%.6f)\n", $cost);
        }
        echo "\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n\n";
    }
}

echo "Session ended.\n";