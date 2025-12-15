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

use App\Core\App;

if (!function_exists('app')) {
    /**
     * Resolve a dependency from the container.
     * * Usage:
     * 1. Get Object: resolve(MyClass::class)
     * 2. Get Container (to bind): resolve()->bind(...)
     * * @template T
     *
     * @param string|class-string<T>|null $id
     * @param array $params
     *
     * @return T|App
     */
    function app(?string $id = null, array $params = [])
    {
        static $container;

        if ($container === null) {
            $container = \App\Core\App::getInstance();
        }

        if ($id === null) {
            return $container;
        }

        return $container->make($id, $params);
    }
}