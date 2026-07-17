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
