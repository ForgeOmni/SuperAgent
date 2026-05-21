<?php

declare(strict_types=1);

namespace SuperAgent\Format;

/**
 * Catalog of built-in formatters. Lifted from opencode's `format/formatter.ts`
 * with 26 entries spanning Go, JS/TS, Python, Ruby, Rust, PHP, Dart, Zig, C/C++,
 * Kotlin, Haskell, Clojure, D, Gleam, OCaml, Terraform, LaTeX, Nix, Shell, R,
 * HTML/ERB, and CSS/HTML/JSON/YAML via Prettier or Biome.
 *
 * Each entry is a {@see FormatterInfo} whose probe inspects the project to
 * decide whether the formatter is enabled (e.g. Prettier only fires when
 * `package.json` declares it as a (dev)dependency; Pint only fires when
 * `composer.json` lists `laravel/pint`). Probes return either a command
 * `[bin, ...args]` with `$FILE` placeholder, or `false`.
 *
 * Add a new formatter via `register(FormatterInfo)`; remove with `unregister(name)`.
 * Discovery is by extension: `forExtension('.php')` returns every probe whose
 * `extensions` list contains the dotted suffix.
 */
final class Formatters
{
    /** @var array<string, FormatterInfo> name → info */
    private static array $registry = [];

    private static bool $defaultsLoaded = false;

    public static function register(FormatterInfo $info): void
    {
        self::$registry[$info->name] = $info;
    }

    public static function unregister(string $name): void
    {
        unset(self::$registry[$name]);
    }

    /**
     * @return array<string, FormatterInfo>
     */
    public static function all(): array
    {
        self::ensureDefaults();
        return self::$registry;
    }

    /**
     * Return every formatter whose `extensions` list contains $ext.
     * $ext should include the leading dot and be lowercase (e.g. `.php`, `.tsx`).
     *
     * @return array<int, FormatterInfo>
     */
    public static function forExtension(string $ext): array
    {
        self::ensureDefaults();
        $ext = strtolower($ext);
        $out = [];
        foreach (self::$registry as $info) {
            if (in_array($ext, $info->extensions, true)) {
                $out[] = $info;
            }
        }
        return $out;
    }

    /**
     * Drop all registered formatters and reset to "no defaults loaded" state.
     * Test helper.
     */
    public static function reset(): void
    {
        self::$registry = [];
        self::$defaultsLoaded = false;
    }

    private static function ensureDefaults(): void
    {
        if (self::$defaultsLoaded) {
            return;
        }
        self::$defaultsLoaded = true;
        foreach (self::defaults() as $info) {
            self::$registry[$info->name] = $info;
        }
    }

