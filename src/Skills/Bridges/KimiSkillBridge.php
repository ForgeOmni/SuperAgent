<?php

declare(strict_types=1);

namespace SuperAgent\Skills\Bridges;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Skills\Skill;

/**
 * Kimi K2.6 natively supports Skills (vendor docs: "turn PDFs/decks/docs
 * into reusable Skills"). The native-upload REST schema is not yet
 * publicly documented, so this bridge **falls through** to the universal
 * `SkillInjector` path today — which still works, it just doesn't
 * exploit Kimi's trained-in skill consumption.
 *
 * When Moonshot publishes the `/v1/skills` (or equivalent) endpoint:
 *
 *   1. Lookup / upload the skill (cache the `kimi_skill_id` in
 *      `$skill->getMeta()` or a local sidecar).
 *   2. Override the call body with a skill reference the model can
 *      resolve natively (likely a system-prompt line like
 *      `Activate skill: <kimi_skill_id>` or a `skills` request-body field).
 *   3. Return true to short-circuit the universal prompt-injection path.
 *
 * Until then the bridge is intentionally a no-op — returning false makes
 * `SkillInjector` continue with its standard universal path.
 *
 * Registered via `SkillInjector::registerBridge('kimi', KimiSkillBridge::class)`.
 */
final class KimiSkillBridge
{
    public static function apply(LLMProvider $provider, Skill $skill, array &$options): bool
    {
        // TODO(v0.9.x): when Moonshot publishes native Skills REST, upload the
        //   skill body once, cache the `kimi_skill_id`, and inject that ref
        //   here instead of the universal body. Until then, fall through.
        return false;
    }
}
