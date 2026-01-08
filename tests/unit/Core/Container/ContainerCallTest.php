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
        $result = $this->container->call('trim', ['string' => '  trimmed named  ']);
        $this->assertEquals('trimmed named', $result);
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
     * Verifies that closures created via eval() bypass the file-based caching mechanism.
     */
    public function testCallWithEvalClosureDoesNotCache(): void
    {
        // Closures created in eval() usually don't have stable file/line references suitable for the cache key logic
        $callback = eval('return function() { return "eval"; };');

        $result = $this->container->call($callback);
        $this->assertEquals('eval', $result);
    }

    /**
     * Verifies that passing an invalid array structure to call() throws an exception.
     */
    public function testCallThrowsExceptionOnInvalidArrayCallback(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Invalid callback arguments');

        // Missing method name index 1
        $this->container->call(['SomeClass']);
    }

    /**
     * Tests that a string callback key is returned as-is.
     */
    public function testGetCallbackKeyWithString(): void
    {
        $key = $this->container->getCallbackKey('trim');
        $this->assertEquals('trim', $key);
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

        $contextKey = CallContextStub::class . '::inject';
        $this->container->bindWhen($contextKey, ICallContractStub::class, CallImplementationStubTwo::class);

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
     */
    public function testCallUsesFunctionCache(): void
    {
        $callback = function ($name) {
            return $name;
        };

        $result1 = $this->container->call($callback, ['name' => 'First']);
        $result2 = $this->container->call($callback, ['name' => 'Second']);

        $this->assertEquals('First', $result1);
        $this->assertEquals('Second', $result2);
    }

    /**
     * Verifies getCallbackKey() returns null for eval'd closures to prevent caching issues.
     */
    public function testGetCallbackKeyReturnsNullForEvalClosure(): void
    {
        $closure = null;
        // Eval creates a closure with "eval()'d code" in the filename
        eval('$closure = function() { return "evaled"; };');

        $key = $this->container->getCallbackKey($closure);

        $this->assertNull($key);
    }

    /**
     * Verifies getCallbackKey() throws an exception for invalid array structures.
     */
    public function testGetCallbackKeyThrowsForInvalidArray(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Invalid callback arguments');

        $this->container->getCallbackKey(['just_one_element']);
    }

    /**
     * Verifies getCallbackKey() strips the ROOTPATH from the cache key.
     */
    public function testGetCallbackKeyStripsRootPath(): void
    {
        if (!defined('ROOTPATH')) {
            define('ROOTPATH', __DIR__ . '/');
        }

        // We use a closure defined in this file. Its path starts with __DIR__.
        // Since we set ROOTPATH to __DIR__, the resulting key should not contain the full path.
        $closure = function () {
        };

        $key = $this->container->getCallbackKey($closure);

        // The key format is "closure_filename:line"
        // If stripping worked, filename should not be the full absolute path
        $this->assertStringNotContainsString(ROOTPATH, $key);
        $this->assertStringStartsWith('closure_', $key);
    }

    /**
     * Verifies getCallbackKey() logic for object instances.
     */
    public function testGetCallbackKeyForObject(): void
    {
        $obj = new CallInvokableStub();
        $key = $this->container->getCallbackKey($obj);

        $this->assertEquals(CallInvokableStub::class . '::__invoke', $key);
    }

    /**
     * Verifies getCallbackKey() returns null for non-callback types like integers or booleans.
     */
    public function testGetCallbackKeyReturnsNullForNonCallback(): void
    {
        $this->assertNull($this->container->getCallbackKey(123));
        $this->assertNull($this->container->getCallbackKey(true));
    }

    /**
     * Tests the ReflectionException handling in call() when reflection fails on a parsed class.
     * This simulates a cache hit for a method that subsequently disappears or is invalid.
     */
    public function testCallThrowsExceptionOnReflectionFailureForParsedClass(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Failed to reflect on callback');

        // We spoof the cache to bypass the initial reflection check in call()
        // This simulates a scenario where the cache exists but the method is gone/invalid
        $callback = CallStub::class . '@missingMethod';

        $reflection = new \ReflectionClass($this->container);
        $prop       = $reflection->getProperty('functionCache');
        // Inject dummy dependencies so the cache hit logic works
        $cache            = $prop->getValue($this->container);
        $cache[$callback] = [];
        $prop->setValue($this->container, $cache);

        // This triggers the path:
        // 1. Cache hit ($reflector remains null)
        // 2. $parsedClass populated from string
        // 3. if (!$reflector) -> tries new ReflectionMethod(CallStub, 'missingMethod')
        // 4. Throws ReflectionException -> caught and wrapped in ContainerException
        $this->container->call($callback);
    }
}


// --------------------------------------------------------------------
// STUBS
// --------------------------------------------------------------------

class CallConcreteStub
{
}


interface ICallUnboundStub
{
}


class CallStub
{
    public static function injectInterface(IContainerContractStub $stub): IContainerContractStub
    {
        return $stub;
    }

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