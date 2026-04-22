<?php

declare(strict_types=1);

namespace SuperAgent\Skills;

use SuperAgent\Contracts\LLMProvider;

/**
 * Apply a `Skill` to an outbound chat request.
 *
 * The universal path merges the skill body into `$options['system_prompt']`
 * (creating it if absent, appending if the caller already set one). This
 * works with every provider because every provider honours a `systemPrompt`
 * argument / `system_prompt` option — the caller of `chat()` is responsible
 * for reading it back out and passing it along.
 *
 * When a provider supports richer native skill primitives (Kimi's server-
 * side `Skills`, MiniMax M2.7's native skill registry) a subclass of
 * `SkillBridge` registered via `SkillInjector::registerBridge()` is called
 * instead of the universal path. No bridges ship in this phase — Kimi's
 * Skills REST is not yet public — but the hook is in place so the upgrade
 * lands as a drop-in when vendor specs firm up.
 *
 * Usage:
 *   $options = ['system_prompt' => 'You are helpful.'];
 *   SkillInjector::inject($skill, $options, $provider);
 *   $provider->chat($messages, $tools, $options['system_prompt'], $options);
 */
class SkillInjector
{
    /**
     * Provider-name → bridge FQCN. A bridge is any class with a static
     * `apply(LLMProvider, Skill, array &$options): bool` method that
     * returns true if it handled the skill natively (injector skips the
     * universal fallback in that case).
     *
     * @var array<string, class-string>
     */
    private static array $bridges = [];

    /**
     * Register a provider-specific bridge. The `apply()` callable receives
     * the skill + options and returns true to skip the universal fallback,
     * false to let it run.
     *
     * @param class-string $bridgeClass
     */
    public static function registerBridge(string $providerName, string $bridgeClass): void
    {
        if (! method_exists($bridgeClass, 'apply')) {
            throw new \InvalidArgumentException(
                "Bridge {$bridgeClass} must declare a static apply() method",
            );
        }
        self::$bridges[$providerName] = $bridgeClass;
    }

    /**
     * @return array<string, class-string>
     */
    public static function registeredBridges(): array
    {
        return self::$bridges;
    }

    public static function resetBridges(): void
    {
        self::$bridges = [];
    }

    /**
     * Apply `$skill` to `$options`. When `$provider` is null the universal
     * path is always used — handy for callers that haven't resolved a
     * provider yet.
     *
     * @param array<string, mixed> $options
     */
    public static function inject(Skill $skill, array &$options, ?LLMProvider $provider = null): void
    {
        if ($provider !== null && isset(self::$bridges[$provider->name()])) {
            $bridge = self::$bridges[$provider->name()];
            if ($bridge::apply($provider, $skill, $options) === true) {
                return;
            }
        }

        self::injectUniversal($skill, $options);
    }

    /**
     * Universal path — merges the skill body into `$options['system_prompt']`
     * with a titled section header so multiple skills can be stacked without
     * clobbering each other.
     *
     * @param array<string, mixed> $options
     */
    private static function injectUniversal(Skill $skill, array &$options): void
    {
        $body = $skill->template();
        if (trim($body) === '') {
            return;
        }

        $header = self::renderHeader($skill);
        $block = trim($header . "\n" . $body);

        $existing = $options['system_prompt'] ?? null;
        if (! is_string($existing) || trim($existing) === '') {
            $options['system_prompt'] = $block;
            return;
        }

        // Idempotent: if the same skill was already injected (by name),
        // do nothing so repeated inject() calls don't stack duplicates.
        if (str_contains($existing, $header)) {
            return;
        }

        $options['system_prompt'] = rtrim($existing) . "\n\n" . $block;
    }

    private static function renderHeader(Skill $skill): string
    {
        $name = $skill->name();
        $desc = trim($skill->description());
        $title = "## Skill: {$name}";
        if ($desc !== '') {
            $title .= " — {$desc}";
        }
        return $title;
    }
}
