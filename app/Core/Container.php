<?php

namespace App\Core;

use App\Exceptions\ContainerException;
use App\Exceptions\NotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionException;
use ReflectionClass;

class Container implements ContainerInterface
{
    /**
     * The Singleton instance of the Container itself
     */
    protected static ?Container $instance = null;

    /**
     * Registered bindings (Interface => Implementation)
     */
    protected array $bindings = [];

    /**
     * Cache of resolved singleton objects
     */
    protected array $instances = [];

    /**
     * Registered singletons.
     */
    protected array $singletons = [];

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
     * Use the container as a singleton
     *
     * @param object|array|null $config
     */
    private function __construct($config = null)
    {
        if (is_object($config)) {
            $this->bindings = $config->bindings ?? [];
            $this->singletons = array_fill_keys($config->singletons ?? [], true);
        } elseif (is_array($config)) {
            $this->bindings = $config['bindings'] ?? [];
            $this->singletons = array_fill_keys($config['singletons'] ?? [], true);
        }
    }

    /**
     * Get the shared container instance
     */
    public static function getInstance($config = null): Container
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
    public static function setInstance(Container $container = null): void
    {
        static::$instance = $container;
    }

    /**
     * Flush the container and all the caches
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->singletons = [];
        $this->buildStack = [];
        $this->contextual = [];

        $this->functionCache = [];
        $this->parameterCache = [];
        $this->resolutionCache = [];
    }

    /**
     * PSR-11: Finds an entry of the container by its identifier and returns it
     *
     * @param string $id Identifier of the entry to look for
     *
     * @return mixed
     *
     * @throws NotFoundException  No entry was found for **this** identifier.
     * @throws ContainerException Error while retrieving the entry.
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
     * PSR-11: Check if the container can return an entry for the given identifier
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
            return true;
        }

        // Check Core Service Fallback
        if (class_exists(\Config\Services::class) and method_exists(\Config\Services::class, $id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the given abstract type has been bound.
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
     * Register a binding dynamically (Used by Plugins).
     *
     * @param string $abstract               Interface or Alias (e.g. EntityInterface::class)
     * @param string|callable|null $concrete Concrete Class (e.g. Entity::class)
     *
     * @param bool $shared                   Whether to treat as Singleton
     */
    public function bind(string $abstract, string|callable|null $concrete = null, bool $shared = false): void
    {
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
     * Register a binding if it hasn't already been registered.
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
     * Register a shared binding (Singleton).
     */
    public function singleton(string $abstract, string|callable|null $concrete): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register a shared binding if it hasn't already been registered.
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
        $this->instances[$abstract] = $instance;

        // Keep your existing optimization: map the concrete class to the instance
        if (is_object($instance)) {
            $this->instances[$instance::class] = $instance;

            // Pre-fill the resolution cache since we know the class
            $this->resolutionCache[$abstract] = $instance::class;
        }

        return $instance;
    }

    /**
     * Remove a resolved instance from the instance cache.
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
     * Clear all of the instances from the container.
     *
     * @return void
     */
    public function forgetInstances()
    {
        $this->instances = [];
    }

