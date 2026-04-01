<?php

declare(strict_types=1);

namespace SuperAgent\Prompt;

/**
 * A single section of the system prompt.
 *
 * Can hold either static content or a lazy resolver (closure).
 */
class PromptSection
{
    /** @var \Closure|null */
    private ?\Closure $resolver;

    public function __construct(
        public readonly string $name,
        private ?string $content = null,
        ?\Closure $resolver = null,
    ) {
        $this->resolver = $resolver;
    }

    /**
     * Resolve the section content.
     *
     * @param string[] $enabledTools Currently enabled tool names
     */
    public function resolve(array $enabledTools = []): ?string
    {
        if ($this->content !== null) {
            return $this->content;
        }

        if ($this->resolver !== null) {
            $fn = $this->resolver;
            $ref = new \ReflectionFunction($fn);

            // Pass enabledTools if the resolver accepts a parameter
            if ($ref->getNumberOfParameters() > 0) {
                return $fn($enabledTools);
            }

            return $fn();
        }

        return null;
    }
}
