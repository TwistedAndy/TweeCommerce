<?php

namespace Tests\Unit\Core\Container;

use App\Core\Container\Container;
use App\Core\Container\ContainerException;
use App\Core\Container\NotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use stdClass;

/**
 * Validates the core resolution logic of the Container, including auto-wiring,
 * aliasing, parameter injection, and lifecycle management.
 */
class ContainerResolutionTest extends CIUnitTestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
        $this->container = Container::getInstance();
    }

    /**
     * Tests that a concrete class can be resolved automatically without any binding.
     */
    public function testAutoConcreteResolution(): void
    {
        $instance = $this->container->make(ContainerConcreteStub::class);
        $this->assertInstanceOf(ContainerConcreteStub::class, $instance);
    }

    /**
     * Tests that an interface can be bound to a concrete implementation.
     */
    public function testBindRegistersImplementation(): void
    {
        $this->container->bind(IContainerContractStub::class, ContainerImplementationStub::class);

        $instance = $this->container->make(IContainerContractStub::class);
        $this->assertInstanceOf(ContainerImplementationStub::class, $instance);
    }

    /**
     * Tests that bindIf registers a binding only if it doesn't already exist.
     */
    public function testBindIfRespectsExistingBinding(): void
    {
        $this->container->bind('foo', fn() => (object) ['val' => 'original']);
        $this->container->bindIf('foo', fn() => (object) ['val' => 'new']);

        $this->assertEquals('original', $this->container->make('foo')->val);

        $this->container->bindIf('bar', fn() => (object) ['val' => 'new']);
        $this->assertEquals('new', $this->container->make('bar')->val);
    }

    /**
     * Tests that singletons return the same instance repeatedly.
     */
    public function testSingletonRegistersSharedInstance(): void
    {
        $this->container->singleton('shared', fn() => new stdClass());

        $first  = $this->container->make('shared');
        $second = $this->container->make('shared');

        $this->assertSame($first, $second);
    }

    /**
     * Tests that singletonIf registers a singleton only if not already bound.
     */
    public function testSingletonIfRespectsExisting(): void
    {
        $this->container->singleton('shared', fn() => (object) ['id' => 1]);
        $this->container->singletonIf('shared', fn() => (object) ['id' => 2]);

        $this->assertEquals(1, $this->container->make('shared')->id);
    }

    /**
     * Tests that scoped instances act as singletons until flushed.
     */
    public function testScopedRegistersSharedInstanceUntilFlushed(): void
    {
        $this->container->scoped('scoped', fn() => new stdClass());

        $first  = $this->container->make('scoped');
        $second = $this->container->make('scoped');
        $this->assertSame($first, $second);

        $this->container->forgetScopedInstances();

        $third = $this->container->make('scoped');
        $this->assertNotSame($first, $third);
    }

    /**
     * Tests that bindings can be defined recursively (Alias -> Alias -> Concrete).
     */
    public function testRecursiveAliasResolution(): void
    {
        $this->container->bind('concrete', ContainerConcreteStub::class);
        $this->container->bind('alias_1', 'concrete');
        $this->container->bind('alias_2', 'alias_1');

        $instance = $this->container->make('alias_2');
        $this->assertInstanceOf(ContainerConcreteStub::class, $instance);
    }

    /**
     * Tests that dependencies are automatically injected into constructors.
     */
    public function testNestedDependencyResolution(): void
    {
        $this->container->bind(IContainerContractStub::class, ContainerImplementationStub::class);

        $instance = $this->container->make(ContainerNestedDependentStub::class);

        $this->assertInstanceOf(ContainerDependentStub::class, $instance->inner);
        $this->assertInstanceOf(ContainerImplementationStub::class, $instance->inner->impl);
    }

    /**
     * Tests that manual parameters passed to make() override auto-resolution.
     */
    public function testManualParametersOverrideBindings(): void
    {
        $mockImpl = new ContainerImplementationStub();

        // Even if we bind strict implementations, passing a manual arg works
        $instance = $this->container->make(ContainerDependentStub::class, ['impl' => $mockImpl]);

        $this->assertSame($mockImpl, $instance->impl);
    }

    /**
     * Tests that default parameter values are used when no binding or manual arg is present.
     */
    public function testResolutionOfDefaultParameters(): void
    {
        $instance = $this->container->make(ContainerDefaultValueStub::class);

        $this->assertInstanceOf(ContainerConcreteStub::class, $instance->stub);
        $this->assertEquals('default', $instance->value);
    }

    /**
     * Tests that nullable parameters are resolved to null if the dependency is missing.
     */
    public function testResolutionOfNullableParameters(): void
    {
        // ContainerUnboundStub is not bound, so it should resolve to null
        $instance = $this->container->make(ContainerNullableStub::class);

        $this->assertNull($instance->stub);
    }

    /**
     * Tests that union types try multiple candidates before failing.
     */
    public function testUnionTypeResolution(): void
    {
        // Union is IContainerContractStub|ContainerConcreteStub
        // We bind neither, but ConcreteStub is instantiable.
        // The container should try Interface (fail) -> then Concrete (success).

        $instance = $this->container->make(ContainerUnionStub::class);
        $this->assertInstanceOf(ContainerConcreteStub::class, $instance->dependency);
    }

    /**
     * Tests that variadic parameters consume all remaining manual arguments.
     */
    public function testVariadicParameterResolution(): void
    {
        $instance = $this->container->make(ContainerVariadicStub::class, ['first', 'second', 'third']);

        $this->assertEquals(['first', 'second', 'third'], $instance->items);
    }

    /**
     * Tests that variadic parameters resolve to an empty array if no manual arguments are provided.
     */
    public function testVariadicResolutionWithNoArguments(): void
    {
        $instance = $this->container->make(ContainerVariadicStub::class);

        $this->assertIsArray($instance->items);
        $this->assertEmpty($instance->items);
    }

    /**
     * Tests that a mix of positional arguments and variadic arguments works correctly.
     * The first argument should be mapped to $fixed, and the rest to ...$items.
     */
    public function testVariadicResolutionWithMixedPositionalArguments(): void
    {
        // Expects: $fixed, ...$items
        $instance = $this->container->make(ContainerMixedVariadicStub::class, ['fixed_value', 'var1', 'var2']);

        $this->assertEquals('fixed_value', $instance->fixed);
        $this->assertEquals(['var1', 'var2'], $instance->items);
    }

    /**
     * Tests that auto-wired dependencies are skipped by the variadic collector,
     * allowing the variadic to consume only the remaining manual parameters.
     */
    public function testVariadicResolutionWithDependencyAndManualArgs(): void
    {
        $instance = $this->container->make(ContainerDependencyVariadicStub::class, ['item1', 'item2']);

        $this->assertInstanceOf(ContainerConcreteStub::class, $instance->stub);
        $this->assertEquals(['item1', 'item2'], $instance->items);
    }

    /**
     * Tests that Type-Hinted variadics receive instances if passed manually.
     * Note: The container does not auto-collect all services of a type, but it validates instances if passed manually.
     */
    public function testTypeHintedVariadicManualInjection(): void
    {
        $obj1     = new stdClass();
        $obj1->id = 1;
        $obj2     = new stdClass();
        $obj2->id = 2;

        $instance = $this->container->make(ContainerTypeHintedVariadicStub::class, [$obj1, $obj2]);

        $this->assertCount(2, $instance->items);
        $this->assertSame($obj1, $instance->items[0]);
        $this->assertSame($obj2, $instance->items[1]);
    }

    /**
     * Tests that closures used as factories receive the container as an argument.
     */
    public function testClosureResolutionWithContainerArg(): void
    {
        $this->container->bind('factory', function ($c) {
            return $c;
        });

        $this->assertSame($this->container, $this->container->make('factory'));
    }

    /**
     * Tests that the instance() method swaps a binding for a specific object.
     */
    public function testInstanceSwapping(): void
    {
        $mock = new ContainerImplementationStub();
        $this->container->instance(IContainerContractStub::class, $mock);

        $this->assertSame($mock, $this->container->make(IContainerContractStub::class));
    }

    /**
     * Tests the bound() method for checking existence of bindings.
     */
    public function testBoundChecks(): void
    {
        $this->container->bind('foo', fn() => 'bar');

        $this->assertTrue($this->container->bound('foo'));
        $this->assertFalse($this->container->bound('baz'));
    }

    /**
     * Tests that circular dependencies via aliases are detected.
     */
    public function testCircularAliasDetection(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular binding detected');

        $this->container->bind('A', 'B');
        $this->container->bind('B', 'C');
        $this->container->bind('C', 'A');

        $this->container->make('A');
    }

    /**
     * Tests that circular dependencies via constructor injection are detected.
     */
    public function testCircularDependencyDetectionInBuild(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $this->container->make(CircularA::class);
    }

    /**
     * Tests that requesting a non-existent class throws NotFoundException.
     */
    public function testMissingClassThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->make('NonExistentClass_12345');
    }

    /**
     * Tests that uninstantiable classes (private constructors) throw ContainerException.
     */
    public function testUninstantiableClassThrowsException(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('is not instantiable');

        $this->container->make(PrivateConstructorStub::class);
    }

    /**
     * Tests that the PSR-11 get() method works identically to make().
     */
    public function testGetMethodBehavesLikeMake(): void
    {
        $this->container->bind('foo', fn() => (object) ['val' => 'bar']);
        $this->assertEquals('bar', $this->container->get('foo')->val);
    }

    /**
     * Tests that the PSR-11 has() method correctly identifies available services.
     */
    public function testHasMethodChecksBindingsAndClasses(): void
    {
        $this->container->bind('bound_service', fn() => true);

        $this->assertTrue($this->container->has('bound_service'));
        $this->assertTrue($this->container->has(ContainerConcreteStub::class));
        $this->assertFalse($this->container->has('non_existent_service'));
    }

    /**
     * Tests that resolving logic handles primitive parameters correctly when mixed with dependencies.
     */
    public function testMixedPrimitiveAndClassDependencies(): void
    {
        $instance = $this->container->make(ContainerMixedStub::class, [
            'id'   => 99,
            'name' => 'Test'
        ]);

        $this->assertEquals(99, $instance->id);
        $this->assertInstanceOf(ContainerConcreteStub::class, $instance->stub);
        $this->assertEquals('Test', $instance->name);
    }

    /**
     * Tests that forgetInstance removes a specific instance from the cache.
     */
    public function testForgetInstanceRemovesFromCache(): void
    {
        $this->container->singleton('shared', fn() => new stdClass());
        $obj1 = $this->container->make('shared');

        $this->container->forgetInstance('shared');
        $obj2 = $this->container->make('shared');

        $this->assertNotSame($obj1, $obj2);
    }

    /**
     * Tests that forgetInstances clears the entire container cache.
     */
    public function testForgetInstancesClearsAll(): void
    {
        $this->container->singleton('A', fn() => new stdClass());
        $this->container->singleton('B', fn() => new stdClass());

        $a1 = $this->container->make('A');
        $b1 = $this->container->make('B');

        $this->container->forgetInstances();

        $a2 = $this->container->make('A');
        $b2 = $this->container->make('B');

        $this->assertNotSame($a1, $a2);
        $this->assertNotSame($b1, $b2);
    }
}


