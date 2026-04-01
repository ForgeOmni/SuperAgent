<?php

/**
 * Example: Automated Test Generation
 * 
 * This example demonstrates how to automatically generate
 * unit tests for existing code using SuperAgent.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Tools\Builtin\ReadFileTool;
use SuperAgent\Tools\Builtin\CreateFileTool;
use SuperAgent\Tools\Builtin\BashTool;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Sample class to test
$sampleClass = <<<'PHP'
<?php

namespace App\Services;

class Calculator {
    public function add($a, $b) {
        return $a + $b;
    }
    
    public function subtract($a, $b) {
        return $a - $b;
    }
    
    public function multiply($a, $b) {
        return $a * $b;
    }
    
    public function divide($a, $b) {
        if ($b == 0) {
            throw new \InvalidArgumentException("Division by zero");
        }
        return $a / $b;
    }
    
    public function percentage($value, $percent) {
        return ($value * $percent) / 100;
    }
}
PHP;

// Create sample file
$tempDir = sys_get_temp_dir() . '/superagent_example';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

$classFile = $tempDir . '/Calculator.php';
file_put_contents($classFile, $sampleClass);

echo "🧪 Test Generation Example\n";
echo "Class: {$classFile}\n";
echo str_repeat('-', 50) . "\n\n";

// Create configuration
$config = Config::fromArray([
    'provider' => [
        'type' => 'anthropic',
        'api_key' => $_ENV['ANTHROPIC_API_KEY'],
        'model' => 'claude-3-sonnet-20240229',
    ],
    'tools' => [
        new ReadFileTool(),
        new CreateFileTool(),
        new BashTool(),
    ],
]);

// Create agent
$provider = new AnthropicProvider($config->provider);
$agent = new Agent($provider, $config);

// Generate tests
$prompt = "Please generate comprehensive PHPUnit tests for the Calculator class in {$classFile}.
Include tests for:
- All methods with various inputs
- Edge cases (negative numbers, zero, large numbers)
- Error handling (division by zero)
- Type validation if applicable

Create the test file as {$tempDir}/CalculatorTest.php
Use PHPUnit 9+ syntax and best practices.";

echo "Generating tests...\n\n";

try {
    $response = $agent->query($prompt);
    
    echo "Tests generated successfully!\n\n";
    
    // Display the generated test file
    $testFile = $tempDir . '/CalculatorTest.php';
    if (file_exists($testFile)) {
        echo "Generated test file:\n";
        echo str_repeat('-', 30) . "\n";
        echo file_get_contents($testFile);
        echo "\n" . str_repeat('-', 30) . "\n\n";
        
        // Try to run the tests (if PHPUnit is available)
        echo "Attempting to run tests...\n";
        $runPrompt = "Run the PHPUnit test at {$testFile} and show me the results.";
        
        $testResponse = $agent->query($runPrompt);
        echo $testResponse->content . "\n\n";
    }
    
    // Show cost
    $cost = $agent->getCostTracker()->getTotalCost();
    echo sprintf("Total cost: $%.6f\n", $cost);
    
} catch (Exception $e) {
    echo "Error generating tests: " . $e->getMessage() . "\n";
}

// Cleanup
if (is_dir($tempDir)) {
    array_map('unlink', glob($tempDir . '/*'));
    rmdir($tempDir);
}

echo "\nExample completed.\n";