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
    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
        $this->container = Container::getInstance();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Container::setInstance(null);
    }

    /**
     * Tests that the constructor correctly loads a full array configuration.
     * Note: This test creates its OWN instance to pass config to the constructor.
     */
    public function testConstructLoadsConfiguration(): void
    {
        $config = [
            'bindings'   => [
                'interface'      => stdClass::class,
                'shared'         => stdClass::class,
                'scoped_service' => ConfigScopedStub::class,
            ],
            'singletons' => ['shared'],
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
        $this->assertTrue($s1->extended);

        // Verify Scoped
        $sc1 = $container->make('scoped_service');
        $sc2 = $container->make('scoped_service');
        $this->assertSame($sc1, $sc2);
        $container->forgetScopedInstances();
        $sc3 = $container->make('scoped_service');
        $this->assertNotSame($sc1, $sc3);

        // Verify Tags
        $this->assertCount(2, $container->tagged('my_tag'));
    }

    /**
     * Tests that the constructor throws an exception if a service is defined as both Singleton and Scoped.
     */
    public function testConstructThrowsOnScopeConflict(): void
    {
        // Clear instance to ensure getInstance triggers the private constructor
        Container::setInstance(null);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Conflict: Services cannot be both Singleton and Scoped');

        $config = [
            'singletons' => ['foo'],
            'scoped'     => ['foo']
        ];

        Container::getInstance($config);
    }

    /**
     * Tests that getConcreteClass returns the bound class name for an alias.
     */
    public function testGetConcreteClassReturnsClassNameForAlias(): void
    {
        $this->container->bind('alias', stdClass::class);

        $this->assertEquals(stdClass::class, $this->container->getConcreteClass('alias'));
    }

    /**
     * Tests that getConcreteClass returns the class name of a resolved instance.
     */
    public function testGetConcreteClassReturnsClassOfResolvedInstance(): void
    {
        $this->container->singleton('shared', fn() => new stdClass());

        // Resolve it first so it's in the instance cache
        $this->container->make('shared');

        $this->assertEquals(stdClass::class, $this->container->getConcreteClass('shared'));
    }

    /**
     * Tests that getConcreteClass resolves factories to determine the underlying class.
     */
    public function testGetConcreteClassResolvesFactory(): void
    {
        $this->container->bind('factory', fn() => new stdClass());

        // Should internally build the object to find its class
        $this->assertEquals(stdClass::class, $this->container->getConcreteClass('factory'));
    }

    /**
     * Tests that Intersection Types are treated as built-in/unresolvable, forcing manual injection.
     */
    public function testIntersectionTypesForceManualBinding(): void
    {
        try {
            $this->container->make(ContainerIntersectionStub::class);
            $this->fail('Should have thrown ContainerException due to unresolvable intersection param');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('missing a value', $e->getMessage());
        }

        // It should work if we pass the parameter manually
        $param    = new ContainerIntersectionParam();
        $instance = $this->container->make(ContainerIntersectionStub::class, ['param' => $param]);

        $this->assertSame($param, $instance->param);
    }

    /**
     * Tests the fallback logic where build() calls the global service() function if the class doesn't exist.
     */
    public function testBuildThrowsNotFoundForUnknownString(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Service Resolution Failed: Class 'UnknownServiceString' not found");

        $this->container->make('UnknownServiceString');
    }

    /**
     * Tests that build() returns the object from the service() helper
     * if the class does not exist but the service helper resolves it.
     */
    public function testBuildReturnsObjectFromServiceHelper(): void
    {
        $result = $this->container->make('mock_service_helper');

        $this->assertIsObject($result);
        $this->assertInstanceOf(\stdClass::class, $result);
    }

    /**
     * Tests that has() returns true if the service exists in Config\Services.
     */
    public function testHasChecksConfigServices(): void
    {
        $serviceName = 'testService'; // Default from MockServices

        if (class_exists(\Config\Services::class)) {
            $methods = get_class_methods(\Config\Services::class);
            if (!empty($methods)) {
                $serviceName = $methods[0];
            }
        }

        $this->assertTrue($this->container->has($serviceName));
    }

    /**
     * Tests scopedIf logic: registers only if not bound.
     */
    public function testScopedIf(): void
    {
        $this->container->scoped('foo', stdClass::class);

        // Should ignore this binding
        $this->container->scopedIf('foo', ConfigScopedStub::class);
        $this->assertInstanceOf(stdClass::class, $this->container->make('foo'));

        // Should register this one
        $this->container->scopedIf('bar', ConfigScopedStub::class);
        $this->assertInstanceOf(ConfigScopedStub::class, $this->container->make('bar'));
    }

    /**
     * Verifies that the constructor handles invalid scalar
     * configuration inputs gracefully (by ignoring them).
     */
    public function testConstructHandlesScalarConfig(): void
    {
        Container::setInstance(null);
        // Passing a string 'config' should safely do nothing (not crash).
        $container = Container::getInstance('invalid_config_string');
        $this->assertInstanceOf(Container::class, $container);
    }

    /**
     * Verifies that getConcreteClass() utilizes the internal resolution cache.
     */
    public function testGetConcreteClassUsesCache(): void
    {
        $this->container->bind('alias', stdClass::class);

        // First call populates cache
        $this->assertEquals(stdClass::class, $this->container->getConcreteClass('alias'));

        // Second call hits cache
        $this->assertEquals(stdClass::class, $this->container->getConcreteClass('alias'));
    }
}


// --------------------------------------------------------------------
// STUBS
// --------------------------------------------------------------------

class ConfigScopedStub
{
}


/**
 * Stub used to mock Config\Services via class_alias
 */
class MockServices
{
    public static function testService()
    {
        return true;
    }
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
    public function __construct(public StubIterator&StubCountable $param)
    {
    }
}

namespace App\Core\Container;

if (!function_exists('App\Core\Container\service')) {
    /**
     * This function sits in the same namespace as the Container class.
     * When Container calls `service()`, PHP finds this function first.
     */
    function service(string $name, ...$params)
    {
        // 1. Intercept the specific test case
        if ($name === 'mock_service_helper') {
            return new \stdClass();
        }

        // 2. Fallback to Global Service (if available)
        // This ensures normal framework behavior for other calls
        if (function_exists('\service')) {
            return \service($name, ...$params);
        }

        // 3. Fallback for Config\Services (Unit Test Mode)
        // This handles cases where we mocked Config\Services via class_alias
        if (class_exists('Config\Services') and method_exists('Config\Services', $name)) {
            return \Config\Services::$name(...$params);
        }

        return null;
    }
}