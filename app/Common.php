<?php

/**
 * The goal of this file is to allow developers a location
 * where they can overwrite core procedural functions and
 * replace them with their own. This file is loaded during
 * the bootstrap process and is called during the framework's
 * execution.
 *
 * This can be looked at as a `master helper` file that is
 * loaded early on, and may also contain additional functions
 * that you'd like to use throughout your entire application
 *
 * @see: https://codeigniter.com/user_guide/extending/common.html
 */

use App\Core\Container\Container;

if (!function_exists('app')) {
    /**
     * Resolve a dependency from the container.
     *
     * @param string|null $id
     * @param array $params
     *
     * @return mixed|Container
     */
    function app(?string $id = null, array $params = [])
    {
        static $container;

        if ($container === null) {
            $container = Container::getInstance();
        }

        if ($id === null) {
            return $container;
        }

        return $container->make($id, $params);
    }
}

if (!function_exists('__')) {
    /**
     * Translate a string
     *
     * @param string $name
     * @param string $domain
     *
     * @return string
     */
    function __(string $name, string $domain = 'system'): string
    {
        return $name;
    }
}