// --------------------------------------------------------------------
// STUBS
// --------------------------------------------------------------------

class ContainerConcreteStub
{
}


interface IContainerContractStub
{
}


class ContainerImplementationStub implements IContainerContractStub
{
}


class ContainerDependentStub
{
    public function __construct(public IContainerContractStub $impl)
    {
    }
}


class ContainerNestedDependentStub
{
    public function __construct(public ContainerDependentStub $inner)
    {
    }
}


class ContainerDefaultValueStub
{
    public function __construct(public ContainerConcreteStub $stub, public string $value = 'default')
    {
    }
}


interface IUnboundStub
{
} // Not bound

class ContainerNullableStub
{
    public function __construct(public ?IUnboundStub $stub)
    {
    }
}


class ContainerUnionStub
{
    // IContainerContractStub is not bound, so it fails. ContainerConcreteStub succeeds.
    public function __construct(public IContainerContractStub|ContainerConcreteStub $dependency)
    {
    }
}


class ContainerVariadicStub
{
    public array $items;

    public function __construct(...$items)
    {
        $this->items = $items;
    }
}


class ContainerMixedVariadicStub
{
    public string $fixed;
    public array  $items;

    public function __construct(string $fixed, ...$items)
    {
        $this->fixed = $fixed;
        $this->items = $items;
    }
}


class ContainerDependencyVariadicStub
{
    public array $items;

    public function __construct(public ContainerConcreteStub $stub, ...$items)
    {
        $this->items = $items;
    }
}


class ContainerTypeHintedVariadicStub
{
    public array $items;

    public function __construct(stdClass ...$items)
    {
        $this->items = $items;
    }
}


class PrivateConstructorStub
{
    private function __construct()
    {
    }
}


class CircularA
{
    public function __construct(CircularB $b)
    {
    }
}


class CircularB
{
    public function __construct(CircularC $c)
    {
    }
}


class CircularC
{
    public function __construct(CircularA $a)
    {
    }
}


class ContainerMixedStub
{
    public function __construct(
        public int $id,
        public ContainerConcreteStub $stub,
        public string $name
    ) {
    }
}