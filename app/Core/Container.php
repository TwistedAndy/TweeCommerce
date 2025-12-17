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
     * Cache of function/method parameters to avoid slow Reflection
     * Keys are unique identifiers for the callback (e.g. "Class::method" or Closure hash).
     */
    protected static array $functionCache = [];

    /**
     * Cache of constructor parameters for classes to avoid slow Reflection
     * Structure: [ 'ClassName' => [ ['name'=>'id', 'type'=>null, ...], ... ] ]
     */
    protected static array $parameterCache = [];

    /**
     * Stack of classes currently being built to detect recursion
     */
    protected array $buildStack = [];

    /**
     * Cache of resolved singleton objects
     */
    protected array $instances = [];

    /**
     * Contextual bindings map
     * [ 'ParentClass' => [ 'NeedsInterface' => 'GivesConcrete' ] ]
     */
    protected array $contextual = [];

    /**
     * Registered bindings (Interface => Implementation)
     */
    protected array $bindings = [];

    /**
     * Registered singletons.
     */
    protected array $singletons = [];

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
     * Get the global container instance
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
     * Reset the container instance
     */
    public static function reset(): void
    {
        static::$instance = null;
        static::$functionCache = [];
        static::$parameterCache = [];
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
     * Register a binding dynamically (Used by Plugins).
     *
     * @param string $abstract          Interface or Alias (e.g. EntityInterface::class)
     * @param string|callable $concrete Concrete Class (e.g. Entity::class)
     *
     * @param bool $shared              Whether to treat as Singleton
     */
    public function bind(string $abstract, string|callable $concrete, bool $shared = false): void
    {
        $this->bindings[$abstract] = $concrete;

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
     * Register a shared binding (Singleton).
     */
    public function singleton(string $abstract, string|callable $concrete): void
    {
        $this->bind($abstract, $concrete, true);
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

        // Detect Circular Dependencies
        if (isset($this->buildStack[$abstract])) {
            throw new ContainerException("Circular dependency detected: " . implode(' -> ', array_keys($this->buildStack)) . " -> $abstract");
        }

        $this->buildStack[$abstract] = true;

        try {
            $object = $this->build($concrete, $parameters);
        } finally {
            unset($this->buildStack[$abstract]); // Always remove from stack, even if build fails
        }

        if (isset($this->singletons[$abstract]) or (is_string($concrete) and isset($this->singletons[$concrete]))) {
            $this->instances[$abstract] = $object;
            if (is_string($concrete)) {
                $this->instances[$concrete] = $object;
            }
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
            $service = service($concrete);

            if (is_object($service)) {
                return $service;
            }

            if (interface_exists($concrete)) {
                throw new NotFoundException("Service Resolution Failed: Interface '$concrete' is not bound to any implementation.");
            } else {
                throw new NotFoundException("Service Resolution Failed: Class '$concrete' not found.");
            }

        }

        if (!isset(static::$parameterCache[$concrete])) {
            $this->cacheParameters($concrete);
        }

        $cachedParams = static::$parameterCache[$concrete];

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
     * @param callable|string|array $callback
     * @param array $parameters          Named parameters to override
     * @param string|null $defaultMethod Method to call if $callback is just a class name
     *
     * @return mixed
     */
    public function call(mixed $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        $parsedClass = null;
        $parsedMethod = null;

        if (is_string($callback)) {
            $callbackKey = $callback;
            if (!function_exists($callback)) {
                if (str_contains($callback, '@')) {
                    [$parsedClass, $parsedMethod] = explode('@', $callback);
                } elseif (str_contains($callback, '::')) {
                    [$parsedClass, $parsedMethod] = explode('::', $callback);
                } elseif ($defaultMethod and class_exists($callback)) {
                    $parsedClass = $callback;
                    $parsedMethod = $defaultMethod;
                }
            }
        } else {
            $callbackKey = $this->getCallbackKey($callback);
        }

        // Check function cache or use a reflection
        if ($callbackKey and isset(static::$functionCache[$callbackKey])) {
            $dependencies = static::$functionCache[$callbackKey];
            $contextName = $callbackKey;
        } else {
            try {
                if ($parsedClass) {
                    $reflector = new \ReflectionMethod($parsedClass, $parsedMethod);
                    $contextName = $callback;
                } elseif (is_string($callback) && function_exists($callback)) {
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
                static::$functionCache[$callbackKey] = $dependencies;
            }
        }

        // Resolve & Invoke
        $instances = $this->resolveDependencies($contextName, $dependencies, $parameters);

        if ($parsedClass) {
            $needsInstantiation = true;

            if (str_contains($callback, '::')) {
                try {
                    $ref = new \ReflectionMethod($parsedClass, $parsedMethod);
                    if ($ref->isStatic()) {
                        $needsInstantiation = false;
                    }
                } catch (ReflectionException $e) {
                    throw new ContainerException('Failed to reflect on callback: ' . $e->getMessage());
                }
            }

            if ($needsInstantiation) {
                $callback = [$this->make($parsedClass), $parsedMethod];
            }
        }

        return call_user_func_array($callback, $instances);
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
            static::$parameterCache[$className] = [];
            return;
        }

        static::$parameterCache[$className] = $this->getReflectorParameters($constructor);
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

            // Class Dependency (Auto-wiring)
            if ($type !== null) {

                // If the passed parameter at $numericIndex is an instance of the required type, use it!
                if (array_key_exists($numericIndex, $parameters) and $parameters[$numericIndex] instanceof $type) {
                    $results[] = $parameters[$numericIndex];
                    $numericIndex++;
                    continue;
                }

                if (isset($this->contextual[$className]) and isset($this->contextual[$className][$type])) {
                    $concrete = $this->contextual[$className][$type];

                    // Pass empty array [] to ensure dependencies are isolated from the parent's parameters
                    $results[] = is_string($concrete) ? $this->make($concrete) : $this->build($concrete, []);
                    continue;
                }
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

            // Positional Parameters
            if (array_key_exists($numericIndex, $parameters)) {
                $results[] = $parameters[$numericIndex];
                $numericIndex++; // Move cursor to the next argument
                continue;
            }

            // Variadic (Consume remaining)
            if ($dep['variadic']) {
                while (array_key_exists($numericIndex, $parameters)) {
                    $results[] = $parameters[$numericIndex];
                    $numericIndex++;
                }
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
            $params[] = [
                'name'        => $param->getName(),
                'type_name'   => ($type instanceof ReflectionNamedType and !$type->isBuiltin()) ? $type->getName() : null,
                'is_optional' => $param->isDefaultValueAvailable(),
                'default'     => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                'nullable'    => $param->allowsNull(),
                'variadic'    => $param->isVariadic(),
            ];
        }

        return $params;
    }


}