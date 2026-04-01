<?php

/**
 * Example: Automated Code Refactoring
 * 
 * This example shows how to use SuperAgent to refactor code
 * with specific improvement goals.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Tools\Builtin\ReadFileTool;
use SuperAgent\Tools\Builtin\EditFileTool;
use SuperAgent\Skills\SkillManager;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Sample code to refactor (in reality, this would be an existing file)
$sampleCode = <<<'PHP'
<?php

class UserController {
    public function getUser($id) {
        $conn = mysqli_connect("localhost", "user", "pass", "db");
        $id = mysqli_real_escape_string($conn, $id);
        $result = mysqli_query($conn, "SELECT * FROM users WHERE id = '$id'");
        $user = mysqli_fetch_assoc($result);
        mysqli_close($conn);
        return $user;
    }
    
    public function updateUser($id, $name, $email) {
        $conn = mysqli_connect("localhost", "user", "pass", "db");
        $id = mysqli_real_escape_string($conn, $id);
        $name = mysqli_real_escape_string($conn, $name);
        $email = mysqli_real_escape_string($conn, $email);
        mysqli_query($conn, "UPDATE users SET name='$name', email='$email' WHERE id='$id'");
        mysqli_close($conn);
    }
}
PHP;

// Create sample file
$tempFile = sys_get_temp_dir() . '/UserController.php';
file_put_contents($tempFile, $sampleCode);

echo "🔧 Code Refactoring Example\n";
echo "File: {$tempFile}\n";
echo str_repeat('-', 50) . "\n\n";

// Create configuration with specific tools
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => $_ENV['ANTHROPIC_API_KEY'],
        'model' => 'claude-3-sonnet-20240229',
    ],
    'tools' => [
        new ReadFileTool(),
        new EditFileTool(),
    ],
    'streaming' => false,
]);

// Create agent
$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

// Use the refactor skill
$skillManager = SkillManager::getInstance();
$refactorPrompt = $skillManager->execute('refactor', [
    'file' => $tempFile,
    'aspect' => 'security, maintainability, and modern PHP practices',
    'pattern' => 'Repository Pattern',
]);

echo "Refactoring request:\n";
echo $refactorPrompt . "\n";
echo str_repeat('-', 50) . "\n\n";

// Execute refactoring
try {
    $response = $agent->query($refactorPrompt);
    
    echo "Refactoring completed!\n\n";
    echo "Agent response:\n";
    echo $response->content . "\n\n";
    
    // Show the refactored code
    echo "Refactored code:\n";
    echo str_repeat('-', 30) . "\n";
    echo file_get_contents($tempFile);
    echo "\n" . str_repeat('-', 30) . "\n\n";
    
    // Show cost
    $cost = $agent->getCostTracker()->getTotalCost();
    echo sprintf("Total cost: $%.6f\n", $cost);
    
} catch (Exception $e) {
    echo "Error during refactoring: " . $e->getMessage() . "\n";
}

// Cleanup
unlink($tempFile);
echo "\nExample completed.\n";