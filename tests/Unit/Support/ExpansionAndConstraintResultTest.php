<?php

use Fleetbase\Build\Expansion as ExpansionContract;
use Fleetbase\Support\Expansion;
use Fleetbase\Support\Scheduling\ConstraintResult;
use Fleetbase\Traits\Expandable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Traits\Macroable;

class ExpansionAndConstraintResultExpansion implements ExpansionContract
{
    public static function target()
    {
        return ExpansionAndConstraintResultExpandableTarget::class;
    }
}

class ExpansionAndConstraintResultExpandableTarget
{
    use Expandable;
}

class ExpansionAndConstraintResultRuntimeTarget
{
    use Expandable;

    public string $prefix = 'target';
}

class ExpansionAndConstraintResultRuntimeExpansion
{
    public static int $importedInstanceCalls = 0;

    public static function target()
    {
        return ExpansionAndConstraintResultRuntimeTarget::class;
    }

    public static function importedInstanceClosure()
    {
        static::$importedInstanceCalls++;

        return function (string $suffix): string {
            return $this->prefix . ':' . $suffix;
        };
    }

    protected static function importedStaticClosure()
    {
        return static fn (int $left, int $right): int => $left + $right;
    }

    public static function ignoredNonClosure()
    {
        return 'not expandable';
    }
}

class ExpansionAndConstraintResultExpandableParent
{
    public function __call($method, $parameters)
    {
        return $method . ':' . implode(',', $parameters);
    }
}

class ExpansionAndConstraintResultExpandableChild extends ExpansionAndConstraintResultExpandableParent
{
    use Expandable;
}

class ExpansionAndConstraintResultInvalidExpansionTarget
{
    use Expandable;

    public static function isExpansion(string $name): bool
    {
        return $name === 'invalidExpansion';
    }

    public static function getExpansionClosure(string $name): mixed
    {
        return 'not a closure';
    }
}

class ExpansionAndConstraintResultExpandableModel extends EloquentModel
{
    use Expandable;

    protected $table = 'builder_expansion_records';

    public $timestamps = false;

    protected function protectedFallback(string $value): string
    {
        return 'protected:' . $value;
    }
}

class ExpansionAndConstraintResultMacroableTarget
{
    use Macroable;
}

class ExpansionAndConstraintResultPlainTarget
{
}

function expansion_constraint_result_database(): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);

    return $capsule;
}

test('expansion support detects expansion expandable and macroable targets', function () {
    expect(Expansion::isExpansion(new ExpansionAndConstraintResultExpansion()))->toBeTrue()
        ->and(Expansion::isExpansion(new stdClass()))->toBeFalse()
        ->and(Expansion::isExpandable(ExpansionAndConstraintResultExpandableTarget::class))->toBeTrue()
        ->and(Expansion::isExpandable(ExpansionAndConstraintResultPlainTarget::class))->toBeFalse()
        ->and(Expansion::isExpandable('Missing\\ExpansionTarget'))->toBeFalse()
        ->and(Expansion::isMacroable(ExpansionAndConstraintResultMacroableTarget::class))->toBeTrue()
        ->and(Expansion::isMacroable(ExpansionAndConstraintResultPlainTarget::class))->toBeFalse()
        ->and(Expansion::isMacroable('Missing\\MacroTarget'))->toBeFalse();
});

test('expandable trait registers direct and imported runtime methods', function () {
    bind_test_container();
    $added = new ReflectionProperty(ExpansionAndConstraintResultRuntimeTarget::class, 'added');
    $added->setAccessible(true);
    $added->setValue(null, []);
    ExpansionAndConstraintResultRuntimeExpansion::$importedInstanceCalls = 0;

    ExpansionAndConstraintResultRuntimeTarget::expand('directInstanceClosure', function (string $suffix): string {
        return $this->prefix . '-' . $suffix;
    });
    ExpansionAndConstraintResultRuntimeTarget::expand('directStaticClosure', static fn (int $left, int $right): int => $left * $right);

    $target = new ExpansionAndConstraintResultRuntimeTarget();

    expect(ExpansionAndConstraintResultRuntimeTarget::hasExpansion('directInstanceClosure'))->toBeTrue()
        ->and(ExpansionAndConstraintResultRuntimeTarget::isExpansion('directInstanceClosure'))->toBeTrue()
        ->and(ExpansionAndConstraintResultRuntimeTarget::getExpansionClosure('directInstanceClosure'))->toBeInstanceOf(Closure::class)
        ->and($target->directInstanceClosure('value'))->toBe('target-value')
        ->and($target->directStaticClosure(6, 7))->toBe(42);

    ExpansionAndConstraintResultRuntimeTarget::expand(ExpansionAndConstraintResultRuntimeExpansion::class);

    expect(ExpansionAndConstraintResultRuntimeTarget::hasExpansion('importedInstanceClosure'))->toBeTrue()
        ->and(ExpansionAndConstraintResultRuntimeTarget::hasExpansion('importedStaticClosure'))->toBeTrue()
        ->and(ExpansionAndConstraintResultRuntimeTarget::hasExpansion('ignoredNonClosure'))->toBeFalse()
        ->and(ExpansionAndConstraintResultRuntimeExpansion::$importedInstanceCalls)->toBe(1)
        ->and($target->importedInstanceClosure('hook'))->toBe('target:hook')
        ->and($target->importedStaticClosure(2, 5))->toBe(7);
});

test('expandable trait delegates invalid model and parent fallback calls predictably', function () {
    expansion_constraint_result_database();

    $model = new ExpansionAndConstraintResultExpandableModel();
    $query = $model->where('name', 'Alpha Fleet');

    expect(fn () => (new ExpansionAndConstraintResultInvalidExpansionTarget())->invalidExpansion())
        ->toThrow(RuntimeException::class, 'Invalid closure provided')
        ->and($model->protectedFallback('value'))->toBe('protected:value')
        ->and($query)->toBeInstanceOf(Builder::class)
        ->and($query->toSql())->toContain('where')
        ->and((new ExpansionAndConstraintResultExpandableChild())->missingParentMethod('one', 'two'))->toBe('missingParentMethod:one,two');
});

test('constraint result exposes pass fail and violation contracts', function () {
    $passed = ConstraintResult::pass();
    $failed = ConstraintResult::fail([
        ['code' => 'driver_unavailable', 'message' => 'Driver is unavailable'],
        ['code' => 'vehicle_capacity', 'message' => 'Vehicle capacity exceeded'],
    ]);

    expect($passed->passed())->toBeTrue()
        ->and($passed->failed())->toBeFalse()
        ->and($passed->getViolations())->toBe([])
        ->and($failed->passed())->toBeFalse()
        ->and($failed->failed())->toBeTrue()
        ->and($failed->getViolations())->toBe([
            ['code' => 'driver_unavailable', 'message' => 'Driver is unavailable'],
            ['code' => 'vehicle_capacity', 'message' => 'Vehicle capacity exceeded'],
        ]);
});
