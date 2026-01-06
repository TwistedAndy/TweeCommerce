<?php

namespace App\Core\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

class Container implements ContainerInterface
{
    /**
     * The Singleton instance of the Container itself
     */
    protected static ?Container $instance = null;

    /**
     * The registered type tags.
     */
    protected array $tags = [];

    /**
     * Registered bindings (Interface => Implementation)
     */
    protected array $bindings = [];

    /**
     * Cache of resolved singleton objects
     */
    protected array $instances = [];

    /**
     * Registered singletons
     */
    protected array $singletons = [];

    /**
     * The extension closures for services
     */
    protected array $extenders = [];

    /**
     * Stack of classes currently being built to detect recursion
     */
    protected array $buildStack = [];

    /**
     * Contextual bindings map
     * [ 'ParentClass' => [ 'NeedsInterface' => 'GivesConcrete' ] ]
     */
    protected array $contextual = [];

    /**
     * Cache of function/method parameters to avoid slow Reflection
     * Keys are unique identifiers for the callback (e.g. "Class::method" or Closure hash).
     */
    protected array $functionCache = [];

    /**
     * Cache of constructor parameters for classes to avoid slow Reflection
     * Structure: [ 'ClassName' => [ ['name'=>'id', 'type'=>null, ...], ... ] ]
     */
    protected array $parameterCache = [];

    /**
     * Cache of resolved class names to avoid repeated lookup logic.
     * Keys are abstract aliases, values are concrete class names.
     */
    protected array $resolutionCache = [];

    /**
     * The resolving callbacks
     */
    protected array $resolvingCallbacks = [];

    /**
     * Scoped bindings are processed as singletons that can be flushed
     */
    protected array $scopedDefinitions = [];

    /**
     * The keys of resolved scoped instances that need to be flushed.
     */
    protected array $scopedInstances = [];

    /**
     * Use the container as a singleton
     *
     * @param object|array|null $config
     */
    private function __construct($config = null)
    {
        // Immediately register this instance to prevent re-instantiation attempts
        $this->instances[static::class] = $this;

        if (is_object($config)) {
            $config = (array) $config;
        }

        if (!is_array($config)) {
            return;
        }

        // Process standard bindings
        $this->bindings = $config['bindings'] ?? [];

        // Process Singletons (Convert list to lookup keys)
        if (isset($config['singletons']) and is_array($config['singletons'])) {
            $this->singletons = array_fill_keys($config['singletons'], true);
        }

        // Process Scoped Definitions (Convert list to lookup keys)
        if (isset($config['scoped']) and is_array($config['scoped'])) {
            $this->scopedDefinitions = array_fill_keys($config['scoped'], true);
        }

        // Ensure no service is both a Singleton and Scoped
        $overlap = array_intersect_key($this->singletons, $this->scopedDefinitions);
        if (!empty($overlap)) {
            $keys = implode(', ', array_keys($overlap));
            throw new ContainerException("Conflict: Services cannot be both Singleton and Scoped: [{$keys}]");
        }

        // Process Tags
        if (isset($config['tags']) and is_array($config['tags'])) {
            foreach ($config['tags'] as $tag => $abstracts) {
                $this->tag($abstracts, $tag);
            }
        }

        // Process Extensions
        if (isset($config['extenders']) and is_array($config['extenders'])) {
            foreach ($config['extenders'] as $abstract => $closures) {
                // Normalize to array if only one closure is provided
                $closures = is_array($closures) ? $closures : [$closures];
                foreach ($closures as $closure) {
                    $this->extend($abstract, $closure);
                }
            }
        }
    }

    /**
     * Get the shared container instance
     */
    public static function getInstance($config = null): self
    {
        if (static::$instance === null) {
            if ($config === null and class_exists(\Config\Container::class)) {
                $config = new \Config\Container();
            }
            static::$instance = new static($config);
        }
        return static::$instance;
    }

