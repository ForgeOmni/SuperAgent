<?php

namespace SuperAgent\Support;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\ProviderRegistry;

class StructuredOutput
{
    protected LLMProvider $provider;
    protected bool $strict;
    protected bool $validateOutput;

    public function __construct(LLMProvider $provider, array $config = [])
    {
        $this->provider = $provider;
        $this->strict = $config['strict'] ?? true;
        $this->validateOutput = $config['validate_output'] ?? true;
    }

    /**
     * Generate structured output based on a JSON schema.
     */
    public function generate(
        string $prompt,
        array $jsonSchema,
        ?string $systemPrompt = null,
        array $options = []
    ): array {
        // Check if provider supports native structured output
        $capabilities = ProviderRegistry::getCapabilities($this->provider->getName());
        $supportsNative = $capabilities['structured_output'] ?? false;

        if ($supportsNative && $this->provider->getName() === 'openai') {
            return $this->generateWithOpenAI($prompt, $jsonSchema, $systemPrompt, $options);
        }

        // Fall back to prompt-based approach
        return $this->generateWithPrompt($prompt, $jsonSchema, $systemPrompt, $options);
    }

    /**
     * Generate using OpenAI's native structured output.
     */
    protected function generateWithOpenAI(
        string $prompt,
        array $jsonSchema,
        ?string $systemPrompt,
        array $options
    ): array {
        $messages = [new UserMessage($prompt)];
        
        // Add response format for structured output
        $options['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $jsonSchema['title'] ?? 'response',
                'strict' => $this->strict,
                'schema' => $jsonSchema,
            ],
        ];

        $response = iterator_to_array(
            $this->provider->chat($messages, [], $systemPrompt, $options)
        )[0];

        $content = $response->content[0]->text ?? '';
        $result = json_decode($content, true);

        if ($this->validateOutput) {
            $this->validateAgainstSchema($result, $jsonSchema);
        }

