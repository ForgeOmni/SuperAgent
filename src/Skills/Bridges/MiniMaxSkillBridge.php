<?php

declare(strict_types=1);

namespace SuperAgent\Skills\Bridges;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Skills\Skill;

/**
 * MiniMax M2.7 has **trained-in Skills consumption** — the model reads
 * skills out of the system prompt without a dedicated REST primitive
 * (unlike Kimi's file-based Skills). So the "native" path here is still
 * system-prompt injection, but with a MiniMax-tailored framing that
 * leans on the model's native Skills discipline (97 % adherence rate on
 * 2000-token skills per MiniMax's own benchmarks).
 *
 * Key MiniMax-specific nuance: the scaffold explicitly names it a
 * "Skill" + tells the model to *follow* the skill body as a contract
 * rather than treating it as advisory. The universal `SkillInjector`
 * prompt is more neutral and doesn't invoke that trained-in behaviour.
 *
 * Registered via `SkillInjector::registerBridge('minimax', MiniMaxSkillBridge::class)`.
 */
final class MiniMaxSkillBridge
{
    public static function apply(LLMProvider $provider, Skill $skill, array &$options): bool
    {
        $body = $skill->template();
        if (trim($body) === '') {
            return false;  // nothing to inject — let universal path no-op too
        }

        $header = sprintf(
            "## Active Skill: %s%s",
            $skill->name(),
            trim($skill->description()) !== '' ? " — " . $skill->description() : '',
        );

        // MiniMax-specific framing — the "Follow this skill as an active
        // contract" framing is what triggers the trained-in high
        // adherence rate. Generic injectors use softer wording.
        $scaffold = $header . "\n"
            . "Follow the skill below as an active behavioural contract. "
            . "When the skill conflicts with the user's literal instruction, "
            . "prefer the skill unless the user explicitly opts out.\n\n"
            . $body;

        $existing = $options['system_prompt'] ?? null;
        if (is_string($existing) && str_contains($existing, $header)) {
            return true;  // idempotent — already injected
        }

        $options['system_prompt'] = is_string($existing) && trim($existing) !== ''
            ? rtrim($existing) . "\n\n" . $scaffold
            : $scaffold;

        return true;
    }
}
