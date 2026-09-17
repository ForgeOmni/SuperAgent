<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\GeminiProvider;
use SuperAgent\Providers\ResponseFormat;

/**
 * Gemini's own structured output and safety thresholds.
 *
 * Asking for JSON in words and parsing what comes back works with every
 * provider, which is why it was the only route here; Gemini will enforce a
 * schema itself, and a caller that hands one over should get that rather than
 * a politely worded request.
 */
class GeminiStructuredOutputTest extends TestCase
{
    public function test_a_response_format_becomes_gemini_structured_output(): void
    {
        $body = $this->build(['response_format' => ResponseFormat::jsonSchema([
            'type' => 'object',
            'properties' => ['stops' => ['type' => 'integer']],
            'required' => ['stops'],
        ])]);

        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['stops' => ['type' => 'integer']], 'required' => ['stops']],
            $body['generationConfig']['responseSchema'],
        );
    }

    public function test_json_without_a_schema_asks_only_for_the_mime_type(): void
    {
        $body = $this->build(['response_format' => ResponseFormat::json()]);

        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertArrayNotHasKey('responseSchema', $body['generationConfig']);
    }

    public function test_plain_text_asks_for_nothing(): void
    {
        $body = $this->build(['response_format' => ResponseFormat::text()]);

        $this->assertArrayNotHasKey('responseMimeType', $body['generationConfig']);
    }

    public function test_a_raw_schema_option_works_and_implies_json(): void
    {
        // For a caller that already holds Gemini's own shape.
        $body = $this->build(['response_schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]]]);

        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertSame(['ok' => ['type' => 'boolean']], $body['generationConfig']['responseSchema']['properties']);
    }

    public function test_the_schema_is_narrowed_to_what_gemini_accepts(): void
    {
        // Gemini speaks an OpenAPI 3.0 subset and 400s on the rest, so the
        // schema goes through the same sanitiser as a tool's parameters.
        $body = $this->build(['response_schema' => [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'additionalProperties' => false,
            'properties' => ['name' => ['type' => 'string', 'pattern' => '^[a-z]+$']],
        ]]);

        $schema = $body['generationConfig']['responseSchema'];
        $this->assertArrayNotHasKey('$schema', $schema);
        $this->assertArrayNotHasKey('additionalProperties', $schema);
        $this->assertArrayNotHasKey('pattern', $schema['properties']['name']);
        $this->assertSame('string', $schema['properties']['name']['type']);
    }

    public function test_no_structured_output_leaves_the_request_alone(): void
    {
        $this->assertArrayNotHasKey('responseMimeType', $this->build([])['generationConfig']);
    }

    public function test_safety_settings_are_sent_when_the_call_carries_them(): void
    {
        $settings = [['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE']];

        $this->assertSame($settings, $this->build(['safety_settings' => $settings])['safetySettings']);
    }

    public function test_safety_settings_can_be_configured_once_for_the_provider(): void
    {
        $settings = [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH']];
        $provider = new GeminiProvider(['api_key' => 'k', 'safety_settings' => $settings]);

        $this->assertSame($settings, $this->buildOn($provider, [])['safetySettings']);
    }

    public function test_a_call_overrides_the_configured_thresholds(): void
    {
        $provider = new GeminiProvider([
            'api_key' => 'k',
            'safety_settings' => [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH']],
        ]);
        $perCall = [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE']];

        $this->assertSame($perCall, $this->buildOn($provider, ['safety_settings' => $perCall])['safetySettings']);
    }

    public function test_nothing_is_sent_when_no_thresholds_are_set(): void
    {
        // Gemini's own defaults, which is what every caller got before this.
        $this->assertArrayNotHasKey('safetySettings', $this->build([]));
    }

    private function build(array $options): array
    {
        return $this->buildOn(new GeminiProvider(['api_key' => 'k']), $options);
    }

    private function buildOn(GeminiProvider $provider, array $options): array
    {
        $m = new \ReflectionMethod($provider, 'buildRequestBody');

        return $m->invoke($provider, [new UserMessage('hi')], [], null, $options);
    }
}
