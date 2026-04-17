<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Plugins\PluginManager;
use SuperAgent\Plugins\BasePlugin;
use SuperAgent\Skills\SkillManager;
use SuperAgent\Skills\Skill;
use SuperAgent\Config\ConfigWatcher;
use SuperAgent\Config\HotReload;
use SuperAgent\Agent;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;

class Phase12Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Boot a minimal Laravel Application so config() and base_path() work
        $app = Application::getInstance();
        if (!$app->bound('config')) {
            $app->singleton('config', function () {
                return new Repository([]);
            });
        }

        // Reset singletons
        PluginManager::reset();
        SkillManager::reset();
    }

    /**
     * Test Plugin System
     */
    public function test_plugin_registration_and_lifecycle(): void
    {
        $manager = PluginManager::getInstance();
        
        // Create a test plugin
        $plugin = new class extends BasePlugin {
            public $bootCalled = false;
            public $enableCalled = false;
            public $disableCalled = false;
            
            public function name(): string {
                return 'test_plugin';
            }
            
            public function description(): string {
                return 'Test plugin';
            }
            
            public function boot(): void {
                $this->bootCalled = true;
            }
            
            public function enable(): void {
                $this->enableCalled = true;
            }
            
            public function disable(): void {
                $this->disableCalled = true;
            }
        };
        
        // Register plugin
        $manager->register($plugin);
        $this->assertNotNull($manager->get('test_plugin'));
        
        // Enable plugin
        $manager->enable('test_plugin');
        $this->assertTrue($plugin->bootCalled);
        $this->assertTrue($plugin->enableCalled);
        $this->assertTrue($manager->isEnabled('test_plugin'));
        
        // Disable plugin
        $manager->disable('test_plugin');
        $this->assertTrue($plugin->disableCalled);
        $this->assertFalse($manager->isEnabled('test_plugin'));
    }

    public function test_plugin_dependencies(): void
    {
        $manager = PluginManager::getInstance();
        
        // Create base plugin
        $basePlugin = new class extends BasePlugin {
            public function name(): string { return 'base_plugin'; }
            public function description(): string { return 'Base plugin'; }
        };
        
        // Create dependent plugin
        $dependentPlugin = new class extends BasePlugin {
            public function name(): string { return 'dependent_plugin'; }
            public function description(): string { return 'Dependent plugin'; }
            public function dependencies(): array { return ['base_plugin']; }
        };
        
        // Register base plugin first
        $manager->register($basePlugin);
        
        // Register dependent plugin
        $manager->register($dependentPlugin);
        
        // Enable dependent (should auto-enable base)
        $manager->enable('dependent_plugin');
        $this->assertTrue($manager->isEnabled('base_plugin'));
        $this->assertTrue($manager->isEnabled('dependent_plugin'));
        
        // Cannot disable base while dependent is enabled
        $this->expectException(\RuntimeException::class);
        $manager->disable('base_plugin');
    }

    public function test_plugin_configuration(): void
    {
        $manager = PluginManager::getInstance();
        
        $plugin = new class extends BasePlugin {
            public function name(): string { return 'config_plugin'; }
            public function description(): string { return 'Config plugin'; }
            
            public function configSchema(): array {
                return [
                    'type' => 'object',
                    'properties' => [
                        'api_key' => ['type' => 'string'],
                        'timeout' => ['type' => 'integer'],
                    ],
                ];
            }
        };
        
        $manager->register($plugin);
        
        // Configure plugin
        $config = [
            'api_key' => 'test_key',
            'timeout' => 30,
        ];
        $manager->configure('config_plugin', $config);
        
        $this->assertEquals($config, $plugin->getConfig());
    }

    /**
     * Test Skill System
     */
    public function test_skill_registration_and_execution(): void
    {
        $manager = SkillManager::getInstance();
        
        // Create test skill
        $skill = new class extends Skill {
            public function name(): string { return 'test_skill'; }
            public function description(): string { return 'Test skill'; }
            public function template(): string { return 'Hello {{name}}, you are {{age}} years old!'; }
            
            public function parameters(): array {
                return [
                    ['name' => 'name', 'type' => 'string', 'required' => true],
                    ['name' => 'age', 'type' => 'integer', 'required' => true],
                ];
            }
        };
        
        // Register skill
        $manager->register($skill);
        $this->assertNotNull($manager->get('test_skill'));
        
        // Execute skill
        $result = $manager->execute('test_skill', [
            'name' => 'John',
            'age' => 30,
        ]);
        
        $this->assertEquals('Hello John, you are 30 years old!', $result);
    }

    public function test_skill_validation(): void
    {
        $manager = SkillManager::getInstance();
        
        $skill = new class extends Skill {
            public function name(): string { return 'validated_skill'; }
            public function description(): string { return 'Validated skill'; }
            public function template(): string { return 'Value: {{value}}'; }
            
            public function parameters(): array {
                return [
                    ['name' => 'value', 'type' => 'string', 'required' => true],
                ];
            }
        };
        
        $manager->register($skill);
        
        // Valid execution
        $result = $manager->execute('validated_skill', ['value' => 'test']);
        $this->assertEquals('Value: test', $result);
        
        // Invalid execution (missing required parameter)
        $this->expectException(\InvalidArgumentException::class);
        $manager->execute('validated_skill', []);
    }

    public function test_skill_command_parsing(): void
    {
        $manager = SkillManager::getInstance();
        
        $skill = new class extends Skill {
            public function name(): string { return 'parse_skill'; }
            public function description(): string { return 'Parse skill'; }
            public function template(): string { return 'File: {{file}}, Type: {{type}}'; }
        };
        
        $manager->register($skill);
        
        // Parse and execute command
        $result = $manager->parseAndExecute('/parse_skill file=test.php type=integration');
        $this->assertEquals('File: test.php, Type: integration', $result);
        
        // Non-skill command returns null
        $result = $manager->parseAndExecute('regular message');
        $this->assertNull($result);
    }

    public function test_skill_aliases(): void
    {
        $manager = SkillManager::getInstance();
        
        $skill = new class extends Skill {
            public function name(): string { return 'original_skill'; }
            public function description(): string { return 'Original skill'; }
            public function template(): string { return 'Original'; }
        };
        
        $manager->register($skill);
        $manager->alias('alias_skill', 'original_skill');
        
        // Get by alias
        $aliasedSkill = $manager->get('alias_skill');
        $this->assertNotNull($aliasedSkill);
        $this->assertEquals('original_skill', $aliasedSkill->name());
    }

    /**
     * Test Configuration Hot Reload
     */
    public function test_config_watcher_basic(): void
    {
        $watcher = new ConfigWatcher();
        
        // Create temp file
        $tempFile = sys_get_temp_dir() . '/test_config.php';
        file_put_contents($tempFile, '<?php return ["test" => "value"];');
        
        $callbackCalled = false;
        $watcher->watch($tempFile, function() use (&$callbackCalled) {
            $callbackCalled = true;
        });
        
        // Modify file with a future timestamp to guarantee change detection
        sleep(1); // Ensure different timestamp
        file_put_contents($tempFile, '<?php return ["test" => "new_value"];');
        touch($tempFile, time() + 10);
        clearstatcache();

        // Check for changes
        $watcher->check();
        $this->assertTrue($callbackCalled);
        
        // Cleanup
        unlink($tempFile);
    }

    public function test_config_watcher_multiple_files(): void
    {
        $watcher = new ConfigWatcher();
        
        // Create temp files
        $file1 = sys_get_temp_dir() . '/config1.php';
        $file2 = sys_get_temp_dir() . '/config2.php';
        
        file_put_contents($file1, '<?php return ["key1" => "value1"];');
        file_put_contents($file2, '<?php return ["key2" => "value2"];');
        
        $callbacks = ['file1' => false, 'file2' => false];
        
        $watcher->watch($file1, function() use (&$callbacks) {
            $callbacks['file1'] = true;
        });
        
        $watcher->watch($file2, function() use (&$callbacks) {
            $callbacks['file2'] = true;
        });
        
        // Modify first file
        sleep(1);
        file_put_contents($file1, '<?php return ["key1" => "new_value1"];');
        
        $watcher->check();
        $this->assertTrue($callbacks['file1']);
        $this->assertFalse($callbacks['file2']);
        
        // Cleanup
        unlink($file1);
        unlink($file2);
    }

    public function test_hot_reload_config_resolution(): void
    {
        $hotReload = new HotReload();

        // watchConfigFile for a non-existent config silently returns
        // (resolveConfigPath returns null when file doesn't exist)
        $hotReload->watchConfigFile('nonexistent_config.php');

        $this->assertTrue(true); // If we get here without errors, test passes
    }

    /**
     * Test Built-in Skills
     */
    public function test_builtin_refactor_skill(): void
    {
        $manager = SkillManager::getInstance();
        $skill = $manager->get('refactor');
        
        $this->assertNotNull($skill);
        $this->assertEquals('refactor', $skill->name());
        $this->assertEquals('development', $skill->category());
        
        // Test template generation (must supply all placeholders referenced in the template)
        $result = $skill->execute([
            'file' => 'test.php',
            'aspect' => 'readability',
            'pattern' => 'Repository',
            'performance' => 'query optimization',
        ]);

        $this->assertStringContainsString('refactor the code in test.php', $result);
        $this->assertStringContainsString('Improve readability', $result);
    }

    public function test_builtin_test_skill(): void
    {
        $manager = SkillManager::getInstance();
        $skill = $manager->get('test');
        
        $this->assertNotNull($skill);
        $this->assertEquals('test', $skill->name());
        $this->assertEquals('development', $skill->category());
        
        // Test with all parameters
        $result = $skill->execute([
            'target' => 'UserService.php',
            'type' => 'integration',
            'coverage' => 80,
            'framework' => 'PHPUnit',
        ]);
        
        $this->assertStringContainsString('integration tests for UserService.php', $result);
        $this->assertStringContainsString('80% code coverage', $result);
        $this->assertStringContainsString('PHPUnit', $result);
    }

    public function test_builtin_document_skill(): void
    {
        $manager = SkillManager::getInstance();
        $skill = $manager->get('document');
        
        $this->assertNotNull($skill);
        $this->assertEquals('document', $skill->name());
        $this->assertEquals('documentation', $skill->category());
        
        $result = $skill->execute([
            'target' => 'Agent.php',
            'style' => 'API',
            'format' => 'markdown',
            'audience' => 'developers',
        ]);

        $this->assertStringContainsString('API documentation for Agent.php', $result);
        $this->assertStringContainsString('markdown format', $result);
    }

    public function test_builtin_review_skill(): void
    {
        $manager = SkillManager::getInstance();
        $skill = $manager->get('review');
        
        $this->assertNotNull($skill);
        $this->assertEquals('review', $skill->name());
        $this->assertEquals('quality', $skill->category());
        
        $result = $skill->execute([
            'target' => 'SecurityService.php',
            'type' => 'security',
            'security' => true,
            'performance' => true,
            'standards' => 'PSR-12',
        ]);

        $this->assertStringContainsString('security code review', $result);
        $this->assertStringContainsString('Security vulnerabilities', $result);
    }

    public function test_builtin_debug_skill(): void
    {
        $manager = SkillManager::getInstance();
        $skill = $manager->get('debug');
        
        $this->assertNotNull($skill);
        $this->assertEquals('debug', $skill->name());
        $this->assertEquals('troubleshooting', $skill->category());
        
        $result = $skill->execute([
            'issue' => 'Undefined variable $user',
            'file' => 'UserController.php',
            'stack' => 'at UserController.php:42',
            'context' => 'Occurs when accessing /profile',
        ]);

        $this->assertStringContainsString('Undefined variable $user', $result);
        $this->assertStringContainsString('UserController.php', $result);
        $this->assertStringContainsString('accessing /profile', $result);
    }
}