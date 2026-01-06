<?php

namespace Tests\Unit\Core\Container;

use App\Core\Container\Container;
use CodeIgniter\Test\CIUnitTestCase;
use stdClass;

/**
 * Validates the resolving() callback hooks which allow modifying objects
 * right after they are instantiated by the container.
 */
class ResolvingCallbackTest extends CIUnitTestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
        $this->container = Container::getInstance();
    }

    /**
     * Tests that a callback registered for a specific alias key is triggered.
     */
    public function testResolvingCallbacksAreCalledForSpecificAbstracts(): void
    {
        $this->container->resolving('foo', function ($object) {
            $object->name = 'modified';
        });

        $this->container->bind('foo', fn() => new stdClass);

        $instance = $this->container->make('foo');

        $this->assertEquals('modified', $instance->name);
    }

    /**
     * Tests that a callback registered for the concrete class name is triggered,
     * even if we resolved via an alias.
     */
    public function testResolvingCallbacksAreCalledForConcreteType(): void
    {
        $this->container->resolving(stdClass::class, function ($object) {
            $object->type_checked = true;
        });

        // Bind 'foo' -> stdClass
        $this->container->bind('foo', fn() => new stdClass);

        $instance = $this->container->make('foo');

        $this->assertTrue($instance->type_checked);
    }

    /**
     * Tests that BOTH the abstract (alias) callback and the concrete class callback
     * are fired when resolving an alias.
     */
    public function testResolvingCallbacksAreCalledForBothAbstractAndConcrete(): void
    {
        $this->container->resolving('alias', function ($object) {
            $object->abstract_hit = true;
        });

        $this->container->resolving(ResolvingConcreteStub::class, function ($object) {
            $object->concrete_hit = true;
        });

        $this->container->bind('alias', ResolvingConcreteStub::class);

        $instance = $this->container->make('alias');

        $this->assertTrue($instance->abstract_hit);
        $this->assertTrue($instance->concrete_hit);
    }

    /**
     * Tests that multiple callbacks for the same abstract are executed in order.
     */
    public function testMultipleResolvingCallbacksAreCalled(): void
    {
        $this->container->resolving('foo', function ($object) {
            $object->stack[] = 'first';
        });

        $this->container->resolving('foo', function ($object) {
            $object->stack[] = 'second';
        });

        $this->container->bind('foo', fn() => (object) ['stack' => []]);

        $instance = $this->container->make('foo');

        $this->assertEquals(['first', 'second'], $instance->stack);
    }

    /**
     * Tests that callbacks receive the Container instance as the second argument.
     */
    public function testResolvingCallbackReceivesContainer(): void
    {
        $this->container->resolving('foo', function ($object, $container) {
            $object->container_received = $container;
        });

        $this->container->bind('foo', fn() => new stdClass);

        $instance = $this->container->make('foo');

        $this->assertSame($this->container, $instance->container_received);
    }

    /**
     * Tests that resolving callbacks are fired only once for Singletons.
     */
    public function testResolvingCallbacksAreCalledOnceForSingletons(): void
    {
        $callCounter = 0;
        $this->container->resolving(stdClass::class, function () use (&$callCounter) {
            $callCounter++;
        });

        // Register as singleton
        $this->container->singleton(stdClass::class, fn() => new stdClass);

        $this->container->make(stdClass::class);
        $this->container->make(stdClass::class); // Should pull from cache, skipping callbacks

        $this->assertEquals(1, $callCounter);
    }

    /**
     * Tests that resolving callbacks are fired EVERY time for non-singleton (transient) services.
     */
    public function testResolvingCallbacksFireEveryTimeForTransientServices(): void
    {
        $callCounter = 0;
        $this->container->resolving('transient', function () use (&$callCounter) {
            $callCounter++;
        });

        // Bind normally (not singleton)
        $this->container->bind('transient', fn() => new stdClass);

        $this->container->make('transient');
        $this->container->make('transient');
        $this->container->make('transient');

        $this->assertEquals(3, $callCounter);
    }

    /**
     * Tests the exact invocation counts when resolving via an alias.
     * We expect 1 call for the alias, and 1 call for the concrete class.
     */
    public function testResolvingCallbacksCountForAliases(): void
    {
        $aliasCount    = 0;
        $concreteCount = 0;

        $this->container->resolving('alias', function () use (&$aliasCount) {
            $aliasCount++;
        });

        $this->container->resolving(ResolvingConcreteStub::class, function () use (&$concreteCount) {
            $concreteCount++;
        });

        $this->container->bind('alias', ResolvingConcreteStub::class);

        $this->container->make('alias');

        $this->assertEquals(1, $aliasCount, 'Alias callback should fire exactly once.');
        $this->assertEquals(1, $concreteCount, 'Concrete callback should fire exactly once.');
    }

    /**
     * Tests that resolving callbacks run for nested dependencies.
     */
    public function testResolvingCallbacksAreCalledForDependencies(): void
    {
        $this->container->resolving(ResolvingDependencyStub::class, function ($obj) {
            $obj->resolved = true;
        });

        // ResolvingParentStub depends on ResolvingDependencyStub
        $parent = $this->container->make(ResolvingParentStub::class);

        $this->assertTrue($parent->dependency->resolved);
    }

    /**
     * Tests invocation counts for nested dependencies.
     * Every time we make the Parent, the Child should also trigger its callback.
     */
    public function testNestedResolutionCounters(): void
    {
        $dependencyCount = 0;
        $parentCount     = 0;

        $this->container->resolving(ResolvingDependencyStub::class, function () use (&$dependencyCount) {
            $dependencyCount++;
        });

        $this->container->resolving(ResolvingParentStub::class, function () use (&$parentCount) {
            $parentCount++;
        });

        // Make parent twice (Transient)
        $this->container->make(ResolvingParentStub::class);
        $this->container->make(ResolvingParentStub::class);

        $this->assertEquals(2, $parentCount, 'Parent callback should count 2.');
        $this->assertEquals(2, $dependencyCount, 'Dependency callback should count 2 (once per parent creation).');
    }

    /**
     * Tests that resolving callbacks work for Interfaces when requested directly.
     */
    public function testResolvingCallbacksAreCalledForInterfaces(): void
    {
        $this->container->resolving(ResolvingContractStub::class, function ($object) {
            $object->interface_resolved = true;
        });

        $this->container->bind(ResolvingContractStub::class, ResolvingImplementationStub::class);

        $instance = $this->container->make(ResolvingContractStub::class);

        $this->assertTrue($instance->interface_resolved);
    }
}


// --------------------------------------------------------------------
// STUBS
// --------------------------------------------------------------------

class ResolvingConcreteStub
{
    public bool $abstract_hit = false;
    public bool $concrete_hit = false;
}


interface ResolvingContractStub
{
}


class ResolvingImplementationStub implements ResolvingContractStub
{
    public bool $interface_resolved = false;
}


class ResolvingDependencyStub
{
    public bool $resolved = false;
}


class ResolvingParentStub
{
    public function __construct(public ResolvingDependencyStub $dependency)
    {
    }
}