    /**
     * Resolve a dependency.
     */
    public function make(string $abstract, array $parameters = [])
    {
        // Check the cache for the abstract class first
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Resolve bindings Alias/Interface -> Concrete Class
        $concrete = $abstract;
        $chain = [];

        while (is_string($concrete) and isset($this->bindings[$concrete])) {
            if (isset($chain[$concrete])) {
                throw new ContainerException("Circular binding detected: " . implode(' -> ', array_keys($chain)) . " -> $concrete");
            }
            $chain[$concrete] = true;
            $concrete = $this->bindings[$concrete];
        }

        // Fill the instance cache for singletons
        if (is_string($concrete) and isset($this->instances[$concrete])) {
            $this->instances[$abstract] = $this->instances[$concrete];
            return $this->instances[$concrete];
        }

        // Detect circular dependencies using the normalized key
        $stackKey = is_string($concrete) ? $concrete : $abstract;

        if (isset($this->buildStack[$stackKey])) {
            throw new ContainerException("Circular dependency detected: " . implode(' -> ', array_keys($this->buildStack)) . " -> $stackKey");
        }

        $this->buildStack[$stackKey] = true;

        try {
            $object = $this->build($concrete, $parameters);
        } finally {
            unset($this->buildStack[$stackKey]); // Always remove from stack, even if build fails
        }

        if (isset($this->singletons[$abstract]) or (is_string($concrete) and isset($this->singletons[$concrete]))) {
            $this->instances[$abstract] = $object;
            if (is_string($concrete)) {
                $this->instances[$concrete] = $object;
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

        // Save the class name to the resolution cache
        if (empty($parameters)) {
            $this->resolutionCache[$abstract] = $className;
        }

        return $object;
    }

    /**
     * Reflection-based auto-wiring
     */
    protected function build(string|callable $concrete, array $parameters): object
    {
        if (is_callable($concrete)) {
            return $concrete($this, $parameters);
        }

        if (!class_exists($concrete)) {

            // If it's not a class, it might be a service alias or a dynamic service
            $service = service($concrete, ...$parameters);

            if (is_object($service)) {
                return $service;
            }

            if (interface_exists($concrete)) {
                throw new NotFoundException("Service Resolution Failed: Interface '$concrete' is not bound to any implementation.");
            } else {
                throw new NotFoundException("Service Resolution Failed: Class '$concrete' not found.");
            }

        }

        if (!isset($this->parameterCache[$concrete])) {
            $this->cacheParameters($concrete);
        }

        $cachedParams = $this->parameterCache[$concrete];

        /**
         * If the cache says there are no constructor arguments, we can
         * skip Reflection entirely and just instantiate the class directly
         */
        if (empty($cachedParams)) {
            return new $concrete();
        }

        /**
         * Resolve dependencies using the cache
         */
        $instances = $this->resolveDependencies($concrete, $cachedParams, $parameters);

        /**
         * We finally use Reflection here because newInstanceArgs is cleaner for
         * dynamic args, but we successfully skipped the heavy "analysis" phase above
         */
        try {
            return (new ReflectionClass($concrete))->newInstanceArgs($instances);
        } catch (ReflectionException $e) {
            throw new ContainerException("Container failed to instantiate $concrete: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Call the given callback/method and inject its dependencies.
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
        $parsedClass = null;
        $parsedMethod = null;
        $reflector = null;

        if (is_string($callback)) {
            $callbackKey = $callback;
            if (!function_exists($callback)) {
                if (str_contains($callback, '@')) {
                    [$parsedClass, $parsedMethod] = explode('@', $callback);
                } elseif (str_contains($callback, '::')) {
                    [$parsedClass, $parsedMethod] = explode('::', $callback);
                } elseif (class_exists($callback)) {
                    $parsedClass = $callback;
                    $parsedMethod = $defaultMethod ?? '__invoke';
                }
            }
        } else {
            $callbackKey = $this->getCallbackKey($callback);

            if (is_array($callback) and isset($callback[0]) and is_string($callback[0])) {
                $parsedClass = $callback[0];
                $parsedMethod = $callback[1] ?? '__invoke';
            }
        }

        // Check function cache or use a reflection
        if ($callbackKey and isset($this->functionCache[$callbackKey])) {
            $dependencies = $this->functionCache[$callbackKey];
            $contextName = $callbackKey;
        } else {
            try {
                if ($parsedClass) {
                    $reflector = new \ReflectionMethod($parsedClass, $parsedMethod);
                    $contextName = $callback;
                } elseif (is_string($callback) and function_exists($callback)) {
                    $reflector = new \ReflectionFunction($callback);
                    $contextName = $callback;
                } elseif (is_array($callback)) {
                    $reflector = new \ReflectionMethod($callback[0], $callback[1]);
                    $contextName = $callbackKey;
                } elseif ($callback instanceof \Closure) {
                    $reflector = new \ReflectionFunction($callback);
                    $contextName = 'Closure';
                } elseif (is_object($callback)) {
                    $reflector = new \ReflectionMethod($callback, '__invoke');
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

        // Resolve & Invoke
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
                $callback = [$parsedClass, $parsedMethod];
            }

            if ($needsInstantiation) {
                $callback = [$this->make($parsedClass), $parsedMethod];
            }
        }

        return call_user_func_array($callback, $instances);
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
     * Resolve the concrete class name for an abstract/alias
     *
     * @param string $abstract
     * @param array $parameters
     *
     * @return string
     */
    public function getConcreteClass(string $abstract, array $parameters = []): string
    {
        // Check the class resolution cache first
        if (isset($this->resolutionCache[$abstract])) {
            return $this->resolutionCache[$abstract];
        }

        // If we have an object, we definitely know the class
        if (isset($this->instances[$abstract])) {
            $class = $this->instances[$abstract]::class;
            $this->resolutionCache[$abstract] = $class;
            return $class;
        }

        // Resolve Binding Chain (Alias -> Interface -> Class)
        $concrete = $abstract;
        $chain = [];

        while (is_string($concrete) and isset($this->bindings[$concrete])) {
            if (isset($chain[$concrete])) {
                throw new ContainerException("Circular binding detected: " . implode(' -> ', array_keys($chain)) . " -> $concrete");
            }
            $chain[$concrete] = true;
            $concrete = $this->bindings[$concrete];
        }

        // Return the string if it resolves to an actual class
        // This prevents returning 'cache' (alias) instead of 'Config\Cache' (class)
        if (is_string($concrete) and class_exists($concrete)) {
            $this->resolutionCache[$abstract] = $concrete;
            return $concrete;
        }

        // Delegate all complex resolution (Closures, CI4 Services, Factories) to make()
        $object = $this->make($abstract, $parameters);

        $class = $object::class;

        // Cache the class name for next time
        $this->resolutionCache[$abstract] = $class;

        return $class;
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
            } else {
                throw new ContainerException('Invalid callback arguments: ' . serialize($callback));
            }
        } elseif ($callback instanceof \Closure) {

            try {
                $reflector = new \ReflectionFunction($callback);
            } catch (\ReflectionException $e) {
                throw new ContainerException('Failed to reflect on callback: ' . $e->getMessage());
            }

            $file = $reflector->getFileName();

            if (defined('ROOTPATH')) {
                $file = str_replace(ROOTPATH, '', $file);
            }

            return 'closure_' . $file . ':' . $reflector->getStartLine();

        } elseif (is_object($callback)) {
            return get_class($callback) . '::__invoke';
        } elseif (is_string($callback)) {
            return $callback;
        } else {
            return null;
        }
    }

    /**
     * Analyze the class once and cache the parameter definitions.
     */
    protected function cacheParameters(string $className): void
    {
        try {
            $reflector = new \ReflectionClass($className);
            if (!$reflector->isInstantiable()) {
                throw new ContainerException("Service Resolution Failed: Class '$className' is not instantiable.");
            }
        } catch (ReflectionException $e) {
            throw new ContainerException("Container failed to instantiate '$className': " . $e->getMessage(), 0, $e);
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            $this->parameterCache[$className] = [];
            return;
        }

        $this->parameterCache[$className] = $this->getReflectorParameters($constructor);
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
        $results = [];
        $numericIndex = 0; // Cursor for positional arguments (0, 1, 2...)

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

                // Contextual Array Binding. Allows binding an array of services: $container->bindWhen(..., [ServiceA::class, ServiceB::class])
                if ($type !== null) {
                    $lookupTypes = is_array($type) ? $type : [$type];

                    foreach ($lookupTypes as $candidateType) {
                        if (isset($this->contextual[$className][$candidateType])) {
                            $bound = $this->contextual[$className][$candidateType];

                            if (is_array($bound)) {
                                foreach ($bound as $item) {
                                    $results[] = is_string($item) ? $this->make($item) : $this->build($item, []);
                                }
                                continue 2; // Found a match, skip to next dependency
                            }
                        }
                    }
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

                    // Contextual Binding
                    if (isset($this->contextual[$className]) and isset($this->contextual[$className][$type])) {
                        $concrete = $this->contextual[$className][$type];
                        $results[] = is_string($concrete) ? $this->make($concrete) : $this->build($concrete, []);
                        continue;
                    }

                    // Resolution
                    try {
                        $results[] = $this->make($type);
                    } catch (ContainerException $e) {
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
                        $passed = $parameters[$numericIndex];
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
                            // Check Contextual
                            if (isset($this->contextual[$className][$candidate])) {
                                $concrete = $this->contextual[$className][$candidate];
                                $results[] = is_string($concrete) ? $this->make($concrete) : $this->build($concrete, []);
                                $resolved = true;
                                break;
                            }

                            // Try to Make
                            try {
                                $results[] = $this->make($candidate);
                                $resolved = true;
                                break;
                            } catch (ContainerException $e) {
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
     * Extract parameter details from any function or method.
     * Replaces the logic inside cacheParameters.
     *
     * @param ReflectionFunctionAbstract $reflector
     *
     * @return array
     */
    protected function getReflectorParameters(ReflectionFunctionAbstract $reflector): array
    {
        $params = [];

        foreach ($reflector->getParameters() as $param) {
            $type = $param->getType();
            $resolvedType = null;
            $hasBuiltin = false;

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


}