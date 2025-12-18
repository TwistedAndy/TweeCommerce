<?php

namespace PHPSTORM_META {

    override(\app(0), map([
        'autoloader'       => \CodeIgniter\Autoloader\Autoloader::class,
        'cache'            => \CodeIgniter\Cache\CacheInterface::class,
        'clirequest'       => \CodeIgniter\HTTP\CLIRequest::class,
        'codeigniter'      => \CodeIgniter\CodeIgniter::class,
        'commands'         => \CodeIgniter\CLI\Commands::class,
        'csp'              => \CodeIgniter\HTTP\ContentSecurityPolicy::class,
        'curlrequest'      => \CodeIgniter\HTTP\CURLRequest::class,
        'email'            => \CodeIgniter\Email\Email::class,
        'encrypter'        => \CodeIgniter\Encryption\EncrypterInterface::class,
        'exceptions'       => \CodeIgniter\Debug\Exceptions::class,
        'filters'          => \CodeIgniter\Filters\Filters::class,
        'format'           => \CodeIgniter\Format\Format::class,
        'honeypot'         => \CodeIgniter\Honeypot\Honeypot::class,
        'image'            => \CodeIgniter\Images\Handlers\BaseHandler::class,
        'iterator'         => \CodeIgniter\Debug\Iterator::class,
        'language'         => \CodeIgniter\Language\Language::class,
        'locator'          => \CodeIgniter\Autoloader\FileLocator::class,
        'logger'           => \CodeIgniter\Log\Logger::class,
        'migrations'       => \CodeIgniter\Database\MigrationRunner::class,
        'negotiator'       => \CodeIgniter\HTTP\Negotiate::class,
        'pager'            => \CodeIgniter\Pager\Pager::class,
        'parser'           => \CodeIgniter\View\Parser::class,
        'redirectresponse' => \CodeIgniter\HTTP\RedirectResponse::class,
        'renderer'         => \CodeIgniter\View\View::class,
        'request'          => \CodeIgniter\HTTP\IncomingRequest::class,
        'response'         => \CodeIgniter\HTTP\Response::class,
        'router'           => \CodeIgniter\Router\Router::class,
        'routes'           => \CodeIgniter\Router\RouteCollection::class,
        'security'         => \CodeIgniter\Security\Security::class,
        'session'          => \CodeIgniter\Session\Session::class,
        'throttler'        => \CodeIgniter\Throttle\Throttler::class,
        'timer'            => \CodeIgniter\Debug\Timer::class,
        'toolbar'          => \CodeIgniter\Debug\Toolbar::class,
        'typography'       => \CodeIgniter\Typography\Typography::class,
        'uri'              => \CodeIgniter\HTTP\URI::class,
        'validation'       => \CodeIgniter\Validation\Validation::class,
        'viewcell'         => \CodeIgniter\View\Cell::class,
        ''                 => '@',
    ]));

    override(\service(0), map([
        'autoloader'       => \CodeIgniter\Autoloader\Autoloader::class,
        'cache'            => \CodeIgniter\Cache\CacheInterface::class,
        'clirequest'       => \CodeIgniter\HTTP\CLIRequest::class,
        'codeigniter'      => \CodeIgniter\CodeIgniter::class,
        'commands'         => \CodeIgniter\CLI\Commands::class,
        'csp'              => \CodeIgniter\HTTP\ContentSecurityPolicy::class,
        'curlrequest'      => \CodeIgniter\HTTP\CURLRequest::class,
        'email'            => \CodeIgniter\Email\Email::class,
        'encrypter'        => \CodeIgniter\Encryption\EncrypterInterface::class,
        'exceptions'       => \CodeIgniter\Debug\Exceptions::class,
        'filters'          => \CodeIgniter\Filters\Filters::class,
        'format'           => \CodeIgniter\Format\Format::class,
        'honeypot'         => \CodeIgniter\Honeypot\Honeypot::class,
        'image'            => \CodeIgniter\Images\Handlers\BaseHandler::class,
        'iterator'         => \CodeIgniter\Debug\Iterator::class,
        'language'         => \CodeIgniter\Language\Language::class,
        'locator'          => \CodeIgniter\Autoloader\FileLocator::class,
        'logger'           => \CodeIgniter\Log\Logger::class,
        'migrations'       => \CodeIgniter\Database\MigrationRunner::class,
        'negotiator'       => \CodeIgniter\HTTP\Negotiate::class,
        'pager'            => \CodeIgniter\Pager\Pager::class,
        'parser'           => \CodeIgniter\View\Parser::class,
        'redirectresponse' => \CodeIgniter\HTTP\RedirectResponse::class,
        'renderer'         => \CodeIgniter\View\View::class,
        'request'          => \CodeIgniter\HTTP\IncomingRequest::class,
        'response'         => \CodeIgniter\HTTP\Response::class,
        'router'           => \CodeIgniter\Router\Router::class,
        'routes'           => \CodeIgniter\Router\RouteCollection::class,
        'security'         => \CodeIgniter\Security\Security::class,
        'session'          => \CodeIgniter\Session\Session::class,
        'throttler'        => \CodeIgniter\Throttle\Throttler::class,
        'timer'            => \CodeIgniter\Debug\Timer::class,
        'toolbar'          => \CodeIgniter\Debug\Toolbar::class,
        'typography'       => \CodeIgniter\Typography\Typography::class,
        'uri'              => \CodeIgniter\HTTP\URI::class,
        'validation'       => \CodeIgniter\Validation\Validation::class,
        'viewcell'         => \CodeIgniter\View\Cell::class,
        ''                 => '@',
    ]));