    /**
     * Set the shared container instance
     *
     * @param Container|null $container
     *
     * @return void
     */
    public static function setInstance(?self $container = null): void
    {
        static::$instance = $container;
    }

    /**
     * PSR-11: Find an object by its identifier and return it
     *
     * @template TClass of object
     *
     * @param string|class-string<TClass> $id
     *
     * @return ($id is class-string<TClass> ? TClass : mixed)
     *
     * @throws NotFoundException  No entry was found for the identifier
     * @throws ContainerException Error while retrieving the entry
     */
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new NotFoundException("No entry or class found for identifier: '$id'");
        }

        try {
            return $this->make($id);
        } catch (NotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ContainerException("Error while resolving entry '$id': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * PSR-11: Check if the container can return an object by the identifier
     *
     * @param string $id
     *
     * @return bool
     */
    public function has(string $id): bool
    {
        // Check Resolution Cache first
        if (isset($this->resolutionCache[$id])) {
            return true;
        }

        // Check Singletons/Cache
        if (isset($this->instances[$id])) {
            return true;
        }

        // Check Bindings (Aliases/Interfaces)
        if (isset($this->bindings[$id])) {
            return true;
        }

        // Check Class Existence (Autowiring)
        if (class_exists($id)) {
            try {
                return (new \ReflectionClass($id))->isInstantiable();
            } catch (\ReflectionException $e) {
                // Class exists but is broken/unusable
                return false;
            }
        }

        // Check Core Service Fallback
        if (class_exists(\Config\Services::class) and method_exists(\Config\Services::class, $id)) {
            return true;
        }

        return false;
    }

    /**
     * Resolve a dependency and return an object
     *
     * @template TClass of object
     *
     * @param string|class-string<TClass> $abstract
     * @param array $parameters
     * @param string $context A calling class name for contextual binding
     *
     * @return ($abstract is class-string<TClass> ? TClass : mixed)
     *
     * @throws ContainerException
     */
    public function make(string $abstract, array $parameters = [], string $context = '')
    {
        if (isset($this->instances[$abstract]) and empty($parameters) and ($context === '' or !isset($this->contextual[$context][$abstract]))) {
            return $this->instances[$abstract];
        }

        if ($context === '' and isset($this->resolutionCache[$abstract])) {
            $concrete = $this->resolutionCache[$abstract];
        } else {
            $concrete = $this->resolveConcrete($abstract, $context);
            if ($context === '' and is_string($concrete) and empty($parameters)) {
                $this->resolutionCache[$abstract] = $concrete;
            }
        }

        $isStringConcrete = is_string($concrete);

        // Concrete Instance Check (e.g. Interface -> Alias -> Existing Singleton)
        if ($isStringConcrete and isset($this->instances[$concrete])) {
            if ($context === '' and $abstract !== $concrete) {
                $this->instances[$abstract] = $this->instances[$concrete];
            }
            return $this->instances[$concrete];
        }

        // Build with Recursion Protection
        $stackKey = $isStringConcrete ? $concrete : $abstract;

        // Detect circular dependencies using the normalized key
        if (isset($this->buildStack[$stackKey])) {
            throw new ContainerException("Circular dependency detected: " . implode(' -> ', array_keys($this->buildStack)) . " -> $stackKey");
        }

        $this->buildStack[$stackKey] = true;

        try {
            $object = $this->build($concrete, $parameters);
        } finally {
            unset($this->buildStack[$stackKey]); // Always remove from stack, even if build fails
        }

        // Apply Extenders (Decorators) to swap the instance (e.g. wrapping in a Proxy)
        if (isset($this->extenders[$abstract])) {
            foreach ($this->extenders[$abstract] as $extender) {
                // The extender receives the current object and returns a NEW one
                $object = $extender($object, $this);
            }
        }

        // Check if Shared (Singleton) OR Scoped
        $isShared = (isset($this->singletons[$abstract]) or ($isStringConcrete and isset($this->singletons[$concrete])));
        $isScoped = (isset($this->scopedDefinitions[$abstract]) or ($isStringConcrete and isset($this->scopedDefinitions[$concrete])));

        if ($isShared or $isScoped) {
            if ($isStringConcrete) {
                $this->instances[$concrete] = $object;
                if ($isScoped) {
                    $this->scopedInstances[$concrete] = true;
                }
            }

            if ($context === '') {
                $this->instances[$abstract] = $object;
                if ($isScoped) {
                    $this->scopedInstances[$abstract] = true;
                }
            }
        }

        $className = $object::class;

        // Handle the resolving callbacks for an abstract
        if (isset($this->resolvingCallbacks[$abstract])) {
            foreach ($this->resolvingCallbacks[$abstract] as $callback) {
                $callback($object, $this);
            }
        }

        // Handle the resolving callbacks for a concrete class
        if ($className !== $abstract and isset($this->resolvingCallbacks[$className])) {
            foreach ($this->resolvingCallbacks[$className] as $callback) {
                $callback($object, $this);
            }
        }

        return $object;
    }

    /**
     * Call the given callback/method and inject its dependencies
     * Matches Laravel's: $container->call([$object, 'method'], ['param' => 123]);
     *
     * @param callable|string|array $callback Callback
     * @param array $parameters               Named parameters to override
     * @param string|null $defaultMethod      Method to call if $callback is just a class name
     *
     * @return mixed
     */
    public function call(mixed $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        $parsedClass  = null;
        $parsedMethod = null;
        $reflector    = null;

        if (is_string($callback)) {
            $callbackKey = $callback;
            if (!function_exists($callback)) {
                if (str_contains($callback, '@')) {
                    [$parsedClass, $parsedMethod] = explode('@', $callback);
                } elseif (str_contains($callback, '::')) {
                    [$parsedClass, $parsedMethod] = explode('::', $callback);
                } elseif (class_exists($callback)) {
                    $parsedClass  = $callback;
                    $parsedMethod = $defaultMethod ?? '__invoke';
                }
            }
        } else {
            $callbackKey = $this->getCallbackKey($callback);

            if (is_array($callback) and isset($callback[0]) and is_string($callback[0])) {
                $parsedClass  = $callback[0];
                $parsedMethod = $callback[1] ?? '__invoke';
            }
        }

        // Check function cache or use a reflection
        if ($callbackKey and isset($this->functionCache[$callbackKey])) {
            $dependencies = $this->functionCache[$callbackKey];
            $contextName  = $callbackKey;
        } else {
            try {
                if ($parsedClass) {
                    $reflector   = new \ReflectionMethod($parsedClass, $parsedMethod);
                    $contextName = $callbackKey ? : ($parsedClass . '::' . $parsedMethod);
                } elseif (is_string($callback) and function_exists($callback)) {
                    $reflector   = new \ReflectionFunction($callback);
                    $contextName = $callback;
                } elseif (is_array($callback)) {
                    $reflector   = new \ReflectionMethod($callback[0], $callback[1]);
                    $contextName = $callbackKey;
                } elseif ($callback instanceof \Closure) {
                    $reflector   = new \ReflectionFunction($callback);
                    $contextName = 'Closure';
                } elseif (is_object($callback)) {
                    $reflector   = new \ReflectionMethod($callback, '__invoke');
                    $contextName = $callbackKey;
                } else {
                    throw new ContainerException('Invalid callback provided to call(): ' . serialize($callback));
                }
            } catch (ReflectionException $e) {
                throw new ContainerException('Failed to reflect on callback: ' . $e->getMessage());
            }

            $dependencies = $this->getReflectorParameters($reflector);

            if ($callbackKey) {
                $this->functionCache[$callbackKey] = $dependencies;
            }
        }

        $instances = $this->resolveDependencies($contextName, $dependencies, $parameters);

        if ($parsedClass) {
            $needsInstantiation = true;

            if (!$reflector) {
                try {
                    $reflector = new \ReflectionMethod($parsedClass, $parsedMethod);
                } catch (ReflectionException $e) {
                    throw new ContainerException('Failed to reflect on callback: ' . $e->getMessage());
                }
            }

            if ($reflector->isStatic()) {
                $needsInstantiation = false;
                $callback           = [$parsedClass, $parsedMethod];
            }

            if ($needsInstantiation) {
                $callback = [$this->make($parsedClass), $parsedMethod];
            }
        }

        if (is_array($callback)) {
            return call_user_func_array($callback, $instances);
        }

        return $callback(...$instances);
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @template TInstance of mixed
     *
     * @param string $abstract
     * @param TInstance $instance
     *
     * @return TInstance
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        // If replacing an existing object instance, remove its class-key mapping
        if (isset($this->instances[$abstract]) and is_object($this->instances[$abstract])) {
            $old = $this->instances[$abstract];
            if (isset($this->instances[$old::class]) and $this->instances[$old::class] === $old) {
                unset($this->instances[$old::class]);
            }
        }

        // Clear stale cache
        unset($this->scopedInstances[$abstract]);

        $this->resolutionCache = [];

        $this->instances[$abstract] = $instance;

        if (is_object($instance)) {
            $this->instances[$instance::class] = $instance;

            // Pre-fill the resolution cache since we know the class
            $this->resolutionCache[$abstract] = $instance::class;
        }

        return $instance;
    }

    /**
     * Instantiate a concrete instance of the given type
     *
     * @template TClass of object
     *
     * @param string|callable $concrete
     * @param array $parameters
     *
     * @return TClass
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function build(string|callable $concrete, array $parameters): object
    {
        if (is_callable($concrete)) {
            if ($concrete instanceof \Closure) {
                return $concrete($this, $parameters);
            }

            return $concrete(...array_values($parameters));
        }

        if (!class_exists($concrete)) {
            // If it's not a class, it might be a service alias or a dynamic service
            $service = \service($concrete, ...array_values($parameters));

            if (is_object($service)) {
                return $service;
            }

            if (interface_exists($concrete)) {
                $message = "Service Resolution Failed: Interface '$concrete' is not bound to any implementation.";
            } else {
                $message = "Service Resolution Failed: Class '$concrete' not found.";
            }

            throw new NotFoundException($message);
        }

        // Reflect the class once and cache the parameters
        if (!isset($this->parameterCache[$concrete])) {
            $reflector = new \ReflectionClass($concrete);

            if (!$reflector->isInstantiable()) {
                throw new ContainerException("Service Resolution Failed: Class '$concrete' is not instantiable.");
            }

            $constructor = $reflector->getConstructor();

            if ($constructor === null) {
                $this->parameterCache[$concrete] = [];
                return new $concrete();
            }

            $this->parameterCache[$concrete] = $this->getReflectorParameters($constructor);
        }

        $cachedParams = $this->parameterCache[$concrete];

        // Skip the reflection entirely if there are no constructor arguments
        if (empty($cachedParams)) {
            return new $concrete();
        }

        $args = $this->resolveDependencies($concrete, $cachedParams, $parameters);

        try {
            return new $concrete(...$args);
        } catch (\Error $e) {
            // Try reflection to bypass visibility or get a better error message.
            try {
                return (new ReflectionClass($concrete))->newInstanceArgs($args);
            } catch (ReflectionException $re) {
                throw new ContainerException("Container failed to instantiate $concrete: " . $re->getMessage(), 0, $re);
            }
        }
    }

    /**
     * Register a binding dynamically
     *
     * @param string $abstract               Interface or Alias (e.g. EntityInterface::class)
     * @param string|callable|null $concrete Concrete Class (e.g. Entity::class)
     *
     * @param bool $shared                   Whether to treat as Singleton
     */
    public function bind(string $abstract, string|callable|null $concrete = null, bool $shared = false): void
    {
        // Clear stale cache
        unset(
            $this->instances[$abstract],
            $this->scopedInstances[$abstract]
        );

        $this->resolutionCache = [];

        // Self-binding
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // Prevent infinite loops in make() alias resolution
        if ($abstract === $concrete) {
            unset($this->bindings[$abstract]);
        } else {
            $this->bindings[$abstract] = $concrete;
        }

        if ($shared) {
            $this->singletons[$abstract] = true;
            if (is_string($concrete)) {
                $this->singletons[$concrete] = true;
            }
        }
    }

    /**
     * Define a contextual binding: when class {$class} asks for {$needs}, give it {$give}
     *
     * @param string $class         The Class Name that needs the dependency
     * @param string $needs         The Interface/Class dependency needed
     * @param string|callable $give The Concrete implementation to provide
     */
    public function bindWhen(string $class, string $needs, string|callable $give): void
    {
        if (!isset($this->contextual[$class])) {
            $this->contextual[$class] = [];
        }
        $this->contextual[$class][$needs] = $give;
    }

    /**
     * Register a binding if it hasn't already been registered
     *
     * @param string $abstract
     * @param string|callable|null $concrete
     * @param bool $shared
     *
     * @return void
     */
    public function bindIf(string $abstract, string|callable|null $concrete = null, bool $shared = false): void
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Determine if the given abstract type has been bound
     *
     * @param string $abstract
     *
     * @return bool
     */
    public function bound(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) or isset($this->instances[$abstract]) or isset($this->singletons[$abstract]);
    }

    /**
     * Register a shared binding (Singleton)
     */
    public function singleton(string $abstract, string|callable|null $concrete): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register a shared binding if it hasn't already been registered
     *
     * @param string $abstract
     * @param string|callable|null $concrete
     *
     * @return void
     */
    public function singletonIf(string $abstract, string|callable|null $concrete = null): void
    {
        if (!$this->bound($abstract)) {
            $this->singleton($abstract, $concrete);
        }
    }

    /**
     * Register a scoped binding in the container
     * Scoped instances are singletons that are flushed on every requests
     *
     * @param string $abstract
     * @param string|callable|null $concrete
     *
     * @return void
     */
    public function scoped(string $abstract, string|callable|null $concrete = null): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // Bind it normally (not as a global singleton)
        $this->bind($abstract, $concrete, false);

        // Mark it as scoped
        $this->scopedDefinitions[$abstract] = true;

        if (is_string($concrete)) {
            $this->scopedDefinitions[$concrete] = true;
        }
    }

    /**
     * Register a scoped binding if it hasn't already been registered
     */
    public function scopedIf(string $abstract, string|callable|null $concrete = null): void
    {
        if (!$this->bound($abstract)) {
            $this->scoped($abstract, $concrete);
        }
    }

    /**
     * Extend (decorate) an abstract type in the container
     *
     * @param string $abstract
     * @param callable $closure
     *
     * @return void
     */
    public function extend(string $abstract, callable $closure): void
    {
        if (!isset($this->extenders[$abstract])) {
            $this->extenders[$abstract] = [];
        }

        $this->resolutionCache = [];

        $this->extenders[$abstract][] = $closure;

        // Forget already created singletons, so they will be rebuilt next time
        $concrete = $abstract;
        while (is_string($concrete) and isset($this->bindings[$concrete])) {
            $concrete = $this->bindings[$concrete];
        }

        // Clear the abstract singleton
        if (isset($this->instances[$abstract])) {

            if (is_object($this->instances[$abstract])) {
                unset($this->instances[$this->instances[$abstract]::class]);
            }

            unset($this->instances[$abstract]);
        }

        // Clear the concrete singleton
        if (is_string($concrete) and isset($this->instances[$concrete])) {
            unset($this->instances[$concrete]);
        }
    }

    /**
     * Assign a set of tags to a given binding
     *
     * @param array|string $abstracts
     * @param array|string $tags
     *
     * @return void
     */
    public function tag(array|string $abstracts, array|string $tags): void
    {
        if (!is_array($tags)) {
            $tags = array_slice(func_get_args(), 1);
        }

        if (!is_array($abstracts)) {
            $abstracts = [$abstracts];
        }

        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ($abstracts as $abstract) {
                $this->tags[$tag][$abstract] = true;
            }
        }
    }

    /**
     * Return an array of instantiated objects for a tag
     *
     * @param string $tag
     *
     * @return array
     */
    public function tagged(string $tag): array
    {
        $results = [];

        if (isset($this->tags[$tag])) {
            foreach (array_keys($this->tags[$tag]) as $abstract) {
                $results[] = $this->make($abstract);
            }
        }

        return $results;
    }

    /**
     * Register a callback to be run after creating an object
     *
     * @param string $abstract
     * @param callable $callback
     *
     * @return void
     */
    public function resolving(string $abstract, callable $callback): void
    {
        if (!isset($this->resolvingCallbacks[$abstract])) {
            $this->resolvingCallbacks[$abstract] = [];
        }

        $this->resolvingCallbacks[$abstract][] = $callback;
    }

    /**
     * Flush the container and all the caches
     */
    public function flush(): void
    {
        $this->tags       = [];
        $this->bindings   = [];
        $this->instances  = [];
        $this->singletons = [];
        $this->buildStack = [];
        $this->contextual = [];

        $this->scopedInstances   = [];
        $this->scopedDefinitions = [];

        $this->functionCache   = [];
        $this->parameterCache  = [];
        $this->resolutionCache = [];

        $this->instances[static::class] = $this;
    }

    /**
     * Remove a resolved instance from the instance cache
     *
     * @param string $abstract
     *
     * @return void
     */
    public function forgetInstance(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    /**
     * Clear all of the instances from the container
     *
     * @return void
     */
    public function forgetInstances(): void
    {
        $this->instances = [static::class => $this];
    }

    /**
     * Flush all scoped instances from the container
     *
     * @return void
     */
    public function forgetScopedInstances(): void
    {
        foreach ($this->scopedDefinitions as $abstract => $status) {
            unset($this->instances[$abstract]);
        }

        foreach ($this->scopedInstances as $abstract => $status) {
            unset($this->instances[$abstract]);
        }

        // Reset the tracker
        $this->scopedInstances = [];
        $this->resolutionCache = [];
    }

    /**
     * Generates a persistent key for a callback
     *
     * @param callable|string|object|array $callback
     *
     * @return string|null
     */
    public function getCallbackKey($callback): string|null
    {
        if (is_array($callback)) {
            if (isset($callback[0]) and isset($callback[1])) {
                return (is_object($callback[0]) ? get_class($callback[0]) : $callback[0]) . '::' . $callback[1];
            }

            throw new ContainerException('Invalid callback arguments: ' . serialize($callback));
        }

        if ($callback instanceof \Closure) {
            try {
                $reflector = new \ReflectionFunction($callback);
            } catch (\ReflectionException $e) {
                throw new ContainerException('Failed to reflect on callback: ' . $e->getMessage());
            }

            $file = $reflector->getFileName();

            // Do not cache serialized closures because they share the same file and line number
            if (str_contains($file, "eval()'d code")) {
                return null;
            }

            if (defined('ROOTPATH')) {
                $file = str_replace(ROOTPATH, '', $file);
            }

            return 'closure_' . $file . ':' . $reflector->getStartLine();
        }

        if (is_string($callback)) {
            return $callback;
        }

        if (is_object($callback)) {
            return get_class($callback) . '::__invoke';
        }

        return null;
    }

    /**
     * Resolve the concrete class name for an abstract/alias
     *
     * @param string $abstract
     * @param array $parameters
     * @param string $context
     *
     * @return string
     */
    public function getConcreteClass(string $abstract, array $parameters = [], string $context = ''): string
    {
        // Check the class resolution cache first
        if ($context === '' and isset($this->resolutionCache[$abstract])) {
            return $this->resolutionCache[$abstract];
        }

        $isCacheable = ($context === '' and empty($parameters));

        // If we have an object, we definitely know the class
        if (isset($this->instances[$abstract]) and ($context === '' or !isset($this->contextual[$context][$abstract]))) {
            $class = $this->instances[$abstract]::class;

            if ($isCacheable) {
                $this->resolutionCache[$abstract] = $class;
            }
            return $class;
        }

        $concrete = $this->resolveConcrete($abstract, $context);

        // Return the string if it resolves to an actual class
        if (is_string($concrete) and class_exists($concrete)) {
            if ($isCacheable) {
                $this->resolutionCache[$abstract] = $concrete;
            }
            return $concrete;
        }

        // If $concrete is a closure or an interface bound to a factory, we must build it to know the class
        $object = $this->make($abstract, $parameters, $context);

        $class = $object::class;

        // Cache the class name for next time
        if ($isCacheable) {
            $this->resolutionCache[$abstract] = $class;
        }

        return $class;
    }

    /**
     * Extract parameter details from any function or method
     *
     * @param ReflectionFunctionAbstract $reflector
     *
     * @return array
     */
    protected function getReflectorParameters(ReflectionFunctionAbstract $reflector): array
    {
        $params = [];

        foreach ($reflector->getParameters() as $param) {
            $type         = $param->getType();
            $resolvedType = null;
            $hasBuiltin   = false;

            if ($type instanceof ReflectionNamedType) {
                // Standard: Single Type
                if (!$type->isBuiltin()) {
                    $resolvedType = $type->getName();
                } else {
                    $hasBuiltin = true;
                }
            } elseif ($type instanceof \ReflectionUnionType) {
                // Union: Collect all valid classes
                $candidates = [];
                foreach ($type->getTypes() as $unionType) {
                    if ($unionType instanceof ReflectionNamedType) {
                        if (!$unionType->isBuiltin()) {
                            $candidates[] = $unionType->getName();
                        } else {
                            $hasBuiltin = true;
                        }
                    }
                }

                $count = count($candidates);

                // If there's only one union type available, store it as a string
                if ($count === 1) {
                    $resolvedType = $candidates[0];
                } elseif ($count > 1) {
                    $resolvedType = $candidates;
                }
            } elseif ($type instanceof \ReflectionIntersectionType) {
                // Intersection types cannot be easily auto-resolved.
                // We treat them as unresolvable/builtin to force manual binding or fail gracefully.
                $hasBuiltin = true;
            }

            $params[] = [
                'name'        => $param->getName(),
                'type_name'   => $resolvedType,
                'is_optional' => $param->isDefaultValueAvailable(),
                'default'     => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                'builtin'     => $hasBuiltin,
                'nullable'    => $param->allowsNull(),
                'variadic'    => $param->isVariadic(),
            ];
        }

        return $params;
    }

    /**
     * Resolve class dependencies and return them as an array of arguments
     *
     * @param string $className
     * @param array $dependencies
     * @param array $parameters
     *
     * @return array
     */
    protected function resolveDependencies(string $className, array $dependencies, array $parameters): array
    {
        $results      = [];
        $numericIndex = 0; // Cursor for positional arguments (0, 1, 2...)

        $contextKey = $className;

        if (!isset($this->contextual[$className])) {
            if (str_contains($className, '::')) {
                $parts = explode('::', $className, 2);
                if (isset($this->contextual[$parts[0]])) {
                    $contextKey = $parts[0];
                }
            } elseif (str_contains($className, '@')) {
                $parts = explode('@', $className, 2);
                if (isset($this->contextual[$parts[0]])) {
                    $contextKey = $parts[0];
                }
            }
        }

        foreach ($dependencies as $dep) {

            $name = $dep['name'];
            $type = $dep['type_name'];

            // Named Parameters
            if (array_key_exists($name, $parameters)) {
                $results[] = $parameters[$name];

                // Skip a positional value if it exists at the current cursor, so it doesn't accidentally shift to the next argument
                if (array_key_exists($numericIndex, $parameters)) {
                    $numericIndex++;
                }
                continue;
            }

            // Variadic check must run before Class/Positional checks because it consumes multiple arguments.
            if ($dep['variadic']) {
                // Manual Parameters passed to make(). If the user manually provided args, consume ALL remaining positional args.
                if (array_key_exists($numericIndex, $parameters)) {
                    while (array_key_exists($numericIndex, $parameters)) {
                        $results[] = $parameters[$numericIndex];
                        $numericIndex++;
                    }
                    continue;
                }

                continue;
            }

            // Class Dependency (Auto-wiring)
            if ($type !== null) {

                if (is_string($type)) {
                    // If the passed parameter at $numericIndex is an instance of the required type, use it!
                    if (array_key_exists($numericIndex, $parameters) and ($parameters[$numericIndex] instanceof $type or $dep['builtin'])) {
                        $results[] = $parameters[$numericIndex];
                        $numericIndex++;
                        continue;
                    }

                    // Resolution
                    try {
                        $results[] = $this->make($type, [], $contextKey);
                    } catch (\Throwable $e) {
                        if ($dep['nullable']) {
                            $results[] = null;
                        } else {
                            throw $e;
                        }
                    }
                    continue;
                }

                // Process multiple union types (e.g. Logger|FileLogger)
                if (is_array($type)) {
                    $resolved = false;

                    // Check passed parameters against ANY of the union types
                    if (array_key_exists($numericIndex, $parameters)) {
                        $passed  = $parameters[$numericIndex];
                        $isMatch = $dep['builtin'];

                        if (!$isMatch) {
                            foreach ($type as $candidate) {
                                if ($passed instanceof $candidate) {
                                    $isMatch = true;
                                    break;
                                }
                            }
                        }

                        if ($isMatch) {
                            $results[] = $passed;
                            $numericIndex++;
                            $resolved = true;
                        }
                    }

                    // Attempt resolution of each candidate
                    if (!$resolved) {
                        foreach ($type as $candidate) {
                            try {
                                $results[] = $this->make($candidate, [], $contextKey);
                                $resolved  = true;
                                break;
                            } catch (\Throwable $e) {
                                // Continue to next candidate
                            }
                        }
                    }

                    if ($resolved) {
                        continue;
                    }

                    // Fail or Nullable
                    if ($dep['nullable']) {
                        $results[] = null;
                        continue;
                    }

                    throw new ContainerException("Unresolvable dependency: Parameter '\${$name}' in '{$className}' could not be resolved. Tried: " . implode('|', $type));
                }
            }

            // Positional Parameters
            if (array_key_exists($numericIndex, $parameters)) {
                $results[] = $parameters[$numericIndex];
                $numericIndex++; // Move cursor to the next argument
                continue;
            }

            // Defaults
            if ($dep['is_optional']) {
                $results[] = $dep['default'];
            } else {
                throw new ContainerException("Unresolvable dependency: Parameter '\${$name}' in class '{$className}' is missing a value.");
            }
        }

        return $results;
    }

    /**
     * Resolve the concrete type for a given abstract alias
     *
     * @param string $abstract
     * @param string $context
     *
     * @return string|callable
     * @throws ContainerException
     */
    protected function resolveConcrete(string $abstract, string $context = ''): string|callable
    {
        // Contextual Binding
        if ($context and isset($this->contextual[$context][$abstract])) {
            $concrete = $this->contextual[$context][$abstract];
            if (is_string($concrete) and isset($this->bindings[$concrete])) {
                return $this->resolveConcrete($concrete, $context);
            }
            return $concrete;
        }

        // Global Binding
        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];
        } else {
            return $abstract;
        }

        // Alias Binding
        $chain = [];

        while (is_string($concrete) and isset($this->bindings[$concrete])) {
            if (isset($chain[$concrete])) {
                throw new ContainerException("Circular binding detected: " . implode(' -> ', array_keys($chain)) . " -> $concrete");
            }
            $chain[$concrete] = true;
            $concrete         = $this->bindings[$concrete];
        }

        return $concrete;
    }
}