<?php

declare(strict_types=1);

namespace SuperAgent\Auth;

/**
 * Stable per-install device identifier + rough system metadata.
 *
 * Used by providers that require device-identification headers
 * (Moonshot / Kimi Code sends `X-Msh-Platform`, `X-Msh-Version`,
 * `X-Msh-Device-Id`, `X-Msh-Device-Name`, `X-Msh-Device-Model`,
 * `X-Msh-Os-Version` — see `packages/kosong/.../chat_provider/kimi.py` in
 * kimi-cli for the exact list). Their backend uses these for abuse
 * prevention and per-install rate limiting; we were being silently
 * deprioritized by sending none.
 *
 * Design:
 *   - The UUID is generated once and persisted to
 *     `~/.superagent/device.json` so the same machine keeps the same id
 *     across invocations and upgrades.
 *   - System info (OS family, version, hostname) is read live from PHP
 *     builtins — we don't cache it because the cost is negligible and
 *     re-reading handles hostname / OS-upgrade changes transparently.
 *   - Everything returned by `headers()` is a pre-formatted string
 *     suitable for an HTTP header value; callers don't have to sanitize.
 */
class DeviceIdentity
{
    private static ?array $cached = null;

    /**
     * @return array{device_id:string, platform:string, os_version:string, device_name:string, device_model:string, version:string}
     */
    public static function info(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $deviceId = self::loadOrCreateDeviceId();
        $platform = self::platform();

        self::$cached = [
            'device_id'    => $deviceId,
            'platform'     => $platform,
            'os_version'   => (string) php_uname('r'),
            'device_name'  => (string) gethostname() ?: 'unknown',
            'device_model' => self::deviceModel($platform),
            'version'      => self::agentVersion(),
        ];
        return self::$cached;
    }

    /**
     * Moonshot's `X-Msh-*` header family. Safe to call for any Kimi
     * region — the headers are informational, not auth. We emit them
     * for every Kimi request so the Moonshot backend gets consistent
     * per-install metadata regardless of which endpoint we hit.
     *
     * @return array<string, string>
     */
    public static function kimiHeaders(): array
    {
        $i = self::info();
        return [
            'X-Msh-Platform'    => $i['platform'],
            'X-Msh-Version'     => $i['version'],
            'X-Msh-Device-Id'   => $i['device_id'],
            'X-Msh-Device-Name' => $i['device_name'],
            'X-Msh-Device-Model'=> $i['device_model'],
            'X-Msh-Os-Version'  => $i['os_version'],
        ];
    }

    /**
     * Reset cached info. Test hook.
     */
    public static function reset(): void
    {
        self::$cached = null;
    }

    public static function path(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim($home, '/\\') . '/.superagent/device.json';
    }

    // ── Internal ───────────────────────────────────────────────

    private static function loadOrCreateDeviceId(): string
    {
        $path = self::path();
        if (is_readable($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded['device_id']) && is_string($decoded['device_id'])) {
                    return $decoded['device_id'];
                }
            }
        }

        $deviceId = self::generateUuidV4();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = json_encode(
            ['device_id' => $deviceId, 'created_at' => gmdate('c')],
            JSON_PRETTY_PRINT,
        );
        if ($payload !== false) {
            $tmp = $path . '.tmp';
            if (@file_put_contents($tmp, $payload) !== false) {
                @rename($tmp, $path);
                @chmod($path, 0644);
            }
        }
        return $deviceId;
    }

    private static function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin'  => 'macos',
            'Linux'   => 'linux',
            'Windows' => 'windows',
            'BSD'     => 'bsd',
            default   => strtolower((string) PHP_OS_FAMILY),
        };
    }

    private static function deviceModel(string $platform): string
    {
        // On macOS we can read the actual hw.model via sysctl; elsewhere
        // we fall back to the kernel arch (x86_64 / arm64 / aarch64).
        if ($platform === 'macos' && is_executable('/usr/sbin/sysctl')) {
            $out = @shell_exec('/usr/sbin/sysctl -n hw.model 2>/dev/null');
            if (is_string($out) && ($trim = trim($out)) !== '') {
                return $trim;
            }
        }
        return (string) php_uname('m');
    }

    private static function agentVersion(): string
    {
        // Read from composer.json — cheap and keeps the header honest
        // without us having to hand-maintain a VERSION constant.
        $composer = dirname(__DIR__, 2) . '/composer.json';
        if (is_readable($composer)) {
            $json = @json_decode((string) file_get_contents($composer), true);
            if (is_array($json) && !empty($json['version']) && is_string($json['version'])) {
                return (string) $json['version'];
            }
        }
        return 'dev';
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4),
        );
    }
}
