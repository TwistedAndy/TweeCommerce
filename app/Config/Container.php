<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Container extends BaseConfig
{

    /**
     * Default Interface -> Class or Alias -> Class or CodeIgniter Service Alias
     * Include the default CodeIgniter interfaces and bind them to services()
     *
     * @see vendor/codeigniter4/framework/system/Config/BaseService.php
     */
    public array $bindings = [
        // Request & Response
        \CodeIgniter\HTTP\ContentSecurityPolicy::class      => 'csp',
        \CodeIgniter\HTTP\RequestInterface::class           => 'request',
        \CodeIgniter\HTTP\ResponseInterface::class          => 'response',
        \CodeIgniter\HTTP\RedirectResponse::class           => 'redirectresponse',
        \CodeIgniter\HTTP\IncomingRequest::class            => 'incomingrequest',
        \CodeIgniter\HTTP\SiteURIFactory::class             => 'siteurifactory',
        \CodeIgniter\HTTP\CURLRequest::class                => 'curlrequest',
        \CodeIgniter\HTTP\CLIRequest::class                 => 'clirequest',
        \CodeIgniter\HTTP\Negotiate::class                  => 'negotiator',
        \CodeIgniter\HTTP\URI::class                        => 'uri',

        // Core Services & Routing
        \CodeIgniter\CodeIgniter::class                     => 'codeigniter',
        \CodeIgniter\CLI\Commands::class                    => 'commands',
        \CodeIgniter\Router\RouteCollectionInterface::class => 'routes',
        \CodeIgniter\Router\RouterInterface::class          => 'router',
        \CodeIgniter\Filters\FilterInterface::class         => 'filters',
        \CodeIgniter\Session\SessionInterface::class        => 'session',

        // Database & Cache
        \CodeIgniter\Pager\PagerInterface::class            => 'pager',
        \CodeIgniter\Database\MigrationRunner::class        => 'migrations',
        \CodeIgniter\Cache\CacheInterface::class            => 'cache',
        \CodeIgniter\Cache\ResponseCache::class             => 'responsecache',

        // Logging & Debugging
        \CodeIgniter\Log\Logger::class                      => 'logger',
        \CodeIgniter\Debug\Timer::class                     => 'timer',
        \CodeIgniter\Debug\Toolbar::class                   => 'toolbar',
        \CodeIgniter\Debug\Iterator::class                  => 'iterator',
        \CodeIgniter\Debug\Exceptions::class                => 'exceptions',

        // Security & Validation
        \CodeIgniter\Security\SecurityInterface::class      => 'security',
        \CodeIgniter\Throttle\ThrottlerInterface::class     => 'throttler',
        \CodeIgniter\Validation\ValidationInterface::class  => 'validation',
        \CodeIgniter\Encryption\EncrypterInterface::class   => 'encrypter',

        // View & Presentation
        \CodeIgniter\Images\ImageHandlerInterface::class    => 'image',
        \CodeIgniter\Typography\Typography::class           => 'typography',
        \CodeIgniter\Language\Language::class               => 'language',
        \CodeIgniter\Format\Format::class                   => 'format',
        \CodeIgniter\View\RendererInterface::class          => 'renderer',
        \CodeIgniter\View\View::class                       => 'renderer',
        \CodeIgniter\View\Parser::class                     => 'parser',
        \CodeIgniter\View\Cell::class                       => 'viewcell',
        \CodeIgniter\Email\Email::class                     => 'email',

        // Application Bindings
    ];

    /**
     * List of services preserved for the entire life of the PHP process
     */
    public array $singletons = [
        // Framework Bindings
        \CodeIgniter\Log\Logger::class,
        \CodeIgniter\Debug\Timer::class,
        \CodeIgniter\Debug\Toolbar::class,
        \CodeIgniter\Debug\Iterator::class,
        \CodeIgniter\Debug\Exceptions::class,
        \CodeIgniter\Pager\PagerInterface::class,
        \CodeIgniter\Database\ConnectionInterface::class,
        \CodeIgniter\Database\MigrationRunner::class,
        \CodeIgniter\Cache\CacheInterface::class,
        \CodeIgniter\Cache\ResponseCache::class,
        \CodeIgniter\Security\SecurityInterface::class,
        \CodeIgniter\Throttle\ThrottlerInterface::class,
        \CodeIgniter\Images\ImageHandlerInterface::class,
        \CodeIgniter\Typography\Typography::class,
        \CodeIgniter\View\RendererInterface::class,
        \CodeIgniter\View\Parser::class,
        \CodeIgniter\View\View::class,
        \CodeIgniter\View\Cell::class,
        \CodeIgniter\Email\Email::class,
        \CodeIgniter\Format\Format::class,

        // Application Classes
        \App\Core\Libraries\Sanitizer::class,
    ];

    /**
     * Services that act as singletons but are flushed after every request
     */
    public array $scoped = [
        // Framework classes to be recreated between requests
        \CodeIgniter\HTTP\ContentSecurityPolicy::class,
        \CodeIgniter\HTTP\ResponseInterface::class,
        \CodeIgniter\HTTP\RedirectResponse::class,
        \CodeIgniter\HTTP\RequestInterface::class,
        \CodeIgniter\HTTP\IncomingRequest::class,
        \CodeIgniter\HTTP\SiteURIFactory::class,
        \CodeIgniter\HTTP\CURLRequest::class,
        \CodeIgniter\HTTP\CLIRequest::class,
        \CodeIgniter\HTTP\Negotiate::class,
        \CodeIgniter\HTTP\URI::class,
        \CodeIgniter\CodeIgniter::class,
        \CodeIgniter\CLI\Commands::class,
        \CodeIgniter\Validation\ValidationInterface::class,
        \CodeIgniter\Router\RouteCollectionInterface::class,
        \CodeIgniter\Router\RouterInterface::class,
        \CodeIgniter\Filters\FilterInterface::class,
        \CodeIgniter\Session\SessionInterface::class,
        \CodeIgniter\Language\Language::class,

        // Application Classes
        \App\Core\Action\ActionService::class,
        \App\Core\Action\ActionModel::class,
    ];

    /**
     * Group multiple services under one tag
     */
    public array $tags = [
        // 'reports' => [\App\Actions\Pdf::class, \App\Actions\Excel::class],
    ];

    /**
     * Decorate (modify) objects after they are instantiated.
     */
    public array $extenders = [];

    public function __construct()
    {
        parent::__construct();

        $this->bindings[\CodeIgniter\Database\ConnectionInterface::class] = function () {
            return \Config\Database::connect();
        };

        /*
        $this->extenders[\App\Core\Action\ActionService::class] = [
            function ($service, $container) {
                // Logic to wrap the service
                return $service;
            }
        ];
        */

    }

}