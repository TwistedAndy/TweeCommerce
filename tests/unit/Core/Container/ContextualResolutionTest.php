<?php

namespace Tests\Unit\Core\Container;

use App\Core\Container\Container;
use App\Core\Container\ContainerException;
use App\Core\Container\NotFoundException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Validates the Container's ability to resolve dependencies based on calling context.
 */
class ContextualResolutionTest extends CIUnitTestCase
{
    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(null);
        $this->container = Container::getInstance();
    }

    /**
     * Verifies that bind() correctly maps an interface to a concrete class.
     */
    public function testBindRegistersImplementation(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);
        $this->assertInstanceOf(ContextWorkerA::class, $this->container->make(IContextWorker::class));
    }

    /**
     * Ensures bindIf() does not overwrite a previously registered service.
     */
    public function testBindIfRespectsExistingBinding(): void
    {
        $this->container->bind('service', fn() => (object) ['val' => 'original']);
        $this->container->bindIf('service', fn() => (object) ['val' => 'new']);

        $this->assertEquals('original', $this->container->make('service')->val);
    }

    /**
     * Ensures bindIf() registers a service only if the key is currently empty.
     */
    public function testBindIfRegistersWhenKeyIsMissing(): void
    {
        $this->container->bindIf('missing', fn() => (object) ['val' => 'found']);
        $this->assertEquals('found', $this->container->make('missing')->val);
    }

    /**
     * Verifies that a class can be bound to itself for standard resolution.
     */
    public function testBindSelfResolution(): void
    {
        $this->container->bind(ContextWorkerA::class);
        $this->assertInstanceOf(ContextWorkerA::class, $this->container->make(ContextWorkerA::class));
    }

    /**
     * Tests that two classes can receive different implementations of the same interface.
     */
    public function testContainerCanInjectDifferentImplementationsDependingOnContext(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);

        // Context 1: ClassX gets default (ContextWorkerA)
        $this->container->bindWhen(ClassX::class, IContextWorker::class, ContextWorkerA::class);

        // Context 2: ClassY gets specific (ContextWorkerB)
        $this->container->bindWhen(ClassY::class, IContextWorker::class, ContextWorkerB::class);

        $one = $this->container->make(ClassX::class);
        $two = $this->container->make(ClassY::class);

        $this->assertInstanceOf(ContextWorkerA::class, $one->worker);
        $this->assertInstanceOf(ContextWorkerB::class, $two->worker);
    }

    /**
     * Validates that specific contextual rules take precedence over global bindings.
     */
    public function testContextualOverridesGlobalBinding(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);
        $this->container->bindWhen(ClassY::class, IContextWorker::class, ContextWorkerB::class);

        $this->assertInstanceOf(ContextWorkerB::class, $this->container->make(ClassY::class)->worker);
    }

    /**
     * Tests providing a closure as the concrete implementation in a contextual rule.
     */
    public function testContextualBindingWithClosure(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);

        $this->container->bindWhen(ClassX::class, IContextWorker::class, function () {
            return new ContextWorkerB();
        });

        $this->assertInstanceOf(ContextWorkerB::class, $this->container->make(ClassX::class)->worker);
    }

    /**
     * Tests that defining a contextual binding does not affect the default (global) resolution.
     */
    public function testContextualBindingDoesntOverrideNonContextualResolution(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);
        $this->container->bindWhen(ClassY::class, IContextWorker::class, ContextWorkerB::class);

        // ClassX has no context, should get Global (A)
        $this->assertInstanceOf(ContextWorkerA::class, $this->container->make(ClassX::class)->worker);
        // ClassY has context, should get Specific (B)
        $this->assertInstanceOf(ContextWorkerB::class, $this->container->make(ClassY::class)->worker);
    }

    /**
     * Tests that contextual bindings support alias chains (recursion).
     * Logic: Context -> 'alias_1' -> 'alias_2' -> Concrete.
     */
    public function testContextualBindingResolvesAliasChains(): void
    {
        // Setup alias chain: alias_1 -> alias_2 -> Concrete
        $this->container->bind('alias_2', ContextWorkerB::class);
        $this->container->bind('alias_1', 'alias_2');

        // Bind Context: ClassY -> Interface -> alias_1
        $this->container->bindWhen(ClassY::class, IContextWorker::class, 'alias_1');

        $two = $this->container->make(ClassY::class);

        $this->assertInstanceOf(ContextWorkerB::class, $two->worker);
    }

    /**
     * Tests that contextual bindings override existing Singleton bindings.
     * The context should force a new instance (or the specific singleton) instead of the global singleton.
     */
    public function testContextualBindingOverridesGlobalSingleton(): void
    {
        // Global Singleton: WorkerA
        $this->container->singleton(IContextWorker::class, ContextWorkerA::class);

        // Context: ClassY -> WorkerB
        $this->container->bindWhen(ClassY::class, IContextWorker::class, ContextWorkerB::class);

        $one = $this->container->make(ClassX::class);
        $two = $this->container->make(ClassY::class);

        $this->assertInstanceOf(ContextWorkerA::class, $one->worker);
        $this->assertInstanceOf(ContextWorkerB::class, $two->worker);

        // Verify we got the singleton instance for the global request
        $oneAgain = $this->container->make(ClassX::class);
        $this->assertSame($one->worker, $oneAgain->worker);
    }

    /**
     * Tests that the context is strictly shallow (immediate parent only).
     * Rules defined for Parent -> Child do not cascade down to Child -> GrandChild.
     */
    public function testContextIsStrictlyShallow(): void
    {
        // Global: Interface -> WorkerA
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);

        // Rule: NestedParent (Grandparent) should trigger WorkerB when resolving its dependencies.
        // But NestedParent depends on NestedChild. NestedChild depends on Interface.
        // This rule says: When building NestedParent, if it needs Interface, give WorkerB.
        // It DOES NOT say: When building dependencies OF NestedParent, give WorkerB.
        $this->container->bindWhen(NestedParent::class, IContextWorker::class, ContextWorkerB::class);

        $parent = $this->container->make(NestedParent::class);

        // Since NestedParent doesn't directly need Interface, the rule is unused.
        // NestedChild (child) resolves Interface using Global binding (WorkerA).
        $this->assertInstanceOf(ContextWorkerA::class, $parent->child->worker);
    }

    /**
     * Tests that we can bind multiple distinct dependencies contextually on the same class.
     */
    public function testMultipleContextualBindingsOnSameClass(): void
    {
        // Bind Interface1 -> WorkerB
        $this->container->bindWhen(DualContext::class, IContextWorker::class, ContextWorkerB::class);

        // Bind Interface2 -> WorkerB (using a different interface for the test)
        $this->container->bindWhen(DualContext::class, ISecondWorker::class, ContextWorkerB::class);

        // Manually providing the 'other' param as it is a primitive/untyped in the stub
        $instance = $this->container->make(DualContext::class, ['other' => 'primitive']);

        $this->assertInstanceOf(ContextWorkerB::class, $instance->worker);
    }

    /**
     * Tests that we can mix global and contextual resolution in the same constructor.
     */
    public function testMixedGlobalAndContextualResolution(): void
    {
        // Global: Interface1 -> WorkerA
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);

        // Global: Interface2 -> WorkerA
        $this->container->bind(ISecondWorker::class, ContextWorkerA::class);

        // Context: Class -> Interface1 -> WorkerB
        $this->container->bindWhen(MixedContext::class, IContextWorker::class, ContextWorkerB::class);

        $instance = $this->container->make(MixedContext::class);

        // worker1 uses Context (WorkerB)
        $this->assertInstanceOf(ContextWorkerB::class, $instance->worker1);
        // worker2 uses Global (WorkerA)
        $this->assertInstanceOf(ContextWorkerA::class, $instance->worker2);
    }

    /**
     * Tests contextual dependency injection into a class method via call().
     */
    public function testCallMethodContextual(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);

        // Context: When ContextWorkerA calls a method needing IContextWorker, give it WorkerB
        $this->container->bindWhen(ContextWorkerA::class, IContextWorker::class, ContextWorkerB::class);

        $subject = new ContextWorkerA();

        // ContextWorkerA::handle(IContextWorker $worker)
        $result = $this->container->call([$subject, 'handle']);

        $this->assertInstanceOf(ContextWorkerB::class, $result);
    }

    /**
     * Tests contextual resolution when using the Class@Method string syntax in call().
     */
    public function testCallAtSyntaxContextual(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);

        $callString = ContextWorkerA::class . '@handle';

        // Bind using the exact string that will be passed to call()
        $this->container->bindWhen($callString, IContextWorker::class, ContextWorkerB::class);

        $result = $this->container->call($callString);
        $this->assertInstanceOf(ContextWorkerB::class, $result);
    }

    /**
     * Tests context injection for static method calls via call().
     */
    public function testContextualBindingForStaticMethodCalls(): void
    {
        $contextKey = ContextWorkerA::class . '::staticHandle';

        $this->container->bindWhen(
            $contextKey,
            IContextWorker::class,
            ContextWorkerB::class
        );

        $result = $this->container->call([ContextWorkerA::class, 'staticHandle']);

        $this->assertInstanceOf(ContextWorkerB::class, $result);
    }

    /**
     * Tests manual context passing to the make() method.
     * This verifies that passing a string as the 3rd argument triggers context binding.
     */
    public function testMakeAcceptsManualContext(): void
    {
        // Global Binding -> ContextWorkerA
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);

        // Context Binding for 'CustomContextName' -> ContextWorkerB
        $this->container->bindWhen('CustomContextName', IContextWorker::class, ContextWorkerB::class);

        // 1. Resolve normally (Expect A)
        $default = $this->container->make(IContextWorker::class);
        $this->assertInstanceOf(ContextWorkerA::class, $default);

        // 2. Resolve with manual context (Expect B)
        $manual = $this->container->make(IContextWorker::class, [], 'CustomContextName');
        $this->assertInstanceOf(ContextWorkerB::class, $manual);
    }

    /**
     * Tests resolving a class with manual parameter overrides using make().
     */
    public function testManualParameterOverride(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);

        $instance = $this->container->make(ClassX::class, ['worker' => new ContextWorkerB()]);

        $this->assertInstanceOf(ContextWorkerB::class, $instance->worker);
    }

    /**
     * Tests that a strict circular alias loop is detected and throws ContainerException.
     */
    public function testCircularAliasDetection(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular binding detected');

        $this->container->bind('A', 'B');
        $this->container->bind('B', 'C');
        $this->container->bind('C', 'A'); // Loop

        $this->container->make('A');
    }

    /**
     * Tests that resolving a non-existent class throws NotFoundException.
     */
    public function testResolutionFailureThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->make('NonExistentClass');
    }

    /**
     * Confirms that clearing an instance cache does not remove its contextual binding rules.
     */
    public function testForgetInstanceClearsObjectButRetainsRules(): void
    {
        $this->container->bind(IContextWorker::class, ContextWorkerA::class);
        $this->container->bindWhen(ClassX::class, IContextWorker::class, ContextWorkerB::class);

        $obj1 = $this->container->make(ClassX::class);
        $this->assertInstanceOf(ContextWorkerB::class, $obj1->worker);

        $this->container->forgetInstance(ClassX::class);

        $obj2 = $this->container->make(ClassX::class);
        $this->assertNotSame($obj1, $obj2);
        $this->assertInstanceOf(ContextWorkerB::class, $obj2->worker);
    }
}


// --------------------------------------------------------------------
// HELPER CLASSES
// --------------------------------------------------------------------

interface IContextWorker
{
}


interface ISecondWorker
{
}


class ContextWorkerA implements IContextWorker, ISecondWorker
{
    public function handle(IContextWorker $worker)
    {
        return $worker;
    }

    public static function staticHandle(IContextWorker $worker)
    {
        return $worker;
    }
}


class ContextWorkerB implements IContextWorker, ISecondWorker
{
}


class ClassX
{
    public function __construct(public IContextWorker $worker)
    {
    }
}


class ClassY
{
    public function __construct(public IContextWorker $worker)
    {
    }
}


class NestedChild
{
    public function __construct(public IContextWorker $worker)
    {
    }
}


class NestedParent
{
    public function __construct(public NestedChild $child)
    {
    }
}


class DualContext
{
    public function __construct(public IContextWorker $worker, public $other)
    {
    }
}


class MixedContext
{
    public function __construct(public IContextWorker $worker1, public ISecondWorker $worker2)
    {
    }
}