<?php

namespace Tests\Unit\Core\Container;

use App\Core\Container\Container;
use App\Core\Container\ContainerException;
use App\Core\Container\NotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use stdClass;

/**
 * Validates Container configuration loading, helper methods, and framework integration logic.
 */
class ContainerConfigTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Container::setInstance(null);
    }

    /**
     * Tests that the constructor correctly loads a full array configuration.
     * This covers bindings, singletons, scoped definitions, tags, and extenders.
     */
    public function testConstructLoadsConfiguration(): void
    {
        $config = [
            // Bindings must be defined here
            'bindings'   => [
                'interface'      => stdClass::class,
                'shared'         => stdClass::class,
                'scoped_service' => ConfigScopedStub::class,
            ],
            // Singletons is a list of keys that are shared
            'singletons' => ['shared'],
            // Scoped is a list of keys that are scoped
            'scoped'     => ['scoped_service'],
            'tags'       => ['my_tag' => ['shared', 'interface']],
            'extenders'  => [
                'shared' => function ($obj) {
                    $obj->extended = true;
                    return $obj;
                }
            ]
        ];

        // Reset instance to force constructor run via getInstance
        Container::setInstance(null);
        $container = Container::getInstance($config);

        // Verify Bindings
        $this->assertTrue($container->has('interface'));

        // Verify Singletons (Should return same instance)
        $s1 = $container->make('shared');
        $s2 = $container->make('shared');
        $this->assertSame($s1, $s2);
        $this->assertTrue($s1->extended); // Verify Extender ran

        // Verify Scoped
        $sc1 = $container->make('scoped_service');
        $sc2 = $container->make('scoped_service');
        $this->assertSame($sc1, $sc2); // Same within request
        $container->forgetScopedInstances();
        $sc3 = $container->make('scoped_service');
        $this->assertNotSame($sc1, $sc3); // Different after flush

        // Verify Tags
        $this->assertCount(2, $container->tagged('my_tag'));
    }

    /**
     * Tests that the constructor throws an exception if a service is defined as both Singleton and Scoped.
     */
    public function testConstructThrowsOnScopeConflict(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Conflict: Services cannot be both Singleton and Scoped');

        $config = [
            'singletons' => ['foo'],
            'scoped'     => ['foo']
        ];

        // Clear instance to ensure getInstance triggers the private constructor
        Container::setInstance(null);
        Container::getInstance($config);
    }

    /**
     * Tests that getConcreteClass returns the bound class name for an alias.
     */
    public function testGetConcreteClassReturnsClassNameForAlias(): void
    {
        $container = Container::getInstance();
        $container->bind('alias', stdClass::class);

        $this->assertEquals(stdClass::class, $container->getConcreteClass('alias'));
    }

    /**
     * Tests that getConcreteClass returns the class name of a resolved instance.
     */
    public function testGetConcreteClassReturnsClassOfResolvedInstance(): void
    {
        $container = Container::getInstance();
        $container->singleton('shared', fn() => new stdClass());

        // Resolve it first so it's in the instance cache
        $container->make('shared');

        $this->assertEquals(stdClass::class, $container->getConcreteClass('shared'));
    }

    /**
     * Tests that getConcreteClass resolves factories to determine the underlying class.
     */
    public function testGetConcreteClassResolvesFactory(): void
    {
        $container = Container::getInstance();
        $container->bind('factory', fn() => new stdClass());

        // Should internally build the object to find its class
        $this->assertEquals(stdClass::class, $container->getConcreteClass('factory'));
    }

    /**
     * Tests that Intersection Types are treated as built-in/unresolvable, forcing manual override.
     */
    public function testIntersectionTypesForceManualBinding(): void
    {
        $container = Container::getInstance();

        // Attempting to resolve without parameters should fail because the container
        // marks intersection types as 'builtin' (hard to auto-wire).
        try {
            $container->make(ContainerIntersectionStub::class);
            $this->fail('Should have thrown ContainerException due to unresolvable intersection param');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('missing a value', $e->getMessage());
        }

        // It should work if we pass the parameter manually
        $param    = new ContainerIntersectionParam();
        $instance = $container->make(ContainerIntersectionStub::class, ['param' => $param]);

        $this->assertSame($param, $instance->param);
    }

    /**
     * Tests that make() falls back to the global service() helper if the class is not found.
     */
    public function testMakeFallsBackToGlobalServiceHelper(): void
    {
        $container = Container::getInstance();

        // 1. Framework Environment (Real service helper available)
        if (function_exists('service')) {
            // 'timer' is a standard CI4 service that requires no arguments
            // Container sees 'timer' is not a class, so it calls service('timer')
            $result = $container->make('timer');
            $this->assertIsObject($result);
            return;
        }

        // 2. Isolated Environment (Mock required)
        // Define a global service() function dynamically for this test run
        eval('function service($name) { return ($name === "mock_service_helper") ? new \stdClass() : null; }');

        $result = $container->make('mock_service_helper');
        $this->assertIsObject($result);
    }

    /**
     * Tests the fallback logic where build() calls the global service() function if the class doesn't exist.
     * Since we cannot easily define the service() function if it doesn't exist, we expect NotFoundException.
     */
    public function testBuildThrowsNotFoundForUnknownString(): void
    {
        $container = Container::getInstance();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Service Resolution Failed: Class 'UnknownServiceString' not found");

        $container->make('UnknownServiceString');
    }

    /**
     * Tests that has() returns true if the service exists in Config\Services.
     */
    public function testHasChecksConfigServices(): void
    {
        $serviceName = 'testService';

        // If Config\Services already exists (real app context), use an existing method
        if (class_exists(\Config\Services::class)) {
            $methods = get_class_methods(\Config\Services::class);
            if (!empty($methods)) {
                $serviceName = $methods[0];
            } else {
                $this->markTestSkipped('Config\Services exists but has no methods to test.');
            }
        } else {
            // Otherwise, mock it dynamically for the unit test
            eval('namespace Config; class Services { public static function testService() {} }');
        }

        $container = Container::getInstance();

        $this->assertTrue($container->has($serviceName));
    }
}


// --------------------------------------------------------------------
// STUBS
// --------------------------------------------------------------------

class ConfigScopedStub
{
}


interface StubIterator extends \Iterator
{
}


interface StubCountable extends \Countable
{
}


class ContainerIntersectionParam implements StubIterator, StubCountable
{
    public function current(): mixed
    {
        return null;
    }

    public function next(): void
    {
    }

    public function key(): mixed
    {
        return null;
    }

    public function valid(): bool
    {
        return false;
    }

    public function rewind(): void
    {
    }

    public function count(): int
    {
        return 0;
    }
}


class ContainerIntersectionStub
{
    // Requires PHP 8.1+
    public function __construct(public StubIterator&StubCountable $param)
    {
    }
}


/**
 * Mock for the CodeIgniter global service helper.
 */
if (!function_exists('service')) {
    function service(string $name, ...$params)
    {
        if ($name === 'mock_service_helper') {
            return new \stdClass(); // Return a dummy object to prove success
        }
        return null;
    }
}