        return $result;
    }

    /**
     * Generate using prompt-based approach for providers without native support.
     */
    protected function generateWithPrompt(
        string $prompt,
        array $jsonSchema,
        ?string $systemPrompt,
        array $options
    ): array {
        // Build enhanced prompt with schema
        $enhancedPrompt = $this->buildSchemaPrompt($prompt, $jsonSchema);
        
        // Add system instructions for JSON output
        $jsonSystemPrompt = "You are a helpful assistant that always responds with valid JSON that conforms to the provided schema. "
            . "Never include any text outside the JSON object. "
            . "Ensure all required fields are present and have the correct types.";
        
        if ($systemPrompt) {
            $jsonSystemPrompt = "{$systemPrompt}\n\n{$jsonSystemPrompt}";
        }

        $messages = [new UserMessage($enhancedPrompt)];
        
        // Some providers support JSON mode
        if (in_array($this->provider->getName(), ['anthropic', 'ollama'])) {
            $options['format'] = 'json';
        }

        $maxRetries = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = iterator_to_array(
                $this->provider->chat($messages, [], $jsonSystemPrompt, $options)
            )[0];

            $content = $response->content[0]->text ?? '';
            
            // Try to extract JSON from response
            $json = $this->extractJson($content);
            
            if ($json === null) {
                $lastError = "Failed to extract valid JSON from response";
                continue;
            }

            // Validate if required
            if ($this->validateOutput) {
                try {
                    $this->validateAgainstSchema($json, $jsonSchema);
                    return $json;
                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                    
                    // Add error feedback for next attempt
                    $messages[] = new UserMessage(
                        "The JSON output was invalid: {$e->getMessage()}. "
                        . "Please correct it and provide valid JSON that matches the schema."
                    );
                    continue;
                }
            }

            return $json;
        }

        throw new ProviderException(
            "Failed to generate valid structured output after {$maxRetries} attempts. Last error: {$lastError}",
            $this->provider->getName()
        );
    }

    /**
     * Build a prompt that includes the JSON schema.
     */
    protected function buildSchemaPrompt(string $prompt, array $jsonSchema): string
    {
        $schemaStr = json_encode($jsonSchema, JSON_PRETTY_PRINT);
        
        return <<<PROMPT
{$prompt}

You must respond with a JSON object that conforms to this schema:
```json
{$schemaStr}
```

Important:
- Output ONLY valid JSON, no other text
- Include all required fields
- Use the correct data types as specified
- Follow any constraints (min/max values, enum options, patterns)
PROMPT;
    }

    /**
     * Extract JSON from a text response.
     */
    protected function extractJson(string $text): ?array
    {
        // First try direct parsing
        $json = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        // Try to find JSON within the text
        // Look for JSON between ```json and ``` markers
        if (preg_match('/```json\s*\n?(.*?)\n?```/s', $text, $matches)) {
            $json = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        // Look for JSON between ``` markers without json label
        if (preg_match('/```\s*\n?(\{.*?\}|\[.*?\])\n?```/s', $text, $matches)) {
            $json = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        // Try to find JSON object or array in text
        if (preg_match('/(\{.*\}|\[.*\])/s', $text, $matches)) {
            $json = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        return null;
    }

    /**
     * Validate output against JSON schema.
     */
    protected function validateAgainstSchema(array $data, array $schema): void
    {
        // Basic validation - can be enhanced with a proper JSON Schema validator
        if (isset($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!isset($data[$field])) {
                    throw new \InvalidArgumentException("Required field '{$field}' is missing");
                }
            }
        }

        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $field => $fieldSchema) {
                if (!isset($data[$field])) {
                    continue;
                }

                $this->validateField($data[$field], $fieldSchema, $field);
            }
        }
    }

    /**
     * Validate a single field against its schema.
     */
    protected function validateField($value, array $schema, string $fieldName): void
    {
        // Type validation
        if (isset($schema['type'])) {
            $expectedType = $schema['type'];
            $actualType = $this->getJsonType($value);
            
            if ($expectedType !== $actualType) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' must be of type '{$expectedType}', got '{$actualType}'"
                );
            }
        }

        // String validations
        if (isset($schema['minLength']) && is_string($value)) {
            if (strlen($value) < $schema['minLength']) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' must be at least {$schema['minLength']} characters long"
                );
            }
        }

        if (isset($schema['maxLength']) && is_string($value)) {
            if (strlen($value) > $schema['maxLength']) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' must be at most {$schema['maxLength']} characters long"
                );
            }
        }

        if (isset($schema['pattern']) && is_string($value)) {
            if (!preg_match('/' . $schema['pattern'] . '/', $value)) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' does not match required pattern"
                );
            }
        }

        // Number validations
        if (isset($schema['minimum']) && is_numeric($value)) {
            if ($value < $schema['minimum']) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' must be at least {$schema['minimum']}"
                );
            }
        }

        if (isset($schema['maximum']) && is_numeric($value)) {
            if ($value > $schema['maximum']) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' must be at most {$schema['maximum']}"
                );
            }
        }

        // Enum validation
        if (isset($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' must be one of: " . implode(', ', $schema['enum'])
                );
            }
        }

        // Array validations
        if (isset($schema['minItems']) && is_array($value)) {
            if (count($value) < $schema['minItems']) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' must have at least {$schema['minItems']} items"
                );
            }
        }

        if (isset($schema['maxItems']) && is_array($value)) {
            if (count($value) > $schema['maxItems']) {
                throw new \InvalidArgumentException(
                    "Field '{$fieldName}' must have at most {$schema['maxItems']} items"
                );
            }
        }

        // Nested object validation
        if (isset($schema['properties']) && is_array($value)) {
            foreach ($schema['properties'] as $nestedField => $nestedSchema) {
                if (isset($value[$nestedField])) {
                    $this->validateField(
                        $value[$nestedField],
                        $nestedSchema,
                        "{$fieldName}.{$nestedField}"
                    );
                }
            }
        }

        // Array items validation
        if (isset($schema['items']) && is_array($value)) {
            foreach ($value as $index => $item) {
                $this->validateField($item, $schema['items'], "{$fieldName}[{$index}]");
            }
        }
    }

    /**
     * Get JSON type of a PHP value.
     */
    protected function getJsonType($value): string
    {
        if (is_null($value)) return 'null';
        if (is_bool($value)) return 'boolean';
        if (is_int($value)) return 'integer';
        if (is_float($value)) return 'number';
        if (is_string($value)) return 'string';
        if (is_array($value)) {
            // Check if associative or sequential
            if (array_keys($value) === range(0, count($value) - 1)) {
                return 'array';
            }
            return 'object';
        }
        return 'unknown';
    }

    /**
     * Helper to create common schemas.
     */
    public static function schema(): SchemaBuilder
    {
        return new SchemaBuilder();
    }
}

class SchemaBuilder
{
    protected array $schema = [];

    public function object(array $properties = []): self
    {
        $this->schema = [
            'type' => 'object',
            'properties' => $properties,
        ];
        return $this;
    }

    public function array(array $items = []): self
    {
        $this->schema = [
            'type' => 'array',
            'items' => $items,
        ];
        return $this;
    }

    public function string(array $constraints = []): array
    {
        return array_merge(['type' => 'string'], $constraints);
    }

    public function integer(array $constraints = []): array
    {
        return array_merge(['type' => 'integer'], $constraints);
    }

    public function number(array $constraints = []): array
    {
        return array_merge(['type' => 'number'], $constraints);
    }

    public function boolean(): array
    {
        return ['type' => 'boolean'];
    }

    public function enum(array $values): array
    {
        return ['enum' => $values];
    }

    public function required(array $fields): self
    {
        $this->schema['required'] = $fields;
        return $this;
    }

    public function title(string $title): self
    {
        $this->schema['title'] = $title;
        return $this;
    }

    public function description(string $description): self
    {
        $this->schema['description'] = $description;
        return $this;
    }

    public function additionalProperties(bool $allowed): self
    {
        $this->schema['additionalProperties'] = $allowed;
        return $this;
    }

    public function build(): array
    {
        return $this->schema;
    }
}