<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Compat;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolPolicy;

/**
 * A tool policy refuses by category, so a builtin that declares none is a
 * hole: it reads as 'general', which no deny list names, and it would be
 * offered to an embedded host's model however carefully that host configured
 * itself. Three goal tools were exactly that until 1.3.0.
 *
 * This test exists for the next builtin, not for those three — it fails on
 * any tool that inherits the base category instead of declaring its own.
 */
class BuiltinToolCategoryLockdownTest extends TestCase
{
    public function test_every_builtin_tool_declares_its_own_category(): void
    {
        $inherited = [];

        foreach ($this->builtinToolClasses() as $class) {
            $reflection = new ReflectionClass($class);
            $declaredIn = $reflection->getMethod('category')->getDeclaringClass()->getName();

            if ($declaredIn === Tool::class) {
                $inherited[] = $reflection->getShortName();
            }
        }

        $this->assertSame(
            [],
            $inherited,
            'These builtin tools fall back to the base category and so cannot be '
            . 'filtered by a ToolPolicy: ' . implode(', ', $inherited)
        );
    }

    public function test_the_host_reaching_categories_still_name_real_builtins(): void
    {
        // Guards the other direction: if a category in HOST_CATEGORIES stops
        // matching anything, the constant has drifted from what the tools say
        // about themselves and the deny list quietly covers less than it reads.
        $categories = [];

        foreach ($this->builtinToolClasses() as $class) {
            // Without the constructor: several builtins need collaborators,
            // and category() answers from a literal in every one of them.
            $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $categories[] = strtolower($tool->category());
        }

        $categories = array_unique($categories);
        $unmatched = array_diff(ToolPolicy::HOST_CATEGORIES, $categories);

        $this->assertSame(
            [],
            array_values($unmatched),
            'HOST_CATEGORIES names categories no builtin declares: ' . implode(', ', $unmatched)
        );
    }

    /** @return list<class-string<Tool>> */
    private function builtinToolClasses(): array
    {
        $classes = [];

        foreach (glob(__DIR__ . '/../../src/Tools/Builtin/*.php') as $file) {
            $class = 'SuperAgent\\Tools\\Builtin\\' . basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Tool::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
