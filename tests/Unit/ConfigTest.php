<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Config\ConfigWatcher;
use SuperAgent\Config\HotReload;
use Illuminate\Foundation\Application;

class ConfigTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempFile = sys_get_temp_dir() . '/test_config_' . uniqid() . '.json';
        file_put_contents($this->tempFile, json_encode(['key' => 'value']));

        // Ensure base_path() works for HotReload tests
        new Application(sys_get_temp_dir());
    }
    
    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }
    
    public function testConfigWatcherCanWatchFile()
    {
        $watcher = new ConfigWatcher();
        $callbackExecuted = false;
        
        $watcher->watch($this->tempFile, function($file) use (&$callbackExecuted) {
            $callbackExecuted = true;
        });
        
        $this->assertContains($this->tempFile, $watcher->getWatchedFiles());
    }
    
    public function testConfigWatcherThrowsExceptionForNonExistentFile()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config file not found');
        
        $watcher = new ConfigWatcher();
        $watcher->watch('/non/existent/file.json', function() {});
    }
    
    public function testConfigWatcherDetectsFileChanges()
    {
        $watcher = new ConfigWatcher();
        $changeDetected = false;

        $watcher->watch($this->tempFile, function($file) use (&$changeDetected) {
            $changeDetected = true;
        });

        // Simulate file change — touch with a future timestamp to ensure filemtime differs
        sleep(1);
        file_put_contents($this->tempFile, json_encode(['key' => 'new_value']));
        clearstatcache(true, $this->tempFile);
        touch($this->tempFile, time() + 2);
        clearstatcache(true, $this->tempFile);

        $watcher->check();

        $this->assertTrue($changeDetected);
    }
    
    public function testConfigWatcherCanStartAndStop()
    {
        $watcher = new ConfigWatcher();
        
        $this->assertFalse($watcher->isWatching());
        
        $watcher->start();
        $this->assertTrue($watcher->isWatching());
        
        $watcher->stop();
        $this->assertFalse($watcher->isWatching());
    }
    
    public function testConfigWatcherCanClear()
    {
        $watcher = new ConfigWatcher();
        $watcher->watch($this->tempFile, function() {});
        
        $this->assertNotEmpty($watcher->getWatchedFiles());
        
        $watcher->clear();
        
        $this->assertEmpty($watcher->getWatchedFiles());
        $this->assertFalse($watcher->isWatching());
    }
    
    public function testHotReloadCanWatchConfigFile()
    {
        $hotReload = new HotReload();
        
        // Mock config path
        $configFile = 'test_config.json';
        file_put_contents(sys_get_temp_dir() . '/' . $configFile, json_encode(['key' => 'value']));
        
        $callbackExecuted = false;
        $hotReload->watchConfigFile($configFile, function() use (&$callbackExecuted) {
            $callbackExecuted = true;
        });
        
        // Clean up
        unlink(sys_get_temp_dir() . '/' . $configFile);
        
        $this->assertTrue(true); // Test passes if no exception thrown
    }
    
    public function testHotReloadCanEnableForAgent()
    {
        $agent = $this->createMock(\SuperAgent\Agent::class);
        $hotReload = new HotReload();
        
        // This should not throw an exception
        $hotReload->enableForAgent($agent);
        
        $this->assertTrue(true);
    }
    
    public function testHotReloadCanStop()
    {
        $hotReload = new HotReload();
        
        // This should not throw an exception
        $hotReload->stop();
        
        $this->assertTrue(true);
    }
}