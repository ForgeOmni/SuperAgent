<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class PhpUnitTool extends Tool
{
    public function name(): string
    {
        return 'phpunit';
    }

    public function description(): string
    {
        return 'Run PHPUnit tests and return results.';
    }

    public function category(): string
    {
        return 'testing';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'description' => 'Filter expression to run specific tests.',
                ],
                'testsuite' => [
                    'type' => 'string',
                    'description' => 'Name of the test suite to run.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to specific test file or directory.',
                ],
                'coverage' => [
                    'type' => 'boolean',
                    'description' => 'Generate code coverage report.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $binary = $this->findPhpUnit();
        if ($binary === null) {
            return ToolResult::error('PHPUnit binary not found. Install via composer require --dev phpunit/phpunit.');
        }

        $args = [];

        if (!empty($input['filter'])) {
            $args[] = '--filter ' . escapeshellarg($input['filter']);
        }

        if (!empty($input['testsuite'])) {
            $args[] = '--testsuite ' . escapeshellarg($input['testsuite']);
        }

        if (!empty($input['coverage'])) {
            $args[] = '--coverage-text';
        }

        if (!empty($input['path'])) {
            $args[] = escapeshellarg($input['path']);
        }

        $cmd = $binary . ' ' . implode(' ', $args) . ' 2>&1';
        $output = shell_exec($cmd);

        return ToolResult::success($output ?? 'No output from PHPUnit.');
    }

    private function findPhpUnit(): ?string
    {
        foreach (['./vendor/bin/phpunit', 'phpunit'] as $candidate) {
            if (is_executable($candidate) || shell_exec("which {$candidate} 2>/dev/null")) {
                return $candidate;
            }
        }
        return null;
    }
}
