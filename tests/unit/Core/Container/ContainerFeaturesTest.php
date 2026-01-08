<?php

namespace Tests\Unit\Core\Container;

use App\Core\Container\Container;
use CodeIgniter\Test\CIUnitTestCase;
use stdClass;

/**
 * Validates advanced Container features: Tags, Extensions, and Runtime Swapping.
 */
class ContainerFeaturesTest extends CIUnitTestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
        $this->container = Container::getInstance();
    }

    /**
     * Verifies that multiple services can be assigned a tag and retrieved together.
     */
    public function testTaggingAndRetrieving(): void
    {
        $this->container->bind('ServiceA', fn() => (object) ['id' => 'A']);
        $this->container->bind('ServiceB', fn() => (object) ['id' => 'B']);

        $this->container->tag(['ServiceA', 'ServiceB'], 'reports');

        $results = $this->container->tagged('reports');

        $this->assertCount(2, $results);
        $this->assertEquals('A', $results[0]->id);
        $this->assertEquals('B', $results[1]->id);
    }

    /**
     * Tests that tagging works when passing arguments individually instead of an array.
     */
    public function testTaggingWithVariadicArguments(): void
    {
        $this->container->bind('ServiceA', stdClass::class);

        // tag('ServiceA', 'tag1', 'tag2')
        $this->container->tag('ServiceA', 'tag1', 'tag2');

        $this->assertCount(1, $this->container->tagged('tag1'));
        $this->assertCount(1, $this->container->tagged('tag2'));
    }

    /**
     * Tests tagging multiple abstracts with multiple tags passed as arrays.
     */
    public function testTaggingMultipleServicesWithMultipleTags(): void
    {
        $this->container->bind('A', stdClass::class);
        $this->container->bind('B', stdClass::class);

        $this->container->tag(['A', 'B'], ['tag1', 'tag2']);

        $this->assertCount(2, $this->container->tagged('tag1'));
        $this->assertCount(2, $this->container->tagged('tag2'));
    }

    /**
     * Verifies that tagged() returns an empty array for unknown tags.
     */
    public function testTaggedReturnsEmptyForUnknown(): void
    {
        $this->assertIsArray($this->container->tagged('non_existent'));
        $this->assertEmpty($this->container->tagged('non_existent'));
    }

    /**
     * Tests extending a service to modify it before it is returned.
     */
    public function testExtendModifiesService(): void
    {
        $this->container->bind('config', fn() => (object) ['debug' => false]);

        $this->container->extend('config', function ($service, $container) {
            $service->debug = true;
            return $service;
        });

        $instance = $this->container->make('config');
        $this->assertTrue($instance->debug);
    }

    /**
     * Tests that multiple extenders are applied in order.
     */
    public function testMultipleExtendersApplyInOrder(): void
    {
        $this->container->bind('text', fn() => (object) ['val' => 'foo']);

        $this->container->extend('text', fn($obj) => (object) ['val' => $obj->val . 'bar']);
        $this->container->extend('text', fn($obj) => (object) ['val' => $obj->val . 'baz']);

        $instance = $this->container->make('text');
        $this->assertEquals('foobarbaz', $instance->val);
    }

    /**
     * Tests that extenders work on Singletons and ensure the instance is refreshed.
     */
    public function testExtendWorksOnSingletons(): void
    {
        $this->container->singleton('shared', fn() => (object) ['count' => 0]);

        // Create first instance
        $instance1 = $this->container->make('shared');
        $this->assertEquals(0, $instance1->count);

        // Extend it (should clear cache)
        $this->container->extend('shared', function ($service) {
            $service->count++;
            return $service;
        });

        $instance2 = $this->container->make('shared');
        $instance3 = $this->container->make('shared');

        // New instance should reflect extension
        $this->assertEquals(1, $instance2->count);
        $this->assertNotSame($instance1, $instance2);
        $this->assertSame($instance2, $instance3);
    }

    /**
     * Tests that extending a service clears its old instance from the cache immediately.
     */
    public function testExtendClearsStaleInstances(): void
    {
        $this->container->singleton('service', stdClass::class);
        $original = $this->container->make('service');

        $this->container->extend('service', function ($s) {
            $s->extended = true;
            return $s;
        });

        // The old instance should be gone, next resolve rebuilds it
        $new = $this->container->make('service');

        $this->assertNotSame($original, $new);
        $this->assertTrue($new->extended);
    }

    /**
     * Tests extending a concrete class that was bound via interface.
     */
    public function testExtendInterfaceBinding(): void
    {
        $this->container->bind(IFeatureTarget::class, FeatureTargetImpl::class);

        $this->container->extend(IFeatureTarget::class, function ($obj) {
            $obj->extended = true;
            return $obj;
        });

        $instance = $this->container->make(IFeatureTarget::class);
        $this->assertTrue($instance->extended);
    }

    /**
     * Tests that extending an alias clears the cached instance of the underlying concrete class.
     * This covers the code:
     * if (is_string($concrete) and isset($this->instances[$concrete])) { unset($this->instances[$concrete]); }
     */
    public function testExtendClearsConcreteSingletonWhenExtendingAlias(): void
    {
        // 1. Bind 'alias' to a concrete class
        $this->container->bind('alias', FeatureTargetImpl::class);

        // 2. Register the concrete class as a Singleton.
        $this->container->singleton(FeatureTargetImpl::class, null);

        // 3. Resolve the CONCRETE class directly to populate the cache.
        $instance1 = $this->container->make(FeatureTargetImpl::class);

        // 4. Extend the ALIAS.
        // This should detect that the concrete class is already cached and clear it.
        $this->container->extend('alias', function ($obj) {
            $obj->extended = true;
            return $obj;
        });

        // 5. Resolve via the ALIAS again.
        // If the cache wasn't cleared, make() would return the old instance immediately
        // without applying the new extender.
        // Since it was cleared, it builds a new one and applies the extender.
        $instance2 = $this->container->make('alias');

        $this->assertNotSame($instance1, $instance2);
        $this->assertTrue($instance2->extended);
    }

    /**
     * Tests manually swapping an instance (mocking).
     */
    public function testInstanceSwapping(): void
    {
        $this->container->bind(IFeatureTarget::class, FeatureTargetImpl::class);

        $mock = new class implements IFeatureTarget {
            public function run()
            {
                return 'mocked';
            }
        };

        // Swap the implementation
        $this->container->instance(IFeatureTarget::class, $mock);

        $result = $this->container->make(IFeatureTarget::class);
        $this->assertSame($mock, $result);
        $this->assertEquals('mocked', $result->run());
    }

    /**
     * Tests that instance swapping correctly updates the class-to-instance mapping.
     */
    public function testInstanceRegistersClassNameMapping(): void
    {
        $obj = new FeatureTargetImpl();
        $this->container->instance('alias', $obj);

        // Should be retrievable by alias
        $this->assertSame($obj, $this->container->make('alias'));
        // Should be retrievable by class name
        $this->assertSame($obj, $this->container->make(FeatureTargetImpl::class));
    }

    /**
     * Tests that replacing an instance removes the old reverse mapping.
     */
    public function testInstanceReplacementClearsOldMapping(): void
    {
        $old = new FeatureTargetImpl();
        $this->container->instance('alias', $old);

        $new = new FeatureTargetImpl();
        $this->container->instance('alias', $new);

        $this->assertSame($new, $this->container->make('alias'));
        $this->assertSame($new, $this->container->make(FeatureTargetImpl::class));
    }

    /**
     * Tests that flush() resets the container state entirely.
     */
    public function testFlushClearsEverything(): void
    {
        $this->container->bind('foo', stdClass::class);
        $this->container->instance('bar', new stdClass());
        $this->container->tag('foo', 'tag');

        $this->container->flush();

        $this->assertFalse($this->container->has('foo'));
        $this->assertFalse($this->container->has('bar'));
        $this->assertEmpty($this->container->tagged('tag'));
    }
}


// --------------------------------------------------------------------
// HELPERS
// --------------------------------------------------------------------

interface IFeatureTarget
{
    public function run();
}


class FeatureTargetImpl implements IFeatureTarget
{
    public bool $extended = false;

    public function run()
    {
        return 'original';
    }
}