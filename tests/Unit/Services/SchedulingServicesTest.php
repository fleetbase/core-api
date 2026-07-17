<?php

use Fleetbase\Models\ScheduleItem;
use Fleetbase\Services\Scheduling\ConstraintService;
use Fleetbase\Support\Scheduling\ConstraintResult;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\PendingActivityLog;

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

class SchedulingConstraintFailingHandler
{
    public function validate(ScheduleItem $item): ConstraintResult
    {
        return ConstraintResult::fail([
            [
                'constraint_key' => 'rest_period',
                'message'        => 'Driver must rest before the next shift.',
                'assignee_uuid'  => $item->assignee_uuid,
            ],
        ]);
    }
}

class SchedulingConstraintSecondFailingHandler
{
    public function validate(ScheduleItem $item): ConstraintResult
    {
        return ConstraintResult::fail([
            [
                'constraint_key' => 'vehicle_capacity',
                'message'        => 'Vehicle capacity is exceeded.',
                'assignee_uuid'  => $item->assignee_uuid,
            ],
        ]);
    }
}

class SchedulingActivityFake
{
    public array $entries  = [];
    private array $current = [];

    public function performedOn($subject): self
    {
        $this->current['subject'] = $subject;

        return $this;
    }

    public function causedBy($user): self
    {
        $this->current['user'] = $user;

        return $this;
    }

    public function event(string $event): self
    {
        $this->current['event'] = $event;

        return $this;
    }

    public function withProperties(array $properties): self
    {
        $this->current['properties'] = $properties;

        return $this;
    }

    public function log(string $message): void
    {
        $this->current['message'] = $message;
        $this->entries[]          = $this->current;
        $this->current            = [];
    }
}

class SchedulingActivityLoggerFake extends ActivityLogger
{
    public function __construct(private SchedulingActivityFake $activityFake)
    {
    }

    public function performedOn(Model $model): static
    {
        $this->activityFake->performedOn($model);

        return $this;
    }

    public function causedBy(Model|int|string|null $modelOrId): static
    {
        $this->activityFake->causedBy($modelOrId);

        return $this;
    }

    public function event(string $event): static
    {
        $this->activityFake->event($event);

        return $this;
    }

    public function withProperties(mixed $properties): static
    {
        $this->activityFake->withProperties($properties);

        return $this;
    }

    public function log(string $description): ?ActivityContract
    {
        $this->activityFake->log($description);

        return null;
    }
}

class SchedulingPendingActivityLogFake extends PendingActivityLog
{
    public function __construct(private SchedulingActivityLoggerFake $activityLogger)
    {
    }

    public function useLog(?string $logName): self
    {
        return $this;
    }

    public function logger(): ActivityLogger
    {
        return $this->activityLogger;
    }
}

if (!function_exists('activity')) {
    function activity(): SchedulingActivityFake
    {
        return $GLOBALS['scheduling_activity_fake'];
    }
}

if (!function_exists('event')) {
    function event(mixed $event = null): mixed
    {
        return $event;
    }
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
    $item    = schedule_item_for_constraint_test('driver', 'driver-1');

    expect($service->validate($item))->toBe([])
        ->and($service->checkConstraint($item, 'max_hours'))->toBeTrue();
});

it('merges schedule constraint violations and reports failed constraint checks', function () {
    $container                           = bind_test_container();
    $activity                            = new SchedulingActivityFake();
    $GLOBALS['scheduling_activity_fake'] = $activity;
    $container->instance(PendingActivityLog::class, new SchedulingPendingActivityLogFake(new SchedulingActivityLoggerFake($activity)));
    $container->instance(SchedulingConstraintFailingHandler::class, new SchedulingConstraintFailingHandler());
    $container->instance(SchedulingConstraintSecondFailingHandler::class, new SchedulingConstraintSecondFailingHandler());

    $service = new ConstraintService();
    $service->register('driver', SchedulingConstraintFailingHandler::class);
    $service->register('driver', SchedulingConstraintSecondFailingHandler::class);

    $item = schedule_item_for_constraint_test('driver', 'driver-1');

    $violations = $service->validate($item);

    expect($violations)->toBe([
        [
            'constraint_key' => 'rest_period',
            'message'        => 'Driver must rest before the next shift.',
            'assignee_uuid'  => 'driver-1',
        ],
        [
            'constraint_key' => 'vehicle_capacity',
            'message'        => 'Vehicle capacity is exceeded.',
            'assignee_uuid'  => 'driver-1',
        ],
    ])
        ->and($service->checkConstraint($item, 'rest_period'))->toBeFalse()
        ->and($service->checkConstraint($item, 'vehicle_capacity'))->toBeFalse()
        ->and($service->checkConstraint($item, 'time_window'))->toBeTrue()
        ->and($activity->entries)->toHaveCount(4)
        ->and($activity->entries[0]['subject'])->toBe($item)
        ->and($activity->entries[0]['event'])->toBe('schedule.constraint_violated')
        ->and($activity->entries[0]['message'])->toBe('Schedule constraint violated')
        ->and($activity->entries[0]['properties'])->toBe(['violations' => $violations]);

    unset($GLOBALS['scheduling_activity_fake']);
});

it('resolves and runs registered schedule constraint handlers for matching assignee types', function () {
    $container = bind_test_container();
    $handler   = new SchedulingConstraintPassingHandler();
    $container->instance(SchedulingConstraintPassingHandler::class, $handler);
    $container->instance(SchedulingConstraintMissingValidateHandler::class, new SchedulingConstraintMissingValidateHandler());

    $service = new ConstraintService();
    $service->register('driver', SchedulingConstraintPassingHandler::class);
    $service->register('driver', SchedulingConstraintMissingValidateHandler::class);
    $service->register('vehicle', SchedulingConstraintPassingHandler::class);

    $driverItem  = schedule_item_for_constraint_test('driver', 'driver-1');
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
