<?php

declare(strict_types=1);

/**
 * SuperAgent standalone polyfills for collect() and now().
 *
 * Composer's `files` autoload load order across packages is not guaranteed.
 * We can't rely on `function_exists()` alone — if our helpers load before
 * illuminate/support's helpers, our collect() wins and Laravel's
 * ReflectsClosures breaks at runtime (mapWithKeys not defined).
 *
 * Using class_exists() against Illuminate's bundled classes is reliable
 * because PSR-4 autoload resolves those immediately on first use.
 */

// class_exists() with autoloading enabled — if illuminate/support is
// installed, this triggers its autoload and returns true; we skip
// declaring our collect() so Laravel's helpers.php can declare it.
if (! class_exists(\Illuminate\Support\Collection::class) && ! function_exists('collect')) {
    /**
     * Create a new Collection instance.
     *
     * @param  array|\SuperAgent\Support\Collection  $items
     * @return \SuperAgent\Support\Collection
     */
    function collect(array|\SuperAgent\Support\Collection $items = []): \SuperAgent\Support\Collection
    {
        return new \SuperAgent\Support\Collection($items);
    }
}

if (! class_exists(\Illuminate\Support\Carbon::class) && ! function_exists('now')) {
    /**
     * Create a new DateTime instance for "now".
     *
     * Returns SuperAgent\Support\DateTime which provides toIso8601String()
     * for Carbon compatibility.
     */
    function now(?\DateTimeZone $tz = null): \SuperAgent\Support\DateTime
    {
        return \SuperAgent\Support\DateTime::now($tz);
    }
}
