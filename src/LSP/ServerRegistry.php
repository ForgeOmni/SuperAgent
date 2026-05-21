<?php

declare(strict_types=1);

namespace SuperAgent\LSP;

/**
 * Catalog of built-in language servers. Each entry knows how to:
 *   1. Find the project root for a given file (root marker discovery).
 *   2. Spawn the language server subprocess (returns false when not installed).
 *
 * The defaults cover the most common languages a coding agent encounters:
 * PHP (intelephense + phpactor), TypeScript (typescript-language-server),
 * Go (gopls), Rust (rust-analyzer), Python (pyright), C/C++ (clangd), and
 * Bash (bash-language-server). Add more via {@see register()}.
 */
final class ServerRegistry
{
    /** @var array<string, ServerInfo> */
    private static array $servers = [];
    private static bool $defaultsLoaded = false;

    public static function register(ServerInfo $info): void
    {
        self::$servers[$info->id] = $info;
    }

    public static function unregister(string $id): void
    {
        unset(self::$servers[$id]);
    }

    /**
     * @return array<string, ServerInfo>
     */
    public static function all(): array
    {
        self::ensureDefaults();
        return self::$servers;
    }

    public static function get(string $id): ?ServerInfo
    {
        self::ensureDefaults();
        return self::$servers[$id] ?? null;
    }

    /**
     * Return every server that handles the given file extension. Multiple
     * servers may match (e.g. PHP can use intelephense or phpactor); callers
     * typically pick the first one whose `spawn` resolves successfully.
     *
     * @return array<int, ServerInfo>
     */
    public static function forExtension(string $ext): array
    {
        self::ensureDefaults();
        $ext = strtolower($ext);
        $out = [];
        foreach (self::$servers as $info) {
            if (in_array($ext, $info->extensions, true)) {
                $out[] = $info;
            }
        }
        return $out;
    }

    public static function reset(): void
    {
        self::$servers = [];
        self::$defaultsLoaded = false;
    }

    private static function ensureDefaults(): void
    {
        if (self::$defaultsLoaded) {
            return;
        }
        self::$defaultsLoaded = true;
        foreach (self::defaults() as $s) {
            self::$servers[$s->id] = $s;
        }
    }

    /** @return array<int, ServerInfo> */
    private static function defaults(): array
    {
        $nearest = static function (array $markers, string $start, string $stop): ?string {
            $start = rtrim($start, '/');
            $stop = rtrim($stop, '/');
            $dir = $start;
            while ($dir !== '' && $dir !== '/') {
                foreach ($markers as $m) {
                    if (is_file($dir . '/' . $m) || is_dir($dir . '/' . $m)) {
                        return $dir;
                    }
                }
                if ($dir === $stop || $dir === dirname($dir)) {
                    break;
                }
                $dir = dirname($dir);
            }
            return $stop !== '' ? $stop : null;
        };

        $which = static function (string $bin): ?string {
            if ($bin === '' || str_contains($bin, '/')) {
                return is_file($bin) && is_executable($bin) ? $bin : null;
            }
            foreach (explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: '')) as $p) {
                if ($p === '') {
                    continue;
                }
                $cand = rtrim($p, '/') . '/' . $bin;
                if (is_file($cand) && is_executable($cand)) {
                    return $cand;
                }
            }
            return null;
        };

        return [
            new ServerInfo(
                id: 'phpactor',
                extensions: ['.php'],
                rootFinder: fn (string $f, string $w) => $nearest(['composer.json', '.git'], dirname($f), $w),
                spawn: function (string $root) use ($which) {
                    $b = $which('phpactor');
                    return $b ? [$b, 'language-server'] : false;
                },
            ),
            new ServerInfo(
                id: 'intelephense',
                extensions: ['.php'],
                rootFinder: fn (string $f, string $w) => $nearest(['composer.json', '.git'], dirname($f), $w),
                spawn: function (string $root) use ($which) {
                    $b = $which('intelephense');
                    return $b ? [$b, '--stdio'] : false;
                },
            ),
            new ServerInfo(
                id: 'gopls',
                extensions: ['.go'],
                rootFinder: fn (string $f, string $w) => $nearest(['go.mod', 'go.work', '.git'], dirname($f), $w),
                spawn: function (string $root) use ($which) {
                    $b = $which('gopls');
                    return $b ? [$b, 'serve'] : false;
                },
            ),
            new ServerInfo(
                id: 'rust-analyzer',
                extensions: ['.rs'],
                rootFinder: fn (string $f, string $w) => $nearest(['Cargo.toml', '.git'], dirname($f), $w),
                spawn: function (string $root) use ($which) {
                    $b = $which('rust-analyzer');
                    return $b ? [$b] : false;
                },
            ),
            new ServerInfo(
                id: 'pyright',
                extensions: ['.py', '.pyi'],
                rootFinder: fn (string $f, string $w) => $nearest(['pyproject.toml', 'setup.py', 'requirements.txt', '.git'], dirname($f), $w),
                spawn: function (string $root) use ($which) {
                    $b = $which('pyright-langserver');
                    return $b ? [$b, '--stdio'] : false;
                },
            ),
            new ServerInfo(
                id: 'typescript-language-server',
                extensions: ['.ts', '.tsx', '.js', '.jsx', '.mjs', '.cjs', '.mts', '.cts'],
                rootFinder: fn (string $f, string $w) => $nearest(['package.json', 'tsconfig.json', '.git'], dirname($f), $w),
                spawn: function (string $root) use ($which) {
                    $b = $which('typescript-language-server');
                    return $b ? [$b, '--stdio'] : false;
                },
            ),
            new ServerInfo(
                id: 'clangd',
                extensions: ['.c', '.h', '.cpp', '.cxx', '.cc', '.hpp'],
                rootFinder: fn (string $f, string $w) => $nearest(['compile_commands.json', 'CMakeLists.txt', '.git'], dirname($f), $w),
                spawn: function (string $root) use ($which) {
                    $b = $which('clangd');
                    return $b ? [$b, '--background-index'] : false;
                },
            ),
            new ServerInfo(
                id: 'bash-language-server',
                extensions: ['.sh', '.bash'],
                rootFinder: fn (string $f, string $w) => $w,
                spawn: function (string $root) use ($which) {
                    $b = $which('bash-language-server');
                    return $b ? [$b, 'start'] : false;
                },
            ),
            new ServerInfo(
                id: 'zls',
                extensions: ['.zig', '.zon'],
                rootFinder: fn (string $f, string $w) => $nearest(['build.zig', '.git'], dirname($f), $w),
                spawn: function (string $root) use ($which) {
                    $b = $which('zls');
                    return $b ? [$b] : false;
                },
            ),
        ];
    }
}
