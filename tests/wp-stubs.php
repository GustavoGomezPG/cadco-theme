<?php

/**
 * The few WordPress functions an import unit calls at load time, stubbed so
 * the unit can be required without WordPress.
 *
 * Load-time only: anything a test actually exercises must not depend on
 * these doing real work. They are defined only when absent, so this file is
 * harmless if WordPress ever is loaded.
 */

declare(strict_types=1);

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}
