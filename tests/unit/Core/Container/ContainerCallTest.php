<?php

namespace Tests\Unit\Core\Container;

use App\Core\Container\Container;
use App\Core\Container\ContainerException;
use CodeIgniter\Test\CIUnitTestCase;
use stdClass;

class ContainerCallTest extends CIUnitTestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
        $this->container = Container::getInstance();
    }

    /**
     * Tests calling a method using the "Class@method" string syntax.
     * This triggers the instantiation of the class via the container.
     */
    public function testCallWithAtSignSyntax(): void
    {
        $result = $this->container->call(CallStub::class . '@work');
        $this->assertEquals('worked', $result);
    }

    /**
     * Tests calling a global PHP function via string.
     */
    public function testCallExecutesGlobalFunction(): void
    {
        // 'trim' is a standard global function
        $result = $this->container->call('trim', ['string' => '  trimmed named  ']);
        $this->assertEquals('trimmed named', $result);

        $result = $this->container->call('trim', ['  trimmed positional  ']);
        $this->assertEquals('trimmed positional', $result);
    }

    /**
     * Tests calling a static method using the "Class::method" string syntax.
     */
    public function testCallWithDoubleColonSyntax(): void
    {
        $result = $this->container->call(CallStub::class . '::staticWork');
        $this->assertEquals('static_worked', $result);
    }

    /**
     * Tests calling a class string where the container infers the "__invoke" method.
     */
    public function testCallWithClassNameDefaultingToInvoke(): void
    {
        $result = $this->container->call(CallInvokableStub::class);
        $this->assertEquals('invoked', $result);
    }

    /**
     * Tests calling a class string with a specific default method provided as the third argument.
     */
    public function testCallWithClassNameAndDefaultMethod(): void
    {
        $result = $this->container->call(CallStub::class, [], 'work');
        $this->assertEquals('worked', $result);
    }

    /**
     * Tests calling a standard array callable [$object, 'method'].
     */
    public function testCallWithObjectAndMethodArray(): void
    {
        $stub   = new CallStub();
        $result = $this->container->call([$stub, 'work']);
        $this->assertEquals('worked', $result);
    }

    /**
     * Tests calling a static method using array syntax ['Class', 'method'].
     */
    public function testCallWithClassStringAndStaticMethodArray(): void
    {
        $result = $this->container->call([CallStub::class, 'staticWork']);
        $this->assertEquals('static_worked', $result);
    }

    /**
     * Tests calling a non-static method using array syntax ['Class', 'method'].
     * The container should automatically instantiate the class.
     */
    public function testCallWithClassStringAndMethodArrayResolvesInstance(): void
    {
        $result = $this->container->call([CallStub::class, 'work']);
        $this->assertEquals('worked', $result);
    }

    /**
     * Tests calling a closure.
     */
    public function testCallWithClosure(): void
    {
        $result = $this->container->call(function () {
            return 'closure_worked';
        });
        $this->assertEquals('closure_worked', $result);
    }

    /**
     * Tests calling an invokable object instance.
     */
    public function testCallWithInvokableObject(): void
    {
        $stub   = new CallInvokableStub();
        $result = $this->container->call($stub);
        $this->assertEquals('invoked', $result);
    }

    /**
     * Tests that dependencies are injected into the called method.
     */
    public function testCallInjectsDependencies(): void
    {
        $this->container->bind(CallConcreteStub::class, fn() => new CallConcreteStub());

        $result = $this->container->call(function (CallConcreteStub $stub) {
            return $stub;
        });

        $this->assertInstanceOf(CallConcreteStub::class, $result);
    }

    /**
     * Tests that parameters passed to call() override dependencies by name.
     */
    public function testCallInjectsNamedParameters(): void
    {
        $result = $this->container->call(function ($foo, $bar) {
            return [$foo, $bar];
        }, ['foo' => 'FOO', 'bar' => 'BAR']);

        $this->assertEquals(['FOO', 'BAR'], $result);
    }

    /**
     * Tests mixing injected dependencies and manual parameters.
     */
    public function testCallMixesInjectionAndParameters(): void
    {
        $result = $this->container->call(function (CallConcreteStub $stub, $name) {
            return [$stub, $name];
        }, ['name' => 'Taylor']);

        $this->assertInstanceOf(CallConcreteStub::class, $result[0]);
        $this->assertEquals('Taylor', $result[1]);
    }

    /**
     * Tests that default parameter values are used if no value is provided or injected.
     */
    public function testCallUsesDefaultParameterValues(): void
    {
        $result = $this->container->call(function ($name = 'Default') {
            return $name;
        });

        $this->assertEquals('Default', $result);
    }

    /**
     * Tests that nullable parameters receive null if not bound.
     */
    public function testCallResolvesNullableParameters(): void
    {
        // We do not bind ICallUnboundStub, and since it is an interface, it cannot be auto-wired.
        $result = $this->container->call(function (?ICallUnboundStub $stub) {
            return $stub;
        });

        $this->assertNull($result);
    }

    /**
     * Tests that variadic parameters consume remaining arguments.
     */
    public function testCallResolvesVariadicParameters(): void
    {
        $result = $this->container->call(function (...$args) {
            return $args;
        }, ['a', 'b', 'c']);

        $this->assertEquals(['a', 'b', 'c'], $result);
    }

    /**
     * Tests that a Contextual Binding applies to a method injected via call().
     */
    public function testCallRespectedContextualBindings(): void
    {
        $this->container->bind(ICallContractStub::class, CallImplementationStub::class);

        // Define a contextual rule: When CallContextStub::inject needs Interface, give it ImplementationTwo
        $contextKey = CallContextStub::class . '::inject';
        $this->container->bindWhen($contextKey, ICallContractStub::class, CallImplementationStubTwo::class);

        // Call the method
        $stub   = new CallContextStub();
        $result = $this->container->call([$stub, 'inject']);

        $this->assertInstanceOf(CallImplementationStubTwo::class, $result);
    }

    /**
     * Tests that invalid callbacks throw a ContainerException.
     */
    public function testCallThrowsExceptionForInvalidCallback(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Invalid callback provided');

        $this->container->call(12345);
    }

    /**
     * Tests that failing to reflect on a callback throws a ContainerException.
     */
    public function testCallThrowsExceptionForNonExistentMethod(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Failed to reflect');

        $this->container->call([CallStub::class, 'nonExistentMethod']);
    }

    /**
     * Tests that caching works by calling the same method twice.
     * This verifies the `functionCache` logic in Container.
     */
    public function testCallUsesFunctionCache(): void
    {
        $callback = function ($name) {
            return $name;
        };

        // First call populates cache
        $result1 = $this->container->call($callback, ['name' => 'First']);
        // Second call uses cache
        $result2 = $this->container->call($callback, ['name' => 'Second']);

        $this->assertEquals('First', $result1);
        $this->assertEquals('Second', $result2);
    }
}


// --------------------------------------------------------------------
// STUBS
// --------------------------------------------------------------------

class CallConcreteStub
{
}


// FIX: Changed from class to interface to prevent auto-wiring
interface ICallUnboundStub
{
}


class CallStub
{
    public function work()
    {
        return 'worked';
    }

    public static function staticWork()
    {
        return 'static_worked';
    }
}


class CallInvokableStub
{
    public function __invoke()
    {
        return 'invoked';
    }
}


interface ICallContractStub
{
}


class CallImplementationStub implements ICallContractStub
{
}


class CallImplementationStubTwo implements ICallContractStub
{
}


class CallContextStub
{
    public function inject(ICallContractStub $stub)
    {
        return $stub;
    }
}