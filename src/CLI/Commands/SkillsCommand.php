<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Skills\SkillManager;
use SuperAgent\Support\MarkdownFrontmatter;

/**
 * `superagent skills` — manage user-level Skills.
 *
 * Subcommands:
 *   list                            Print built-in + user skills.
 *   show <name>                     Print a skill's body + metadata.
 *   install <source> [--name <n>]   Copy a skill file (.md / .php) into
 *                                   ~/.superagent/skills/. Validates the
 *                                   frontmatter on markdown sources so
 *                                   broken files don't land silently.
 *   remove <name>                   Delete a markdown skill by its `name`
 *                                   frontmatter from the user skills dir.
 *                                   (PHP skill files are left alone —
 *                                   they're checked in by convention.)
 *   path                            Print the user skills directory path.
 *
 * Claude-Code skill sources (`.claude/skills`, `.claude/commands`) remain
 * read-only from SuperAgent's side — edit them in their own home.
 */
class SkillsCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['skills_args'] ?? [];
        $sub = strtolower((string) ($args[0] ?? 'list'));
        $rest = array_slice($args, 1);

        return match ($sub) {
            'list'    => $this->list($renderer),
            'show'    => $this->show($renderer, $rest),
            'install' => $this->install($renderer, $rest),
            'remove'  => $this->remove($renderer, $rest),
            'path'    => $this->path($renderer),
            default   => $this->usage($renderer, $sub),
        };
    }

    private function list(Renderer $renderer): int
    {
        $manager = new SkillManager();
        // Re-apply disk loaders since PHPUnit-style gating in ctor may skip
        // them when the env var is set. `loadUserDir` is always safe to run.
        $manager->loadUserDir();
        $manager->loadProjectDir();

        $all = $manager->getAll();
        if ($all === []) {
            $renderer->info('No skills registered.');
            return 0;
        }

        $byCat = [];
        foreach ($all as $name => $skill) {
            $byCat[$skill->category()][] = [$name, $skill->description()];
        }
        ksort($byCat);

        foreach ($byCat as $cat => $entries) {
            $renderer->info("{$cat}:");
            foreach ($entries as [$name, $desc]) {
                $line = '  ' . $name;
                if ($desc !== '') {
                    $line .= '  — ' . $desc;
                }
                $renderer->line($line);
            }
            $renderer->newLine();
        }
        return 0;
    }

    private function show(Renderer $renderer, array $rest): int
    {
        $name = $rest[0] ?? null;
        if (! is_string($name) || $name === '') {
            $renderer->error('Usage: superagent skills show <name>');
            return 2;
        }

        $manager = new SkillManager();
        $manager->loadUserDir();
        $manager->loadProjectDir();
        $skill = $manager->get($name);
        if ($skill === null) {
            $renderer->error("Skill not found: {$name}");
            return 1;
        }

        $renderer->info("# {$skill->name()}  [{$skill->category()}]");
        if ($skill->description() !== '') {
            $renderer->line($skill->description());
        }
        $renderer->newLine();
        $renderer->line($skill->template());
        return 0;
    }

    private function install(Renderer $renderer, array $rest): int
    {
        $source = $rest[0] ?? null;
        if (! is_string($source) || ! is_file($source)) {
            $renderer->error('Usage: superagent skills install <path-to-.md-or-.php> [--name <n>]');
            return 2;
        }

        $overrideName = null;
        for ($i = 1; $i < count($rest); $i++) {
            if ($rest[$i] === '--name' && isset($rest[$i + 1])) {
                $overrideName = (string) $rest[++$i];
            }
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (! in_array($ext, ['md', 'php'], true)) {
            $renderer->error("Unsupported skill file extension: .{$ext} (expected .md or .php)");
            return 2;
        }

        // Markdown validation: parse frontmatter and refuse if `name` is missing.
        if ($ext === 'md') {
            try {
                $parsed = MarkdownFrontmatter::parseFile($source);
                if (empty($parsed['frontmatter']['name'])) {
                    $renderer->error('Markdown skill missing "name" in frontmatter');
                    return 1;
                }
                $overrideName ??= (string) $parsed['frontmatter']['name'];
            } catch (\Throwable $e) {
                $renderer->error('Invalid skill markdown: ' . $e->getMessage());
                return 1;
            }
        }

        $dir = SkillManager::userSkillsDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $renderer->error("Failed to create skills dir: {$dir}");
            return 1;
        }

        $basename = $overrideName !== null
            ? self::slug($overrideName) . '.' . $ext
            : basename($source);
        $dest = $dir . '/' . $basename;

        if (! @copy($source, $dest)) {
            $renderer->error("Failed to copy to: {$dest}");
            return 1;
        }

        $renderer->success("Installed skill: {$dest}");
        return 0;
    }

    private function remove(Renderer $renderer, array $rest): int
    {
        $name = $rest[0] ?? null;
        if (! is_string($name) || $name === '') {
            $renderer->error('Usage: superagent skills remove <name>');
            return 2;
        }

        $dir = SkillManager::userSkillsDir();
        if (! is_dir($dir)) {
            $renderer->warning('No user skills directory to remove from.');
            return 0;
        }

        $removed = 0;
        foreach ((glob($dir . '/*.md') ?: []) as $file) {
            try {
                $parsed = MarkdownFrontmatter::parseFile($file);
                if (($parsed['frontmatter']['name'] ?? null) === $name) {
                    if (@unlink($file)) {
                        $removed++;
                    }
                }
            } catch (\Throwable) {
                // skip malformed files
            }
        }

        if ($removed === 0) {
            $renderer->warning("No markdown skill named '{$name}' found in {$dir}.");
            return 0;
        }

        $renderer->success(sprintf('Removed %d skill file(s) matching "%s"', $removed, $name));
        return 0;
    }

    private function path(Renderer $renderer): int
    {
        $renderer->line(SkillManager::userSkillsDir());
        return 0;
    }

    private function usage(Renderer $renderer, string $sub): int
    {
        $renderer->error("Unknown skills subcommand: {$sub}");
        $renderer->line('');
        $renderer->line('Usage:');
        $renderer->line('  superagent skills list                         List all registered skills');
        $renderer->line('  superagent skills show <name>                  Print a skill\'s body + metadata');
        $renderer->line('  superagent skills install <file> [--name <n>]  Install a .md or .php skill file');
        $renderer->line('  superagent skills remove <name>                Remove a markdown skill by name');
        $renderer->line('  superagent skills path                         Print the user skills dir');
        return 2;
    }

    private static function slug(string $name): string
    {
        $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? 'skill');
        return trim($s, '-') ?: 'skill';
    }
}
