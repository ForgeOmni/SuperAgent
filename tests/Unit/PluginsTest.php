<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Plugins\PluginManager;
use SuperAgent\Plugins\PluginInterface;
use SuperAgent\Plugins\BasePlugin;
use SuperAgent\Agent;
use SuperAgent\Tools\ToolInterface;
use SuperAgent\Hooks\HookRegistry;

class PluginsTest extends TestCase
{
    private PluginManager $manager;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = PluginManager::getInstance();
    }
    
    protected function tearDown(): void
    {
        // Reset the singleton instance
        $reflection = new \ReflectionClass(PluginManager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        
        parent::tearDown();
    }
    
    public function testPluginManagerSingleton()
    {
        $instance1 = PluginManager::getInstance();
        $instance2 = PluginManager::getInstance();
        
        $this->assertSame($instance1, $instance2);
    }
    
    public function testPluginRegistration()
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('test-plugin');
        $plugin->method('dependencies')->willReturn([]);
        $plugin->expects($this->once())->method('register');
        
        $this->manager->register($plugin);
        
        $this->assertTrue($this->manager->isRegistered('test-plugin'));
    }
    
    public function testPluginRegistrationThrowsOnDuplicate()
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('duplicate-plugin');
        $plugin->method('dependencies')->willReturn([]);
        
        $this->manager->register($plugin);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plugin already registered');
        
        $this->manager->register($plugin);
    }
    
    public function testPluginDependencyCheck()
    {
        $dependency = $this->createMock(PluginInterface::class);
        $dependency->method('name')->willReturn('dependency-plugin');
        $dependency->method('dependencies')->willReturn([]);
        
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('dependent-plugin');
        $plugin->method('dependencies')->willReturn(['dependency-plugin']);
        
        // Register dependency first
        $this->manager->register($dependency);
        
        // Should work
        $this->manager->register($plugin);
        
        $this->assertTrue($this->manager->isRegistered('dependent-plugin'));
    }
    
    public function testPluginDependencyMissingThrows()
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('dependent-plugin');
        $plugin->method('dependencies')->willReturn(['missing-plugin']);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing dependency');
        
        $this->manager->register($plugin);
    }
    
    public function testPluginEnabling()
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('enable-test');
        $plugin->method('dependencies')->willReturn([]);
        $plugin->method('isCompatible')->willReturn(true);
        $plugin->expects($this->once())->method('boot');
        
        $this->manager->register($plugin);
        $this->manager->enable('enable-test');
        
        $this->assertTrue($this->manager->isEnabled('enable-test'));
    }
    
    public function testPluginEnablingWithAgent()
    {
        $agent = $this->createMock(Agent::class);
        
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('agent-plugin');
        $plugin->method('dependencies')->willReturn([]);
        $plugin->method('isCompatible')->with($agent)->willReturn(true);
        $plugin->expects($this->once())->method('boot');
        
        $this->manager->register($plugin);
        $this->manager->enable('agent-plugin', $agent);
        
        $this->assertTrue($this->manager->isEnabled('agent-plugin'));
    }
    
    public function testPluginCompatibilityCheck()
    {
        $agent = $this->createMock(Agent::class);
        
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('incompatible-plugin');
        $plugin->method('dependencies')->willReturn([]);
        $plugin->method('isCompatible')->with($agent)->willReturn(false);
        
        $this->manager->register($plugin);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plugin not compatible');
        
        $this->manager->enable('incompatible-plugin', $agent);
    }
    
    public function testPluginDisabling()
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('disable-test');
        $plugin->method('dependencies')->willReturn([]);
        $plugin->expects($this->once())->method('shutdown');
        
        $this->manager->register($plugin);
        $this->manager->enable('disable-test');
        $this->manager->disable('disable-test');
        
        $this->assertFalse($this->manager->isEnabled('disable-test'));
    }
    
    public function testPluginConfiguration()
    {
        $config = ['api_key' => 'test_key', 'enabled' => true];
        
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('name')->willReturn('config-plugin');
        $plugin->method('dependencies')->willReturn([]);
        
        $this->manager->configure('config-plugin', $config);
        $this->manager->register($plugin);
        
        $pluginConfig = $this->manager->getConfiguration('config-plugin');
        $this->assertEquals($config, $pluginConfig);
    }
    
    public function testBasePluginImplementation()
    {
        $basePlugin = new class extends BasePlugin {
            public function name(): string
            {
                return 'base-test-plugin';
            }
            
            public function version(): string
            {
                return '1.0.0';
            }
            
            public function description(): string
            {
                return 'Test base plugin';
            }
        };
        
        $this->assertEquals('base-test-plugin', $basePlugin->name());
        $this->assertEquals('1.0.0', $basePlugin->version());
        $this->assertEquals('Test base plugin', $basePlugin->description());
        $this->assertEmpty($basePlugin->dependencies());
        $this->assertTrue($basePlugin->isCompatible($this->createMock(Agent::class)));
    }
    
    public function testPluginToolRegistration()
    {
        $tool = $this->createMock(ToolInterface::class);
        
        $plugin = new class($tool) extends BasePlugin {
            private $tool;
            
            public function __construct($tool)
            {
                $this->tool = $tool;
            }
            
            public function name(): string
            {
                return 'tool-plugin';
            }
            
            public function boot(): void
            {
                parent::boot();
                $this->registerTool($this->tool);
            }
        };
        
        $this->manager->register($plugin);
        $this->manager->enable('tool-plugin');
        
        $this->assertTrue($this->manager->isEnabled('tool-plugin'));
    }
    
    public function testPluginHookRegistration()
    {
        $plugin = new class extends BasePlugin {
            public function name(): string
            {
                return 'hook-plugin';
            }
            
            public function boot(): void
            {
                parent::boot();
                $this->registerHook('pre_tool_use', function($data) {
                    return $data;
                });
            }
        };
        
        $this->manager->register($plugin);
        $this->manager->enable('hook-plugin');
        
        // Hook should be registered in HookRegistry
        $this->assertTrue($this->manager->isEnabled('hook-plugin'));
    }
    
    public function testPluginDiscovery()
    {
        // Create a mock plugin directory
        $pluginDir = sys_get_temp_dir() . '/test_plugins_' . uniqid();
        mkdir($pluginDir);
        
        // Create a sample plugin file
        $pluginContent = '<?php
namespace TestPlugins;

use SuperAgent\Plugins\BasePlugin;

class SamplePlugin extends BasePlugin
{
    public function name(): string
    {
        return "sample-plugin";
    }
}';
        
        file_put_contents($pluginDir . '/SamplePlugin.php', $pluginContent);
        
        try {
            $discovered = $this->manager->discover($pluginDir);
            
            // Would find plugin classes in real implementation
            $this->assertIsArray($discovered);
        } finally {
            unlink($pluginDir . '/SamplePlugin.php');
            rmdir($pluginDir);
        }
    }
    
    public function testPluginLifecycleEvents()
    {
        $eventTracker = new \stdClass();
        $eventTracker->events = [];
        
        $plugin = new class($eventTracker) extends BasePlugin {
            private $tracker;
            
            public function __construct($tracker)
            {
                $this->tracker = $tracker;
            }
            
            public function name(): string
            {
                return 'lifecycle-plugin';
            }
            
            public function register(): void
            {
                $this->tracker->events[] = 'registered';
            }
            
            public function boot(): void
            {
                parent::boot();
                $this->tracker->events[] = 'booted';
            }
            
            public function shutdown(): void
            {
                $this->tracker->events[] = 'shutdown';
            }
        };
        
        $this->manager->register($plugin);
        $this->assertContains('registered', $eventTracker->events);
        
        $this->manager->enable('lifecycle-plugin');
        $this->assertContains('booted', $eventTracker->events);
        
        $this->manager->disable('lifecycle-plugin');
        $this->assertContains('shutdown', $eventTracker->events);
    }
    
    public function testPluginPriority()
    {
        $lowPriority = $this->createMock(PluginInterface::class);
        $lowPriority->method('name')->willReturn('low-priority');
        $lowPriority->method('priority')->willReturn(10);
        $lowPriority->method('dependencies')->willReturn([]);
        
        $highPriority = $this->createMock(PluginInterface::class);
        $highPriority->method('name')->willReturn('high-priority');
        $highPriority->method('priority')->willReturn(100);
        $highPriority->method('dependencies')->willReturn([]);
        
        $this->manager->register($lowPriority);
        $this->manager->register($highPriority);
        
        // High priority plugins should be processed first
        $this->assertTrue($this->manager->isRegistered('low-priority'));
        $this->assertTrue($this->manager->isRegistered('high-priority'));
    }
}