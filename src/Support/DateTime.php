<?php

declare(strict_types=1);

namespace SuperAgent\Support;

/**
 * Lightweight DateTime class for standalone (non-Laravel) usage.
 *
 * Extends DateTimeImmutable with Carbon-compatible convenience methods
 * used throughout the SuperAgent codebase. This class implements the
 * subset of Carbon's API actually used in SuperAgent, allowing the
 * codebase to work without nesbot/carbon installed.
 *
 * Usage: `use SuperAgent\Support\DateTime as Carbon;` in files that
 * previously imported `Carbon\Carbon`.
 */
class DateTime extends \DateTimeImmutable
{
    /** @var static|string|null Frozen "now" for testing */
    private static string|self|null $testNow = null;

    /** Create a new instance for "now". */
    public static function now(?\DateTimeZone $tz = null): static
    {
        // Check our own test now
        if (self::$testNow !== null) {
            if (self::$testNow instanceof self) {
                return clone self::$testNow;
            }
            return new static(self::$testNow, $tz);
        }

        // Check if real Carbon has a frozen test time
        if (class_exists(\Carbon\Carbon::class, false)) {
            try {
                $carbonNow = \Carbon\Carbon::getTestNow();
                if ($carbonNow !== null) {
                    return new static($carbonNow->format('Y-m-d H:i:s.u'), $carbonNow->getTimezone());
                }
            } catch (\Throwable) {
                // Carbon not properly configured
            }
        }

        return new static('now', $tz);
    }

    /** Freeze "now" for testing (Carbon compatibility). */
    public static function setTestNow(string|self|\DateTimeInterface|null $time = null): void
    {
        if ($time === null) {
            self::$testNow = null;
        } elseif ($time instanceof self) {
            self::$testNow = $time;
        } elseif ($time instanceof \DateTimeInterface) {
            self::$testNow = new static($time->format('Y-m-d H:i:s.u'), $time->getTimezone());
        } else {
            self::$testNow = $time;
        }
    }

    /** Parse a date string into a DateTime instance. */
    public static function parse(string|\DateTimeInterface $time, ?\DateTimeZone $tz = null): static
    {
        if ($time instanceof \DateTimeInterface) {
            return new static($time->format('Y-m-d H:i:s.u'), $time->getTimezone());
        }

        return new static($time, $tz);
    }

    // --- Formatting ---

    /** Format as ISO 8601 string (Carbon compatibility). */
    public function toIso8601String(): string
    {
        return $this->format(\DateTimeInterface::ATOM);
    }

    /** Format as date-time string (Carbon compatibility). */
    public function toDateTimeString(): string
    {
        return $this->format('Y-m-d H:i:s');
    }

    // --- Diff methods ---

    /** Get the difference in days. */
    public function diffInDays(self|\DateTimeInterface|null $other = null): int
    {
        $other = $other ?? new static('now');
        $diff = $this->diff($other);

        return (int) $diff->days;
    }

    /** Get the difference in hours. */
    public function diffInHours(self|\DateTimeInterface|null $other = null): int
    {
        $other = $other ?? new static('now');

        return (int) abs(($other->getTimestamp() - $this->getTimestamp()) / 3600);
    }

    /** Get the difference in minutes. */
    public function diffInMinutes(self|\DateTimeInterface|null $other = null): int
    {
        $other = $other ?? new static('now');

        return (int) abs(($other->getTimestamp() - $this->getTimestamp()) / 60);
    }

    /** Get the difference in seconds. */
    public function diffInSeconds(self|\DateTimeInterface|null $other = null): int
    {
        $other = $other ?? new static('now');

        return (int) abs($other->getTimestamp() - $this->getTimestamp());
    }

    // --- Subtraction methods ---

    /** Subtract days. */
    public function subDays(int $days): static
    {
        return $this->modify("-{$days} days");
    }

    /** Subtract hours. */
    public function subHours(int $hours): static
    {
        return $this->modify("-{$hours} hours");
    }

    /** Subtract minutes. */
    public function subMinutes(int $minutes): static
    {
        return $this->modify("-{$minutes} minutes");
    }

    /** Subtract seconds. */
    public function subSeconds(int $seconds): static
    {
        return $this->modify("-{$seconds} seconds");
    }

    // --- Addition methods ---

    /** Add days. */
    public function addDays(int $days): static
    {
        return $this->modify("+{$days} days");
    }

    /** Add hours. */
    public function addHours(int $hours): static
    {
        return $this->modify("+{$hours} hours");
    }

    /** Add minutes. */
    public function addMinutes(int $minutes): static
    {
        return $this->modify("+{$minutes} minutes");
    }

    /** Add seconds. */
    public function addSeconds(int $seconds): static
    {
        return $this->modify("+{$seconds} seconds");
    }

    // --- Copy ---

    /** Create a mutable-style copy (Carbon compatibility). */
    public function copy(): static
    {
        return clone $this;
    }

    // --- Comparison methods ---

    /** Check if this date is greater than (after) another. */
    public function greaterThan(self|\DateTimeInterface $other): bool
    {
        return $this > $other;
    }

    /** Check if this date is less than (before) another. */
    public function lessThan(self|\DateTimeInterface $other): bool
    {
        return $this < $other;
    }

    // --- Property access (Carbon compatibility) ---

    /** Get Unix timestamp as int (Carbon $timestamp property compatibility). */
    public function __get(string $name): mixed
    {
        if ($name === 'timestamp') {
            return $this->getTimestamp();
        }

        throw new \RuntimeException("Undefined property: {$name}");
    }

    public function __isset(string $name): bool
    {
        return $name === 'timestamp';
    }
}
