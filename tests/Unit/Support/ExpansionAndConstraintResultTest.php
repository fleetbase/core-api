<?php

use Fleetbase\Build\Expansion as ExpansionContract;
use Fleetbase\Support\Expansion;
use Fleetbase\Support\Scheduling\ConstraintResult;
use Fleetbase\Traits\Expandable;
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

class ExpansionAndConstraintResultMacroableTarget
{
    use Macroable;
}

class ExpansionAndConstraintResultPlainTarget
{
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
