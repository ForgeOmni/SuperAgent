<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

/**
 * A Hall is a memory-type corridor within a Wing.
 *
 * Inspired by MemPalace: halls are the same in every wing and act as
 * typed corridors connecting rooms within a wing. They are also the
 * anchor used to bridge rooms across different wings (tunnels).
 *
 * The five halls cover the full vocabulary of an agent's memory:
 *   - FACTS: locked-in decisions and ground truth
 *   - EVENTS: sessions, milestones, debugging runs
 *   - DISCOVERIES: breakthroughs, new insights
 *   - PREFERENCES: habits, user/team likes and dislikes
 *   - ADVICE: recommendations, solutions, warnings
 */
enum Hall: string
{
    case FACTS = 'facts';
    case EVENTS = 'events';
    case DISCOVERIES = 'discoveries';
    case PREFERENCES = 'preferences';
    case ADVICE = 'advice';

    public function slug(): string
    {
        return 'hall_' . $this->value;
    }

    public function description(): string
    {
        return match ($this) {
            self::FACTS => 'Decisions made, choices locked in',
            self::EVENTS => 'Sessions, milestones, debugging',
            self::DISCOVERIES => 'Breakthroughs, new insights',
            self::PREFERENCES => 'Habits, likes, opinions',
            self::ADVICE => 'Recommendations and solutions',
        };
    }

    /**
     * Best-fit hall for a free-form memory kind string (e.g. from MemoryType).
     */
    public static function forKind(string $kind): self
    {
        return match (strtolower($kind)) {
            'user', 'preference', 'preferences' => self::PREFERENCES,
            'feedback', 'advice', 'recommendation' => self::ADVICE,
            'project', 'decision', 'fact', 'facts' => self::FACTS,
            'session', 'event', 'events', 'milestone' => self::EVENTS,
            'discovery', 'discoveries', 'insight' => self::DISCOVERIES,
            'reference' => self::FACTS,
            default => self::EVENTS,
        };
    }
}
