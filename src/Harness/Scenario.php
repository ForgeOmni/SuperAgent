<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

/**
 * Definition of a single E2E test scenario.
 *
 * A scenario describes:
 *   - A prompt to submit to the agent
 *   - Which tools the agent is expected to use
 *   - An optional setup closure to prepare fixtures
 *   - An optional validation closure to check results
 *   - Expected text that should appear in the final output
 */
class Scenario
{
    public function __construct(
        public readonly string $name,
        public readonly string $prompt,
        public readonly array $requiredTools = [],
        public readonly ?string $expectedText = null,
        public readonly ?\Closure $setup = null,
        public readonly ?\Closure $validate = null,
        public readonly ?string $description = null,
        public readonly int $maxTurns = 20,
        public readonly array $tags = [],
        public readonly array $tools = [],
    ) {}

    /**
     * Fluent builder for convenience.
     */
    public static function create(string $name, string $prompt): self
    {
        return new self(name: $name, prompt: $prompt);
    }

    public function withRequiredTools(array $tools): self
    {
        return new self(
            name: $this->name,
            prompt: $this->prompt,
            requiredTools: $tools,
            expectedText: $this->expectedText,
            setup: $this->setup,
            validate: $this->validate,
            description: $this->description,
            maxTurns: $this->maxTurns,
            tags: $this->tags,
            tools: $this->tools,
        );
    }

    public function withExpectedText(string $text): self
    {
        return new self(
            name: $this->name,
            prompt: $this->prompt,
            requiredTools: $this->requiredTools,
            expectedText: $text,
            setup: $this->setup,
            validate: $this->validate,
            description: $this->description,
            maxTurns: $this->maxTurns,
            tags: $this->tags,
            tools: $this->tools,
        );
    }

    public function withSetup(\Closure $setup): self
    {
        return new self(
            name: $this->name,
            prompt: $this->prompt,
            requiredTools: $this->requiredTools,
            expectedText: $this->expectedText,
            setup: $setup,
            validate: $this->validate,
            description: $this->description,
            maxTurns: $this->maxTurns,
            tags: $this->tags,
            tools: $this->tools,
        );
    }

    public function withValidation(\Closure $validate): self
    {
        return new self(
            name: $this->name,
            prompt: $this->prompt,
            requiredTools: $this->requiredTools,
            expectedText: $this->expectedText,
            setup: $this->setup,
            validate: $validate,
            description: $this->description,
            maxTurns: $this->maxTurns,
            tags: $this->tags,
            tools: $this->tools,
        );
    }

    public function withMaxTurns(int $maxTurns): self
    {
        return new self(
            name: $this->name,
            prompt: $this->prompt,
            requiredTools: $this->requiredTools,
            expectedText: $this->expectedText,
            setup: $this->setup,
            validate: $this->validate,
            description: $this->description,
            maxTurns: $maxTurns,
            tags: $this->tags,
            tools: $this->tools,
        );
    }

    public function withTags(array $tags): self
    {
        return new self(
            name: $this->name,
            prompt: $this->prompt,
            requiredTools: $this->requiredTools,
            expectedText: $this->expectedText,
            setup: $this->setup,
            validate: $this->validate,
            description: $this->description,
            maxTurns: $this->maxTurns,
            tags: $tags,
            tools: $this->tools,
        );
    }

    public function withTools(array $tools): self
    {
        return new self(
            name: $this->name,
            prompt: $this->prompt,
            requiredTools: $this->requiredTools,
            expectedText: $this->expectedText,
            setup: $this->setup,
            validate: $this->validate,
            description: $this->description,
            maxTurns: $this->maxTurns,
            tags: $this->tags,
            tools: $tools,
        );
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }
}