    override(\config(0), map([
        'App'                   => \Config\App::class,
        'Autoload'              => \Config\Autoload::class,
        'Cache'                 => \Config\Cache::class,
        'ContentSecurityPolicy' => \Config\ContentSecurityPolicy::class,
        'Cookie'                => \Config\Cookie::class,
        'CURLRequest'           => \Config\CURLRequest::class,
        'Database'              => \Config\Database::class,
        'DocTypes'              => \Config\DocTypes::class,
        'Email'                 => \Config\Email::class,
        'Encryption'            => \Config\Encryption::class,
        'Exceptions'            => \Config\Exceptions::class,
        'Feature'               => \Config\Feature::class,
        'Filters'               => \Config\Filters::class,
        'ForeignCharacters'     => \Config\ForeignCharacters::class,
        'Format'                => \Config\Format::class,
        'Generators'            => \Config\Generators::class,
        'Honeypot'              => \Config\Honeypot::class,
        'Images'                => \Config\Images::class,
        'Kint'                  => \Config\Kint::class,
        'Logger'                => \Config\Logger::class,
        'Migrations'            => \Config\Migrations::class,
        'Mimes'                 => \Config\Mimes::class,
        'Modules'               => \Config\Modules::class,
        'Pager'                 => \Config\Pager::class,
        'Publisher'             => \Config\Publisher::class,
        'Security'              => \Config\Security::class,
        'Services'              => \Config\Services::class,
        'Toolbar'               => \Config\Toolbar::class,
        'UserAgents'            => \Config\UserAgents::class,
        'Validation'            => \Config\Validation::class,
        'View'                  => \Config\View::class,
    ]));

    override(\model(0), map([
        '' => '@',
    ]));

    override(\cache(), map([
        '' => \CodeIgniter\Cache\CacheInterface::class,
    ]));

    // Database Connection
    override(\db_connect(), map([
        '' => \CodeIgniter\Database\BaseConnection::class,
    ]));

    // Database Builder: db_connect()->table('users')
    override(\CodeIgniter\Database\BaseConnection::table(), map([
        '' => \CodeIgniter\Database\BaseBuilder::class,
    ]));

    // Cookies
    override(\cookies(), map([
        '' => \CodeIgniter\Cookie\CookieStore::class,
    ]));
    override(\get_cookie(), map([
        '' => \CodeIgniter\Cookie\Cookie::class,
    ]));

    // Session
    override(\session(), map([
        '' => \CodeIgniter\Session\Session::class,
    ]));

    // Responses & Requests
    override(\request(), map([
        '' => \CodeIgniter\HTTP\IncomingRequest::class,
    ]));
    override(\response(), map([
        '' => \CodeIgniter\HTTP\Response::class,
    ]));
    override(\redirect(), map([
        '' => \CodeIgniter\HTTP\RedirectResponse::class,
    ]));

    // Testing Fabricator
    override(\fabricator(), map([
        '' => \CodeIgniter\Test\Fabricator::class,
    ]));

}
