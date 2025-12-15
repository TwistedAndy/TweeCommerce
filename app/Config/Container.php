<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Container extends BaseConfig
{
    /**
     * Map Aliases or Interfaces to Concrete Classes
     */
    public array $bindings = [

    ];

    /**
     * List of classes/aliases that should be treated as Singletons (Shared).
     * If not listed here, a new instance is created every time.
     */
    public array $singletons = [

    ];
}