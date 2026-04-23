<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\DeviceIdentity;

class DeviceIdentityTest extends TestCase
{
    private string $tmpHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpHome = sys_get_temp_dir() . '/superagent-device-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome, 0755, true);
        putenv('HOME=' . $this->tmpHome);
        DeviceIdentity::reset();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpHome);
        putenv('HOME');
        DeviceIdentity::reset();
        parent::tearDown();
    }

    public function test_info_fields_are_populated(): void
    {
        $i = DeviceIdentity::info();
        foreach (['device_id', 'platform', 'os_version', 'device_name', 'device_model', 'version'] as $k) {
            $this->assertArrayHasKey($k, $i, "missing field: {$k}");
            $this->assertNotSame('', $i[$k], "empty field: {$k}");
        }
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $i['device_id'],
            'device_id must be a valid UUIDv4',
        );
    }

    public function test_device_id_is_stable_across_resets(): void
    {
        $first = DeviceIdentity::info();
        DeviceIdentity::reset();   // clear in-memory cache, not the file
        $second = DeviceIdentity::info();
        $this->assertSame($first['device_id'], $second['device_id']);
    }

    public function test_device_id_is_persisted_to_disk(): void
    {
        DeviceIdentity::info();
        $this->assertFileExists(DeviceIdentity::path());
        $decoded = json_decode((string) file_get_contents(DeviceIdentity::path()), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('device_id', $decoded);
    }

    public function test_kimi_headers_carry_the_six_msh_fields(): void
    {
        $h = DeviceIdentity::kimiHeaders();
        $expected = [
            'X-Msh-Platform', 'X-Msh-Version', 'X-Msh-Device-Id',
            'X-Msh-Device-Name', 'X-Msh-Device-Model', 'X-Msh-Os-Version',
        ];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $h, "missing header: {$key}");
            $this->assertIsString($h[$key]);
            $this->assertNotSame('', $h[$key], "empty header: {$key}");
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
