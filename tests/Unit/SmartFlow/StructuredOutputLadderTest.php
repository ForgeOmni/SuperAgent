<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAgent\SmartFlow\SchemaStub;
use SuperAgent\SmartFlow\SchemaValidator;
use SuperAgent\SmartFlow\Skip;
use SuperAgent\SmartFlow\StructuredOutputLadder;

class StructuredOutputLadderTest extends TestCase
{
    private array $schema = [
        'type' => 'object',
        'required' => ['title', 'score'],
        'properties' => [
            'title' => ['type' => 'string', 'minLength' => 1],
            'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
        ],
    ];

    public function test_no_schema_passes_text_through(): void
    {
        $out = StructuredOutputLadder::resolve('just words', null);
        $this->assertSame('text', $out['layer']);
        $this->assertSame('just words', $out['value']);
        $this->assertTrue($out['valid']);
    }

    public function test_native_layer_for_direct_json_when_requested(): void
    {
        $text = json_encode(['title' => 'Hi', 'score' => 7]);
        $out = StructuredOutputLadder::resolve($text, $this->schema, nativeRequested: true);
        $this->assertSame('native', $out['layer']);
        $this->assertSame(7, $out['value']['score']);
        $this->assertTrue($out['valid']);
    }

    public function test_submitted_layer_for_bare_json_when_not_requested(): void
    {
        $text = json_encode(['title' => 'Hi', 'score' => 7]);
        $out = StructuredOutputLadder::resolve($text, $this->schema, nativeRequested: false);
        $this->assertSame('submitted', $out['layer']);
    }

    public function test_submitted_layer_for_fenced_block(): void
    {
        $text = "Here you go:\n```json\n{\"title\":\"Hi\",\"score\":3}\n```\nthanks";
        $out = StructuredOutputLadder::resolve($text, $this->schema, nativeRequested: true);
        $this->assertSame('submitted', $out['layer']);
        $this->assertSame(3, $out['value']['score']);
    }

    public function test_extracted_layer_for_embedded_object(): void
    {
        $text = 'The answer is {"title":"Hi","score":5} I think.';
        $out = StructuredOutputLadder::resolve($text, $this->schema);
        $this->assertSame('extracted', $out['layer']);
        $this->assertSame(5, $out['value']['score']);
    }

    public function test_invalid_yields_skip(): void
    {
        $text = '{"title":"Hi"}'; // missing required score
        $out = StructuredOutputLadder::resolve($text, $this->schema);
        $this->assertFalse($out['valid']);
        $this->assertTrue(Skip::isSkip($out['value']));
        $this->assertSame('none', $out['layer']);
        $this->assertNotEmpty($out['errors']);
    }

    public function test_garbage_yields_skip(): void
    {
        $out = StructuredOutputLadder::resolve('no json at all here', $this->schema);
        $this->assertFalse($out['valid']);
        $this->assertTrue(Skip::isSkip($out['value']));
    }

    public function test_schema_stub_conforms_to_schema(): void
    {
        $stub = SchemaStub::generate($this->schema, 'seed');
        $this->assertSame([], SchemaValidator::validate($stub, $this->schema));
    }

    public function test_schema_validator_enum_and_nested(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['kind', 'items'],
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
                'items' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'integer']],
            ],
        ];
        $this->assertTrue(SchemaValidator::isValid(['kind' => 'a', 'items' => [1, 2]], $schema));
        $this->assertFalse(SchemaValidator::isValid(['kind' => 'c', 'items' => [1]], $schema));
        $this->assertFalse(SchemaValidator::isValid(['kind' => 'a', 'items' => []], $schema));
        $this->assertFalse(SchemaValidator::isValid(['kind' => 'a', 'items' => ['x']], $schema));
    }
}
