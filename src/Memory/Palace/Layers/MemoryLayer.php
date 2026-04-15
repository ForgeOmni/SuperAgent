<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace\Layers;

/**
 * The 4-tier memory stack (MemPalace-inspired).
 *
 *   L0 Identity       ~50  tokens  always loaded
 *   L1 Critical Facts ~120 tokens  always loaded (team, projects, preferences)
 *   L2 Room Recall    on demand    when the current topic appears
 *   L3 Deep Search    on demand    when explicitly asked
 *
 * L0+L1 together are the "wake-up" payload: a tiny, cached bootstrap
 * that lets the agent open a session already knowing the user's world
 * without re-loading the whole memory directory every turn.
 */
enum MemoryLayer: string
{
    case L0_IDENTITY = 'l0_identity';
    case L1_CRITICAL = 'l1_critical';
    case L2_ROOM = 'l2_room';
    case L3_DEEP = 'l3_deep';

    public function alwaysLoaded(): bool
    {
        return $this === self::L0_IDENTITY || $this === self::L1_CRITICAL;
    }

    public function targetTokens(): int
    {
        return match ($this) {
            self::L0_IDENTITY => 50,
            self::L1_CRITICAL => 120,
            self::L2_ROOM => 1200,
            self::L3_DEEP => 4000,
        };
    }
}