    /** @return array<int, FormatterInfo> */
    private static function defaults(): array
    {
        $which = static fn (string $bin): ?string => self::which($bin);
        $findUp = static fn (string $name, FormatterContext $c): array => self::findUp($name, $c->directory, $c->worktree);

        return [
            // Single-binary formatters: probe via `which`.
            new FormatterInfo('gofmt', ['.go'], static function (FormatterContext $c) use ($which) {
                $b = $which('gofmt');
                return $b ? [$b, '-w', '$FILE'] : false;
            }),
            new FormatterInfo('mix', ['.ex', '.exs', '.eex', '.heex', '.leex', '.neex', '.sface'], static function (FormatterContext $c) use ($which) {
                $b = $which('mix');
                return $b ? [$b, 'format', '$FILE'] : false;
            }),
            new FormatterInfo('zig', ['.zig', '.zon'], static function (FormatterContext $c) use ($which) {
                $b = $which('zig');
                return $b ? [$b, 'fmt', '$FILE'] : false;
            }),
            new FormatterInfo('ktlint', ['.kt', '.kts'], static function (FormatterContext $c) use ($which) {
                $b = $which('ktlint');
                return $b ? [$b, '-F', '$FILE'] : false;
            }),
            new FormatterInfo('rubocop', ['.rb', '.rake', '.gemspec', '.ru'], static function (FormatterContext $c) use ($which) {
                $b = $which('rubocop');
                return $b ? [$b, '--autocorrect', '$FILE'] : false;
            }),
            new FormatterInfo('standardrb', ['.rb', '.rake', '.gemspec', '.ru'], static function (FormatterContext $c) use ($which) {
                $b = $which('standardrb');
                return $b ? [$b, '--fix', '$FILE'] : false;
            }),
            new FormatterInfo('htmlbeautifier', ['.erb', '.html.erb'], static function (FormatterContext $c) use ($which) {
                $b = $which('htmlbeautifier');
                return $b ? [$b, '$FILE'] : false;
            }),
            new FormatterInfo('dart', ['.dart'], static function (FormatterContext $c) use ($which) {
                $b = $which('dart');
                return $b ? [$b, 'format', '$FILE'] : false;
            }),
            new FormatterInfo('terraform', ['.tf', '.tfvars'], static function (FormatterContext $c) use ($which) {
                $b = $which('terraform');
                return $b ? [$b, 'fmt', '$FILE'] : false;
            }),
            new FormatterInfo('latexindent', ['.tex'], static function (FormatterContext $c) use ($which) {
                $b = $which('latexindent');
                return $b ? [$b, '-w', '-s', '$FILE'] : false;
            }),
            new FormatterInfo('gleam', ['.gleam'], static function (FormatterContext $c) use ($which) {
                $b = $which('gleam');
                return $b ? [$b, 'format', '$FILE'] : false;
            }),
            new FormatterInfo('shfmt', ['.sh', '.bash'], static function (FormatterContext $c) use ($which) {
                $b = $which('shfmt');
                return $b ? [$b, '-w', '$FILE'] : false;
            }),
            new FormatterInfo('nixfmt', ['.nix'], static function (FormatterContext $c) use ($which) {
                $b = $which('nixfmt');
                return $b ? [$b, '$FILE'] : false;
            }),
            new FormatterInfo('rustfmt', ['.rs'], static function (FormatterContext $c) use ($which) {
                $b = $which('rustfmt');
                return $b ? [$b, '$FILE'] : false;
            }),
            new FormatterInfo('ormolu', ['.hs'], static function (FormatterContext $c) use ($which) {
                $b = $which('ormolu');
                return $b ? [$b, '-i', '$FILE'] : false;
            }),
            new FormatterInfo('cljfmt', ['.clj', '.cljs', '.cljc', '.edn'], static function (FormatterContext $c) use ($which) {
                $b = $which('cljfmt');
                return $b ? [$b, 'fix', '--quiet', '$FILE'] : false;
            }),
            new FormatterInfo('dfmt', ['.d'], static function (FormatterContext $c) use ($which) {
                $b = $which('dfmt');
                return $b ? [$b, '-i', '$FILE'] : false;
            }),

            // clang-format requires a project-local .clang-format to avoid surprise rewrites.
            new FormatterInfo('clang-format', ['.c', '.cc', '.cpp', '.cxx', '.c++', '.h', '.hh', '.hpp', '.hxx', '.h++', '.ino'], static function (FormatterContext $c) use ($which, $findUp) {
                if ($findUp('.clang-format', $c) === []) {
                    return false;
                }
                $b = $which('clang-format');
                return $b ? [$b, '-i', '$FILE'] : false;
            }),

            // ocamlformat requires .ocamlformat
            new FormatterInfo('ocamlformat', ['.ml', '.mli'], static function (FormatterContext $c) use ($which, $findUp) {
                if (! $which('ocamlformat') || $findUp('.ocamlformat', $c) === []) {
                    return false;
                }
                return ['ocamlformat', '-i', '$FILE'];
            }),

            // Python: prefer ruff if the project opts in via pyproject.toml/ruff.toml.
            new FormatterInfo('ruff', ['.py', '.pyi'], static function (FormatterContext $c) use ($which, $findUp) {
                if (! $which('ruff')) {
                    return false;
                }
                foreach (['pyproject.toml', 'ruff.toml', '.ruff.toml'] as $cfg) {
                    $found = $findUp($cfg, $c);
                    if ($found === []) {
                        continue;
                    }
                    if ($cfg === 'pyproject.toml') {
                        $content = @file_get_contents($found[0]);
                        if ($content !== false && str_contains($content, '[tool.ruff]')) {
                            return ['ruff', 'format', '$FILE'];
                        }
                    } else {
                        return ['ruff', 'format', '$FILE'];
                    }
                }
                return false;
            }),

            // Prettier: project-declared in package.json
            new FormatterInfo('prettier', [
                '.js', '.jsx', '.mjs', '.cjs',
                '.ts', '.tsx', '.mts', '.cts',
                '.html', '.htm', '.css', '.scss', '.sass', '.less',
                '.vue', '.svelte',
                '.json', '.jsonc', '.yaml', '.yml', '.toml', '.xml',
                '.md', '.mdx', '.graphql', '.gql',
            ], static function (FormatterContext $c) use ($which, $findUp) {
                foreach ($findUp('package.json', $c) as $pkg) {
                    $json = self::readJson($pkg);
                    if (! is_array($json)) {
                        continue;
                    }
                    $dev = $json['devDependencies'] ?? [];
                    $deps = $json['dependencies'] ?? [];
                    if ((is_array($dev) && isset($dev['prettier']))
                        || (is_array($deps) && isset($deps['prettier']))
                    ) {
                        // Prefer a local node_modules/.bin/prettier over a global one.
                        $local = dirname($pkg) . '/node_modules/.bin/prettier';
                        if (is_file($local) && is_executable($local)) {
                            return [$local, '--write', '$FILE'];
                        }
                        $b = $which('prettier');
                        if ($b) {
                            return [$b, '--write', '$FILE'];
                        }
                    }
                }
                return false;
            }, ['BUN_BE_BUN' => '1']),

            // Biome: project-declared via biome.json
            new FormatterInfo('biome', [
                '.js', '.jsx', '.mjs', '.cjs',
                '.ts', '.tsx', '.mts', '.cts',
                '.json', '.jsonc',
            ], static function (FormatterContext $c) use ($which, $findUp) {
                foreach (['biome.json', 'biome.jsonc'] as $cfg) {
                    if ($findUp($cfg, $c) === []) {
                        continue;
                    }
                    $b = $which('biome') ?? $which('@biomejs/biome');
                    if ($b) {
                        return [$b, 'format', '--write', '$FILE'];
                    }
                }
                return false;
            }, ['BUN_BE_BUN' => '1']),

            // Pint: PHP — composer.json must list laravel/pint
            new FormatterInfo('pint', ['.php'], static function (FormatterContext $c) use ($findUp) {
                foreach ($findUp('composer.json', $c) as $cf) {
                    $json = self::readJson($cf);
                    if (! is_array($json)) {
                        continue;
                    }
                    $req = $json['require'] ?? [];
                    $reqDev = $json['require-dev'] ?? [];
                    $hasPint = (is_array($req) && isset($req['laravel/pint']))
                        || (is_array($reqDev) && isset($reqDev['laravel/pint']));
                    if ($hasPint) {
                        $vendor = dirname($cf) . '/vendor/bin/pint';
                        if (is_file($vendor) && is_executable($vendor)) {
                            return [$vendor, '$FILE'];
                        }
                    }
                }
                return false;
            }),
        ];
    }

    /** Lookup a binary on PATH; returns absolute path or null. */
    private static function which(string $bin): ?string
    {
        if ($bin === '' || str_contains($bin, '/')) {
            return is_file($bin) && is_executable($bin) ? $bin : null;
        }
        $paths = explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: ''));
        foreach ($paths as $p) {
            if ($p === '') {
                continue;
            }
            $cand = rtrim($p, '/') . '/' . $bin;
            if (is_file($cand) && is_executable($cand)) {
                return $cand;
            }
        }
        return null;
    }

    /**
     * Walk up from $start to $stop looking for files named $name. Returns the
     * absolute paths in order (nearest first).
     *
     * @return array<int, string>
     */
    private static function findUp(string $name, string $start, string $stop): array
    {
        $found = [];
        $start = rtrim($start, '/');
        $stop = rtrim($stop, '/');
        $dir = $start;
        while ($dir !== '') {
            $cand = $dir . '/' . $name;
            if (is_file($cand)) {
                $found[] = $cand;
            }
            if ($dir === $stop || $dir === '/' || $dir === dirname($dir)) {
                break;
            }
            $dir = dirname($dir);
        }
        return $found;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }
}
