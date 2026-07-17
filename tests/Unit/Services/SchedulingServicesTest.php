<?php

use Fleetbase\Models\ScheduleItem;
use Fleetbase\Services\Scheduling\ConstraintService;
use Fleetbase\Support\Scheduling\ConstraintResult;

class SchedulingConstraintPassingHandler
{
    public array $items = [];

    public function validate(ScheduleItem $item): ConstraintResult
    {
        $this->items[] = $item;

        return ConstraintResult::pass();
    }
}

class SchedulingConstraintMissingValidateHandler
{
}

function schedule_item_for_constraint_test(string $assigneeType, string $assigneeUuid): ScheduleItem
{
    $item = new ScheduleItem();
    $item->setRawAttributes([
        'assignee_type' => $assigneeType,
        'assignee_uuid' => $assigneeUuid,
    ], true);

    return $item;
}

it('returns no schedule constraint violations when no handler is registered for the assignee type', function () {
    bind_test_container();

    $service = new ConstraintService();
    $item = schedule_item_for_constraint_test('driver', 'driver-1');

    expect($service->validate($item))->toBe([])
        ->and($service->checkConstraint($item, 'max_hours'))->toBeTrue();
});

it('resolves and runs registered schedule constraint handlers for matching assignee types', function () {
    $container = bind_test_container();
    $handler = new SchedulingConstraintPassingHandler();
    $container->instance(SchedulingConstraintPassingHandler::class, $handler);
    $container->instance(SchedulingConstraintMissingValidateHandler::class, new SchedulingConstraintMissingValidateHandler());

    $service = new ConstraintService();
    $service->register('driver', SchedulingConstraintPassingHandler::class);
    $service->register('driver', SchedulingConstraintMissingValidateHandler::class);
    $service->register('vehicle', SchedulingConstraintPassingHandler::class);

    $driverItem = schedule_item_for_constraint_test('driver', 'driver-1');
    $vehicleItem = schedule_item_for_constraint_test('vehicle', 'vehicle-1');

    expect($service->validate($driverItem))->toBe([])
        ->and($service->checkConstraint($driverItem, 'rest_period'))->toBeTrue()
        ->and($handler->items)->toHaveCount(2)
        ->and($handler->items[0])->toBe($driverItem)
        ->and($handler->items[1])->toBe($driverItem);

    expect($service->validate($vehicleItem))->toBe([])
        ->and($handler->items)->toHaveCount(3)
        ->and($handler->items[2])->toBe($vehicleItem);
});
