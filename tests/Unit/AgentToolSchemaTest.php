<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Permissions\PermissionMode;
use SuperAgent\Swarm\IsolationMode;
use SuperAgent\Tools\Builtin\AgentTool;

/**
 * Verify AgentTool schema enums stay in sync with their backing PHP enums.
 */
class AgentToolSchemaTest extends TestCase
{
    private array $schema;

    protected function setUp(): void
    {
        $tool = new AgentTool();
        $this->schema = $tool->inputSchema();
    }

    /**
     * Every value in the 'mode' schema enum must be resolvable to a PermissionMode.
     */
    public function testModeEnumValuesResolveToPermissionMode(): void
    {
        $enumValues = $this->schema['properties']['mode']['enum'] ?? [];
        $this->assertNotEmpty($enumValues, 'mode enum should not be empty');

        // Known aliases that map to a different canonical value
        $aliases = ['bypass' => 'bypassPermissions'];

        foreach ($enumValues as $value) {
            $resolved = $aliases[$value] ?? $value;
            try {
                $mode = PermissionMode::from($resolved);
                $this->assertInstanceOf(PermissionMode::class, $mode);
            } catch (\ValueError $e) {
                $this->fail(
                    "Schema enum value '{$value}' (resolved: '{$resolved}') "
                    . "cannot be converted to PermissionMode. "
                    . "Valid values: " . implode(', ', array_column(PermissionMode::cases(), 'value'))
                );
            }
        }
    }

    /**
     * Every PermissionMode enum case must appear in the schema enum.
     */
    public function testAllPermissionModeCasesInSchema(): void
    {
        $enumValues = $this->schema['properties']['mode']['enum'] ?? [];

        foreach (PermissionMode::cases() as $case) {
            $this->assertContains(
                $case->value,
                $enumValues,
                "PermissionMode::{$case->name} ('{$case->value}') missing from schema enum"
            );
        }
    }

    /**
     * Every value in the 'isolation' schema enum must match an IsolationMode case.
     */
    public function testIsolationEnumValuesResolveToIsolationMode(): void
    {
        $enumValues = $this->schema['properties']['isolation']['enum'] ?? [];
        $this->assertNotEmpty($enumValues, 'isolation enum should not be empty');

        foreach ($enumValues as $value) {
            try {
                $mode = IsolationMode::from($value);
                $this->assertInstanceOf(IsolationMode::class, $mode);
            } catch (\ValueError $e) {
                $this->fail(
                    "Schema enum value '{$value}' cannot be converted to IsolationMode. "
                    . "Valid values: " . implode(', ', array_column(IsolationMode::cases(), 'value'))
                );
            }
        }
    }

    /**
     * Every IsolationMode enum case must appear in the schema enum.
     */
    public function testAllIsolationModeCasesInSchema(): void
    {
        $enumValues = $this->schema['properties']['isolation']['enum'] ?? [];

        foreach (IsolationMode::cases() as $case) {
            $this->assertContains(
                $case->value,
                $enumValues,
                "IsolationMode::{$case->name} ('{$case->value}') missing from schema enum"
            );
        }
    }
}
