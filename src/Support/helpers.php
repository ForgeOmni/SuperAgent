<?php

declare(strict_types=1);

/**
 * SuperAgent standalone polyfills for collect() and now().
 *
 * These functions are only defined when Laravel's helpers are NOT present.
 * In a Laravel application, illuminate/support defines these first via
 * Composer autoload, so our function_exists() checks skip them.
 */

if (! function_exists('collect')) {
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

if (! function_exists('now')) {
    /**
     * Create a new DateTime instance for "now".
     *
     * Returns SuperAgent\Support\DateTime which provides toIso8601String()
     * for Carbon compatibility.
     *
     * @return \SuperAgent\Support\DateTime
     */
    function now(?\DateTimeZone $tz = null): \SuperAgent\Support\DateTime
    {
        return \SuperAgent\Support\DateTime::now($tz);
    }
}
