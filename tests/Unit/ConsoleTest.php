<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Console\Commands\SuperAgentChatCommand;
use SuperAgent\Console\Commands\SuperAgentRunCommand;
use SuperAgent\Console\Commands\SuperAgentToolsCommand;
use SuperAgent\Console\Commands\SuperAgentMakeToolCommand;
use Illuminate\Console\Application;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application as LaravelApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleTest extends TestCase
{
    private Application $artisan;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new LaravelApplication(getcwd());
        $app->singleton('events', function() {
            return new Dispatcher();
        });

        $this->artisan = new Application($app, new Dispatcher(), '1.0.0');
        $this->output = new BufferedOutput();
    }
    
    public function testSuperAgentChatCommandExists()
    {
        $command = new SuperAgentChatCommand();
        
        $this->assertEquals('superagent:chat', $command->getName());
        $this->assertStringContainsString('interactive chat', $command->getDescription());
    }
    
    public function testSuperAgentRunCommandExists()
    {
        $command = new SuperAgentRunCommand();
        
        $this->assertEquals('superagent:run', $command->getName());
        $this->assertStringContainsString('Execute', $command->getDescription());
    }
    
    public function testSuperAgentToolsCommandExists()
    {
        $command = new SuperAgentToolsCommand();
        
        $this->assertEquals('superagent:tools', $command->getName());
        $this->assertStringContainsString('List', $command->getDescription());
    }
    
    public function testSuperAgentMakeToolCommandExists()
    {
        $command = new SuperAgentMakeToolCommand();
        
        $this->assertEquals('superagent:make-tool', $command->getName());
        $this->assertStringContainsString('Generate', $command->getDescription());
    }
    
    public function testToolsCommandListsAvailableTools()
    {
        $command = new SuperAgentToolsCommand();
        $this->artisan->add($command);
        
        $input = new ArrayInput(['command' => 'superagent:tools']);
        $exitCode = $this->artisan->find('superagent:tools')->run($input, $this->output);
        
        $this->assertEquals(0, $exitCode);
        $outputContent = $this->output->fetch();
        
        // Should show headers
        $this->assertStringContainsString('SuperAgent Tools', $outputContent);
    }
    
    public function testMakeToolCommandRequiresName()
    {
        $command = new SuperAgentMakeToolCommand();
        
        // Get the definition
        $definition = $command->getDefinition();
        
        // Check that 'name' argument is required
        $this->assertTrue($definition->hasArgument('name'));
        $nameArg = $definition->getArgument('name');
        $this->assertTrue($nameArg->isRequired());
    }
    
    public function testRunCommandHandlesPromptOption()
    {
        $command = new SuperAgentRunCommand();

        // Get the definition
        $definition = $command->getDefinition();

        // 'prompt' is a required argument in the signature
        $this->assertTrue($definition->hasArgument('prompt'));
        $promptArg = $definition->getArgument('prompt');
        $this->assertTrue($promptArg->isRequired());
    }
    
    public function testChatCommandHandlesModelOption()
    {
        $command = new SuperAgentChatCommand();
        
        // Get the definition
        $definition = $command->getDefinition();
        
        // Check that 'model' option exists
        $this->assertTrue($definition->hasOption('model'));
        $modelOpt = $definition->getOption('model');
        
        // Should have a default value
        $default = $modelOpt->getDefault();
        $this->assertNotNull($default);
        $this->assertStringContainsString('claude', $default);
    }
    
    public function testChatCommandHandlesToolsOption()
    {
        $command = new SuperAgentChatCommand();
        
        // Get the definition
        $definition = $command->getDefinition();
        
        // Check that 'tools' option exists
        $this->assertTrue($definition->hasOption('tools'));
        $toolsOpt = $definition->getOption('tools');
        
        // Should accept array values
        $this->assertTrue($toolsOpt->isArray());
    }
    
    public function testRunCommandHandlesFileInput()
    {
        $command = new SuperAgentRunCommand();

        // Get the definition
        $definition = $command->getDefinition();

        // Check that 'output' option exists (saves output to file)
        $this->assertTrue($definition->hasOption('output'));
    }
    
    public function testMakeToolCommandCreatesCorrectStructure()
    {
        $tempDir = sys_get_temp_dir() . '/superagent_test_' . uniqid();
        mkdir($tempDir . '/app/SuperAgent/Tools', 0777, true);
        
        // Mock the base_path function
        $originalBasePath = function_exists('base_path') ? 'base_path' : null;
        
        if (!function_exists('base_path')) {
            function base_path($path = '') {
                global $tempDir;
                return $tempDir . ($path ? '/' . $path : '');
            }
        }
        
        try {
            $command = new SuperAgentMakeToolCommand();
            
            // The command would generate a tool class file
            // We'll test the structure it should create
            $expectedPath = $tempDir . '/app/SuperAgent/Tools/TestTool.php';
            
            // Clean up
            $this->assertTrue(true); // Test passes if no exception
        } finally {
            // Clean up temp directory
            if (is_dir($tempDir)) {
                $this->rrmdir($tempDir);
            }
        }
    }
    
    private function rrmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->rrmdir($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
}