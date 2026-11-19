<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace core\filter;

/**
 * Request-scoped filter state cache.
 *
 * For use only by filterlib.php internal functions. Use
 * filter_reset_request_cache() to clear the cache from elsewhere.
 *
 * @package    core
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_cache {
    /** @var array Active rows by context and filter. */
    private static array $activerows = [];

    /** @var array Contexts with loaded active rows. */
    private static array $activerowsloaded = [];

    /** @var array Config values by context and filter. */
    private static array $configvalues = [];

    /** @var array Contexts with loaded config values. */
    private static array $configvaluesloaded = [];

    /** @var array Active filter lists by context. */
    private static array $active = [];

    /** @var array Resolved states by main context. */
    private static array $info = [];

    /** @var array Preload markers. */
    private static array $preloaded = [];

    /**
     * Reset the cache.
     */
    public static function reset(): void {
        self::$activerows = [];
        self::$activerowsloaded = [];
        self::$configvalues = [];
        self::$configvaluesloaded = [];
        self::$active = [];
        self::$info = [];
        self::$preloaded = [];
    }

    /**
     * Check whether a preload key has already been cached.
     *
     * @param string $preloadkey
     * @return bool
     */
    public static function is_preloaded(string $preloadkey): bool {
        return isset(self::$preloaded[$preloadkey]);
    }

    /**
     * Mark a preload key as cached.
     *
     * @param string $preloadkey
     */
    public static function mark_preloaded(string $preloadkey): void {
        self::$preloaded[$preloadkey] = true;
    }

    /**
     * Get active rows for a context.
     *
     * @param int $contextid
     * @return array|null Null if not loaded.
     */
    public static function get_active_rows(int $contextid): ?array {
        if (!isset(self::$activerowsloaded[$contextid])) {
            return null;
        }
        return self::$activerows[$contextid] ?? [];
    }

    /**
     * Store active rows for a context.
     *
     * @param int $contextid
     * @param array $rows Active rows keyed by filter.
     */
    public static function set_active_rows(int $contextid, array $rows): void {
        self::$activerows[$contextid] = $rows;
        self::$activerowsloaded[$contextid] = true;
    }

    /**
     * Get one active row.
     *
     * @param int $contextid
     * @param string $filter
     * @return \stdClass|null
     */
    public static function get_active_row(int $contextid, string $filter): ?\stdClass {
        return self::$activerows[$contextid][$filter] ?? null;
    }

    /**
     * Get config values for a context.
     *
     * @param int $contextid
     * @return array|null Null if not loaded.
     */
    public static function get_config_values(int $contextid): ?array {
        if (!isset(self::$configvaluesloaded[$contextid])) {
            return null;
        }
        return self::$configvalues[$contextid] ?? [];
    }

    /**
     * Store config values for a context.
     *
     * @param int $contextid
     * @param array $values Config values by filter.
     */
    public static function set_config_values(int $contextid, array $values): void {
        self::$configvalues[$contextid] = $values;
        self::$configvaluesloaded[$contextid] = true;
    }

    /**
     * Get an active filter list.
     *
     * @param int $contextid
     * @return array|null Null if not calculated.
     */
    public static function get_active(int $contextid): ?array {
        return self::$active[$contextid] ?? null;
    }

    /**
     * Store an active filter list.
     *
     * @param int $contextid
     * @param array $active
     */
    public static function set_active(int $contextid, array $active): void {
        self::$active[$contextid] = $active;
    }

    /**
     * Get resolved state info.
     *
     * @param int $contextid
     * @return \stdClass|null Null if not calculated.
     */
    public static function get_info(int $contextid): ?\stdClass {
        return self::$info[$contextid] ?? null;
    }

    /**
     * Store resolved state info.
     *
     * @param int $contextid
     * @param \stdClass $info
     */
    public static function set_info(int $contextid, \stdClass $info): void {
        self::$info[$contextid] = $info;
    }
}
