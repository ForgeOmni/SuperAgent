<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider supports "Skills" — reusable packages of instruction, style DNA
 * and/or tools that can be registered once and referenced by id in later
 * requests (Kimi K2.6 Skills derived from PDFs/decks, MiniMax M2.7 complex
 * Skills).
 *
 * SuperAgent's in-repo Skill system (loaded from `~/.superagent/skills/`
 * and project-local `.superagent/skills/`) is the canonical format; per-
 * provider bridges translate those skills to each provider's native
 * representation.
 */
interface SupportsSkills
{
    /**
     * Register a Skill with the provider. Returns a provider-scoped skill
     * id that later `applySkillFragment()` calls can reference.
     *
     * @param array{name: string, description?: string, body: string, ...} $skill
     */
    public function registerSkill(array $skill): string;

    /**
     * Build the request fragment (usually a system-prompt or tool
     * injection) that activates a previously-registered skill for the
     * next turn.
     *
     * @return array<string, mixed>
     */
    public function applySkillFragment(string $skillId): array;

    /**
     * List skills known to the provider for this account.
     *
     * @return array<int, array{id: string, name: string, description?: string}>
     */
    public function listSkills(): array;
}
