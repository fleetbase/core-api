<?php

use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleException;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\ScheduleTemplate;
use Fleetbase\Services\Scheduling\ScheduleService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\PendingActivityLog;

if (!class_exists('RRule\\RRule')) {
    eval(<<<'PHP'
        namespace RRule;

        class RRuleException extends \Exception
        {
        }

        class RRule implements \IteratorAggregate
        {
            private \DateTimeImmutable $dtStart;
            private string $frequency = 'DAILY';
            private int $count = 1;
            private array $byDays = [];

            public function __construct(string $definition)
            {
                if (str_contains($definition, 'THROW_INVALID_ARGUMENT')) {
                    throw new \InvalidArgumentException('Invalid RRULE definition.');
                }

                if (str_contains($definition, 'THROW_RRULE_EXCEPTION')) {
                    throw new RRuleException('Invalid recurrence rule.');
                }

                [$dtStartLine, $ruleLine] = explode("\n", $definition, 2);

                if (str_starts_with($dtStartLine, 'DTSTART;TZID=')) {
                    [, $datePart] = explode(':', $dtStartLine, 2);
                    [, $timezone] = explode('=', explode(':', $dtStartLine, 2)[0], 2);
                    $this->dtStart = new \DateTimeImmutable($datePart, new \DateTimeZone($timezone));
                } else {
                    [, $datePart] = explode(':', $dtStartLine, 2);
                    $this->dtStart = new \DateTimeImmutable(rtrim($datePart, 'Z'), new \DateTimeZone('UTC'));
                }

                $rule = [];
                foreach (explode(';', preg_replace('/^RRULE:/', '', trim($ruleLine))) as $part) {
                    [$key, $value] = explode('=', $part, 2);
                    $rule[$key] = $value;
                }

                $this->frequency = $rule['FREQ'] ?? 'DAILY';
                $this->count = (int) ($rule['COUNT'] ?? 1);
                $this->byDays = isset($rule['BYDAY']) ? explode(',', $rule['BYDAY']) : [];
            }

            public function getIterator(): \Traversable
            {
                $emitted = 0;
                $cursor = $this->dtStart;
                $weekdayMap = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

                while ($emitted < $this->count) {
                    $matchesByDay = empty($this->byDays) || in_array((int) $cursor->format('N'), array_map(fn ($day) => $weekdayMap[$day] ?? 0, $this->byDays), true);

                    if ($matchesByDay) {
                        yield $cursor;
                        $emitted++;
                    }

                    $cursor = match ($this->frequency) {
                        'WEEKLY' => empty($this->byDays) ? $cursor->modify('+1 week') : $cursor->modify('+1 day'),
                        default => $cursor->modify('+1 day'),
                    };
                }
            }
        }
    PHP);
}

if (!function_exists('Fleetbase\\Services\\Scheduling\\event')) {
    eval(<<<'PHP'
        namespace Fleetbase\Services\Scheduling;

        function event(mixed $event = null): mixed
        {
            return $event;
        }
    PHP);
}

class ScheduleServiceTaggedCacheFake
{
    public function tags(array $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return true;
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}

class ScheduleServiceResponseCacheFake
{
    public function clear(): void
    {
    }
}

class ScheduleServiceMaterializeAllFake extends ScheduleService
{
    public function materializeSchedule(Schedule $schedule, ?\Carbon\Carbon $horizon = null): int
    {
        if ($schedule->uuid === 'schedule-error') {
            throw new RuntimeException('Materialization failed');
        }

        return parent::materializeSchedule($schedule, $horizon);
    }
}

class ScheduleServiceLogFake
{
    public array $entries = [];

    public function debug(string $message, array $context = []): void
    {
        $this->entries[] = ['debug', $message, $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['error', $message, $context];
    }
}

class ScheduleServiceActivityFake
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

    public function withProperties(mixed $properties): self
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

class ScheduleServiceActivityLoggerFake extends ActivityLogger
{
    public function __construct(private ScheduleServiceActivityFake $activityFake)
    {
    }

    public function performedOn(EloquentModel $model): static
    {
        $this->activityFake->performedOn($model);

        return $this;
    }

    public function causedBy(EloquentModel|int|string|null $modelOrId): static
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

class ScheduleServicePendingActivityLogFake extends PendingActivityLog
{
    public function __construct(private ScheduleServiceActivityLoggerFake $activityLogger)
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

function schedule_service_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'              => false,
        'database.default'               => 'testing',
        'database.connections.testing'   => $connectionConfig,
        'database.connections.mysql'     => $connectionConfig,
        'fleetbase.connection.db'        => 'testing',
        'activitylog.default_log_name'   => 'default',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    $container->instance('responsecache', new ScheduleServiceResponseCacheFake());
    $container->instance('log', new ScheduleServiceLogFake());
    Cache::swap(new ScheduleServiceTaggedCacheFake());
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('schedules', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();
        $table->string('timezone')->nullable();
        $table->string('status')->nullable();
        $table->dateTime('last_materialized_at')->nullable();
        $table->date('materialization_horizon')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('schedule_items', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('schedule_uuid')->nullable();
        $table->string('template_uuid')->nullable();
        $table->string('assignee_type')->nullable();
        $table->string('assignee_uuid')->nullable();
        $table->string('resource_type')->nullable();
        $table->string('resource_uuid')->nullable();
        $table->dateTime('start_at')->nullable();
        $table->dateTime('end_at')->nullable();
        $table->integer('duration')->nullable();
        $table->dateTime('break_start_at')->nullable();
        $table->dateTime('break_end_at')->nullable();
        $table->string('status')->nullable();
        $table->boolean('is_exception')->default(false);
        $table->date('exception_for_date')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('schedule_exceptions', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('schedule_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->dateTime('start_at')->nullable();
        $table->dateTime('end_at')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('reason')->nullable();
        $table->string('notes')->nullable();
        $table->string('reviewed_by_uuid')->nullable();
        $table->dateTime('reviewed_at')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('schedule_templates', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('schedule_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('start_time')->nullable();
        $table->string('end_time')->nullable();
        $table->integer('duration')->nullable();
        $table->integer('break_duration')->nullable();
        $table->text('rrule')->nullable();
        $table->string('color')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

function schedule_service_bind_activity(): ScheduleServiceActivityFake
{
    $activity = new ScheduleServiceActivityFake();
    app()->instance(PendingActivityLog::class, new ScheduleServicePendingActivityLogFake(new ScheduleServiceActivityLoggerFake($activity)));

    return $activity;
}

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

it('creates updates and deletes schedules with scoped audit and lifecycle events', function () {
    schedule_service_database();
    $activity = schedule_service_bind_activity();
    $service  = new ScheduleService();
    Carbon::setTestNow(Carbon::parse('2026-07-19 09:00:00', 'UTC'));

    $schedule = $service->createSchedule([
        'uuid'         => 'schedule-lifecycle',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'name'         => 'Morning dispatch',
        'timezone'     => 'UTC',
        'status'       => 'draft',
    ]);
    $updated = $service->updateSchedule($schedule, [
        'name'   => 'Morning dispatch updated',
        'status' => 'active',
    ]);

    DB::table('schedule_items')->insert([
        'uuid'          => 'item-delete-cascade',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => $schedule->uuid,
        'assignee_type' => 'driver',
        'assignee_uuid' => 'driver-1',
        'start_at'      => '2026-07-20 09:00:00',
        'end_at'        => '2026-07-20 17:00:00',
        'status'        => 'scheduled',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    DB::table('schedule_templates')->insert([
        'uuid'          => 'template-delete-cascade',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => $schedule->uuid,
        'subject_type'  => 'driver',
        'subject_uuid'  => 'driver-1',
        'name'          => 'Template to delete',
        'rrule'         => 'FREQ=DAILY;COUNT=1',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    DB::table('schedule_exceptions')->insert([
        'uuid'          => 'exception-delete-cascade',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => $schedule->uuid,
        'subject_type'  => 'driver',
        'subject_uuid'  => 'driver-1',
        'start_at'      => '2026-07-21 00:00:00',
        'end_at'        => '2026-07-21 23:59:59',
        'type'          => 'time_off',
        'status'        => 'pending',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $deleted = $service->deleteSchedule($updated);

    expect($schedule)->toBeInstanceOf(Schedule::class)
        ->and($updated->name)->toBe('Morning dispatch updated')
        ->and($updated->status)->toBe('active')
        ->and($deleted)->toBeTrue()
        ->and(Schedule::withTrashed()->find($schedule->uuid)->trashed())->toBeTrue()
        ->and(ScheduleItem::withTrashed()->find('item-delete-cascade')->trashed())->toBeTrue()
        ->and(ScheduleTemplate::withTrashed()->find('template-delete-cascade')->trashed())->toBeTrue()
        ->and(ScheduleException::withTrashed()->find('exception-delete-cascade')->trashed())->toBeTrue()
        ->and($activity->entries)->toHaveCount(3)
        ->and(array_column($activity->entries, 'event'))->toBe([
            'schedule.created',
            'schedule.updated',
            'schedule.deleted',
        ])
        ->and(array_column($activity->entries, 'message'))->toBe([
            'Schedule created',
            'Schedule updated',
            'Schedule deleted',
        ]);
});

it('creates updates assigns and deletes schedule items with parent activation and lifecycle audits', function () {
    $capsule  = schedule_service_database();
    $activity = schedule_service_bind_activity();
    $service  = new ScheduleService();

    $capsule->getConnection()->table('schedules')->insert([
        'uuid'         => 'schedule-draft',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'name'         => 'Draft schedule',
        'status'       => 'draft',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $item = $service->createScheduleItem([
        'uuid'          => 'item-lifecycle',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => 'schedule-draft',
        'start_at'      => '2026-07-20 09:00:00',
        'end_at'        => '2026-07-20 17:00:00',
        'status'        => 'scheduled',
    ]);
    $updated = $service->updateScheduleItem($item, [
        'status' => 'in_progress',
    ]);
    $assigned = $service->assignScheduleItem($updated, 'driver', 'driver-99');
    $deleted  = $service->deleteScheduleItem($assigned);

    expect($item)->toBeInstanceOf(ScheduleItem::class)
        ->and(Schedule::find('schedule-draft')->status)->toBe('active')
        ->and($updated->status)->toBe('in_progress')
        ->and($assigned->assignee_type)->toBe('\Fleetbase\Models\Driver')
        ->and($assigned->assignee_uuid)->toBe('driver-99')
        ->and($deleted)->toBeTrue()
        ->and(ScheduleItem::withTrashed()->find($item->uuid)->trashed())->toBeTrue()
        ->and(array_column($activity->entries, 'event'))->toBe([
            'schedule_item.created',
            'schedule_item.updated',
            'schedule_item.assigned',
            'schedule_item.deleted',
        ])
        ->and($activity->entries[2]['properties'])->toBe([
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-99',
        ]);
});

it('creates and rejects schedule exceptions with review audit state', function () {
    schedule_service_database();
    $activity = schedule_service_bind_activity();
    $service  = new ScheduleService();
    Carbon::setTestNow(Carbon::parse('2026-07-19 10:30:00', 'UTC'));

    $exception = $service->createException([
        'uuid'         => 'exception-lifecycle',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'start_at'     => '2026-07-22 00:00:00',
        'end_at'       => '2026-07-22 23:59:59',
        'type'         => 'time_off',
        'status'       => 'pending',
        'reason'       => 'Personal appointment',
    ]);
    $rejected = $service->rejectException($exception, 'reviewer-1');

    expect($exception)->toBeInstanceOf(ScheduleException::class)
        ->and($rejected->status)->toBe('rejected')
        ->and($rejected->reviewed_by_uuid)->toBe('reviewer-1')
        ->and($rejected->reviewed_at->toDateTimeString())->toBe('2026-07-19 10:30:00')
        ->and(array_column($activity->entries, 'event'))->toBe([
            'schedule_exception.created',
            'schedule_exception.rejected',
        ])
        ->and($activity->entries[0]['properties']['reason'])->toBe('Personal appointment')
        ->and($activity->entries[1]['message'])->toBe('Schedule exception rejected');
});

it('approves exceptions and cancels only overlapping incomplete schedule items for the same subject', function () {
    $capsule  = schedule_service_database();
    $activity = schedule_service_bind_activity();
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));

    $capsule->getConnection()->table('schedule_exceptions')->insert([
        'uuid'          => 'exception-1',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => 'schedule-1',
        'subject_type'  => 'driver',
        'subject_uuid'  => 'driver-1',
        'start_at'      => '2026-06-20 00:00:00',
        'end_at'        => '2026-06-22 23:59:59',
        'type'          => 'time_off',
        'status'        => 'pending',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $capsule->getConnection()->table('schedule_items')->insert([
        [
            'uuid'          => 'item-overlap',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-1',
            'template_uuid' => 'template-1',
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-1',
            'start_at'      => '2026-06-21 09:00:00',
            'end_at'        => '2026-06-21 17:00:00',
            'status'        => 'scheduled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'item-completed',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-1',
            'template_uuid' => 'template-1',
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-1',
            'start_at'      => '2026-06-21 18:00:00',
            'end_at'        => '2026-06-21 20:00:00',
            'status'        => 'completed',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'item-outside-window',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-1',
            'template_uuid' => 'template-1',
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-1',
            'start_at'      => '2026-06-24 09:00:00',
            'end_at'        => '2026-06-24 17:00:00',
            'status'        => 'scheduled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'item-other-driver',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-2',
            'template_uuid' => 'template-2',
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-2',
            'start_at'      => '2026-06-21 09:00:00',
            'end_at'        => '2026-06-21 17:00:00',
            'status'        => 'scheduled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
    ]);

    $exception = ScheduleException::find('exception-1');
    $approved  = (new ScheduleService())->approveException($exception, 'reviewer-1');

    expect($approved->status)->toBe('approved')
        ->and($approved->reviewed_by_uuid)->toBe('reviewer-1')
        ->and($approved->reviewed_at->toDateTimeString())->toBe('2026-06-15 12:00:00')
        ->and(ScheduleItem::find('item-overlap')->status)->toBe('cancelled')
        ->and(ScheduleItem::find('item-completed')->status)->toBe('completed')
        ->and(ScheduleItem::find('item-outside-window')->status)->toBe('scheduled')
        ->and(ScheduleItem::find('item-other-driver')->status)->toBe('scheduled')
        ->and($activity->entries)->toHaveCount(1)
        ->and($activity->entries[0]['subject']->uuid)->toBe('exception-1')
        ->and($activity->entries[0]['event'])->toBe('schedule_exception.approved')
        ->and($activity->entries[0]['message'])->toBe('Schedule exception approved');
});

it('returns no active shift when an approved exception covers the requested date', function () {
    $capsule = schedule_service_database();
    $service = new ScheduleService();

    $capsule->getConnection()->table('schedule_exceptions')->insert([
        'uuid'         => 'exception-1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'start_at'     => '2026-07-10 00:00:00',
        'end_at'       => '2026-07-10 23:59:59',
        'type'         => 'holiday',
        'status'       => 'approved',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $capsule->getConnection()->table('schedule_items')->insert([
        'uuid'          => 'item-1',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => 'schedule-1',
        'assignee_type' => 'driver',
        'assignee_uuid' => 'driver-1',
        'start_at'      => '2026-07-10 09:00:00',
        'end_at'        => '2026-07-10 17:00:00',
        'status'        => 'scheduled',
        'is_exception'  => false,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect($service->getActiveShiftFor('driver', 'driver-1', Carbon::parse('2026-07-10 12:00:00', 'UTC')))->toBeNull();
});

it('returns the earliest active shift for a date while ignoring cancelled and completed items', function () {
    $capsule = schedule_service_database();
    $service = new ScheduleService();

    $capsule->getConnection()->table('schedule_items')->insert([
        [
            'uuid'          => 'item-cancelled',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-1',
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-1',
            'start_at'      => '2026-07-11 07:00:00',
            'end_at'        => '2026-07-11 08:00:00',
            'status'        => 'cancelled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'item-later',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-1',
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-1',
            'start_at'      => '2026-07-11 12:00:00',
            'end_at'        => '2026-07-11 18:00:00',
            'status'        => 'scheduled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'item-earliest',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-1',
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-1',
            'start_at'      => '2026-07-11 09:00:00',
            'end_at'        => '2026-07-11 11:00:00',
            'status'        => 'scheduled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'item-completed',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-1',
            'assignee_type' => 'driver',
            'assignee_uuid' => 'driver-1',
            'start_at'      => '2026-07-11 06:00:00',
            'end_at'        => '2026-07-11 07:00:00',
            'status'        => 'completed',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
    ]);

    $shift = $service->getActiveShiftFor('driver', 'driver-1', Carbon::parse('2026-07-11', 'UTC'));

    expect($shift)->toBeInstanceOf(ScheduleItem::class)
        ->and($shift->uuid)->toBe('item-earliest');
});

it('filters schedules and exceptions for subjects with status type and date windows', function () {
    $capsule = schedule_service_database();
    $service = new ScheduleService();

    $capsule->getConnection()->table('schedules')->insert([
        [
            'uuid'         => 'schedule-active-window',
            'company_uuid' => 'company-1',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'name'         => 'Active schedule',
            'start_date'   => '2026-08-01',
            'end_date'     => '2026-08-31',
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'schedule-draft',
            'company_uuid' => 'company-1',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'name'         => 'Draft schedule',
            'start_date'   => '2026-08-01',
            'end_date'     => '2026-08-31',
            'status'       => 'draft',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'schedule-other-subject',
            'company_uuid' => 'company-1',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-2',
            'name'         => 'Other schedule',
            'start_date'   => '2026-08-01',
            'end_date'     => '2026-08-31',
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);
    $capsule->getConnection()->table('schedule_exceptions')->insert([
        [
            'uuid'         => 'exception-match',
            'company_uuid' => 'company-1',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-08-05 00:00:00',
            'end_at'       => '2026-08-06 00:00:00',
            'type'         => 'sick',
            'status'       => 'approved',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'exception-wrong-type',
            'company_uuid' => 'company-1',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-08-05 00:00:00',
            'end_at'       => '2026-08-06 00:00:00',
            'type'         => 'holiday',
            'status'       => 'approved',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'exception-outside-window',
            'company_uuid' => 'company-1',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-09-05 00:00:00',
            'end_at'       => '2026-09-06 00:00:00',
            'type'         => 'sick',
            'status'       => 'approved',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);
    $capsule->getConnection()->table('schedule_items')->insert([
        [
            'uuid'          => 'item-match',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-active-window',
            'assignee_type' => Schedule::class,
            'assignee_uuid' => 'schedule-active-window',
            'start_at'      => '2026-08-05 09:00:00',
            'end_at'        => '2026-08-05 17:00:00',
            'status'        => 'scheduled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'item-wrong-status',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-active-window',
            'assignee_type' => Schedule::class,
            'assignee_uuid' => 'schedule-active-window',
            'start_at'      => '2026-08-06 09:00:00',
            'end_at'        => '2026-08-06 17:00:00',
            'status'        => 'cancelled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'item-outside-window',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-active-window',
            'assignee_type' => Schedule::class,
            'assignee_uuid' => 'schedule-active-window',
            'start_at'      => '2026-09-05 09:00:00',
            'end_at'        => '2026-09-05 17:00:00',
            'status'        => 'scheduled',
            'is_exception'  => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
    ]);

    $schedules = $service->getSchedulesForSubject('driver', 'driver-1', [
        'status'     => 'active',
        'start_date' => '2026-08-15',
        'end_date'   => '2026-08-20',
    ]);
    $exceptions = $service->getExceptionsForSubject('driver', 'driver-1', [
        'status'   => 'approved',
        'type'     => 'sick',
        'start_at' => '2026-08-01 00:00:00',
        'end_at'   => '2026-08-31 23:59:59',
    ]);
    $items = $service->getScheduleItemsForAssignee(Schedule::class, 'schedule-active-window', [
        'status'   => 'scheduled',
        'start_at' => '2026-08-01 00:00:00',
        'end_at'   => '2026-08-31 23:59:59',
    ]);

    expect($schedules->pluck('uuid')->all())->toBe(['schedule-active-window'])
        ->and($schedules->first()->relationLoaded('items'))->toBeTrue()
        ->and($schedules->first()->relationLoaded('templates'))->toBeTrue()
        ->and($schedules->first()->relationLoaded('exceptions'))->toBeTrue()
        ->and($exceptions->pluck('uuid')->all())->toBe(['exception-match'])
        ->and($items->pluck('uuid')->all())->toBe(['item-match'])
        ->and($items->first()->relationLoaded('schedule'))->toBeTrue()
        ->and($items->first()->relationLoaded('template'))->toBeTrue();
});

it('updates materialization horizon even when a schedule has no applied recurring templates', function () {
    $capsule = schedule_service_database();
    Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));

    $capsule->getConnection()->table('schedules')->insert([
        'uuid'         => 'schedule-1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'name'         => 'Schedule without templates',
        'timezone'     => 'UTC',
        'status'       => 'active',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $schedule = Schedule::find('schedule-1');
    $created  = (new ScheduleService())->materializeSchedule($schedule, Carbon::parse('2026-10-01', 'UTC'));

    expect($created)->toBe(0)
        ->and($schedule->refresh()->last_materialized_at->toDateTimeString())->toBe('2026-09-01 08:00:00')
        ->and($schedule->materialization_horizon->toDateString())->toBe('2026-10-01');
});

it('skips template materialization when the template has no rrule', function () {
    schedule_service_database();

    $schedule = new Schedule();
    $schedule->setRawAttributes([
        'uuid'         => 'schedule-1',
        'company_uuid' => 'company-1',
        'timezone'     => 'UTC',
    ], true);

    $template = new ScheduleTemplate();
    $template->setRawAttributes([
        'uuid'  => 'template-1',
        'rrule' => null,
    ], true);

    expect((new ScheduleService())->materializeTemplate($template, $schedule, Carbon::parse('2026-10-01', 'UTC')))->toBe(0);
});

it('skips template materialization when recurrence produces no occurrences', function () {
    $capsule = schedule_service_database();
    Carbon::setTestNow(Carbon::parse('2026-07-01 00:00:00', 'UTC'));

    $capsule->getConnection()->table('schedules')->insert([
        'uuid'         => 'schedule-1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'name'         => 'Driver schedule',
        'timezone'     => 'UTC',
        'status'       => 'active',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $capsule->getConnection()->table('schedule_templates')->insert([
        'uuid'          => 'template-empty',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => 'schedule-1',
        'subject_type'  => 'driver',
        'subject_uuid'  => 'driver-1',
        'name'          => 'No occurrences',
        'start_time'    => '09:00',
        'duration'      => 480,
        'rrule'         => 'FREQ=DAILY;COUNT=1',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $schedule = Schedule::find('schedule-1');
    $template = ScheduleTemplate::find('template-empty');

    expect((new ScheduleService())->materializeTemplate($template, $schedule, Carbon::parse('2026-06-30', 'UTC')))->toBe(0)
        ->and(ScheduleItem::query()->count())->toBe(0);
});

it('applies a library template to a draft schedule and immediately materializes shifts', function () {
    $capsule  = schedule_service_database();
    $activity = schedule_service_bind_activity();
    Carbon::setTestNow(Carbon::parse('2026-07-01 08:00:00', 'UTC'));

    $capsule->getConnection()->table('schedules')->insert([
        'uuid'         => 'schedule-1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'name'         => 'Driver schedule',
        'timezone'     => 'UTC',
        'status'       => 'draft',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $capsule->getConnection()->table('schedule_templates')->insert([
        'uuid'           => 'template-library',
        'company_uuid'   => 'company-1',
        'schedule_uuid'  => null,
        'subject_type'   => null,
        'subject_uuid'   => null,
        'name'           => 'Weekday mornings',
        'description'    => 'Library copy',
        'start_time'     => '09:00',
        'end_time'       => '11:00',
        'duration'       => null,
        'break_duration' => null,
        'rrule'          => 'FREQ=DAILY;COUNT=2',
        'color'          => '#2563eb',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    $schedule = Schedule::find('schedule-1');
    $template = ScheduleTemplate::find('template-library');
    $result   = (new ScheduleService())->applyTemplateToSchedule($template, $schedule);

    $appliedTemplate = $result['template'];
    $items           = ScheduleItem::orderBy('start_at')->get();

    expect($result['items_created'])->toBe(2)
        ->and($appliedTemplate)->toBeInstanceOf(ScheduleTemplate::class)
        ->and($appliedTemplate->uuid)->not->toBe('template-library')
        ->and($appliedTemplate->schedule_uuid)->toBe('schedule-1')
        ->and($appliedTemplate->subject_type)->toBe('\Fleetbase\Models\Driver')
        ->and($appliedTemplate->subject_uuid)->toBe('driver-1')
        ->and(Schedule::find('schedule-1')->status)->toBe('active')
        ->and($items)->toHaveCount(2)
        ->and($items->pluck('schedule_uuid')->all())->toBe(['schedule-1', 'schedule-1'])
        ->and($items->pluck('template_uuid')->unique()->values()->all())->toBe([$appliedTemplate->uuid])
        ->and($items->pluck('start_at')->map->toDateTimeString()->all())->toBe([
            '2026-07-01 09:00:00',
            '2026-07-02 09:00:00',
        ])
        ->and($items->pluck('end_at')->map->toDateTimeString()->all())->toBe([
            '2026-07-01 11:00:00',
            '2026-07-02 11:00:00',
        ])
        ->and($activity->entries)->toHaveCount(1)
        ->and($activity->entries[0]['event'])->toBe('schedule_template.applied')
        ->and($activity->entries[0]['properties'])->toBe(['template_uuid' => 'template-library']);
});

it('materializes recurring templates idempotently around approved exceptions and break windows', function () {
    $capsule = schedule_service_database();
    Carbon::setTestNow(Carbon::parse('2026-07-01 00:00:00', 'UTC'));

    $capsule->getConnection()->table('schedules')->insert([
        'uuid'         => 'schedule-1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'name'         => 'Driver schedule',
        'timezone'     => 'UTC',
        'status'       => 'active',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $capsule->getConnection()->table('schedule_templates')->insert([
        'uuid'           => 'template-1',
        'company_uuid'   => 'company-1',
        'schedule_uuid'  => 'schedule-1',
        'subject_type'   => 'driver',
        'subject_uuid'   => 'driver-1',
        'name'           => 'Daily shift',
        'start_time'     => '09:00',
        'end_time'       => '17:00',
        'duration'       => null,
        'break_duration' => 60,
        'rrule'          => 'FREQ=DAILY;COUNT=5',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
    $capsule->getConnection()->table('schedule_items')->insert([
        'uuid'          => 'existing-july-2',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => 'schedule-1',
        'template_uuid' => 'template-1',
        'assignee_type' => 'driver',
        'assignee_uuid' => 'driver-1',
        'start_at'      => '2026-07-02 09:00:00',
        'end_at'        => '2026-07-02 17:00:00',
        'duration'      => 480,
        'status'        => 'scheduled',
        'is_exception'  => false,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    $capsule->getConnection()->table('schedule_exceptions')->insert([
        'uuid'          => 'exception-july-3',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => 'schedule-1',
        'subject_type'  => 'driver',
        'subject_uuid'  => 'driver-1',
        'start_at'      => '2026-07-03 00:00:00',
        'end_at'        => '2026-07-03 23:59:59',
        'type'          => 'time_off',
        'status'        => 'approved',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $schedule = Schedule::find('schedule-1');
    $template = ScheduleTemplate::find('template-1');
    $created  = (new ScheduleService())->materializeTemplate($template, $schedule, Carbon::parse('2026-07-05 23:59:59', 'UTC'));
    $items    = ScheduleItem::orderBy('start_at')->get();

    expect($created)->toBe(3)
        ->and($items->pluck('uuid')->contains('existing-july-2'))->toBeTrue()
        ->and($items->pluck('start_at')->map->toDateTimeString()->all())->toBe([
            '2026-07-01 09:00:00',
            '2026-07-02 09:00:00',
            '2026-07-04 09:00:00',
            '2026-07-05 09:00:00',
        ])
        ->and($items->firstWhere('start_at', Carbon::parse('2026-07-01 09:00:00', 'UTC'))->break_start_at->toDateTimeString())->toBe('2026-07-01 12:30:00')
        ->and($items->firstWhere('start_at', Carbon::parse('2026-07-01 09:00:00', 'UTC'))->break_end_at->toDateTimeString())->toBe('2026-07-01 13:30:00')
        ->and($items->where('start_at', Carbon::parse('2026-07-03 09:00:00', 'UTC'))->count())->toBe(0);
});

it('materializes all active schedules into materialized skipped and error buckets', function () {
    $capsule = schedule_service_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 00:00:00', 'UTC'));

    $capsule->getConnection()->table('schedules')->insert([
        [
            'uuid'                    => 'schedule-materialized',
            'company_uuid'            => 'company-1',
            'subject_type'            => 'driver',
            'subject_uuid'            => 'driver-1',
            'name'                    => 'Needs work',
            'timezone'                => 'UTC',
            'status'                  => 'active',
            'materialization_horizon' => null,
            'created_at'              => now(),
            'updated_at'              => now(),
        ],
        [
            'uuid'                    => 'schedule-skipped',
            'company_uuid'            => 'company-1',
            'subject_type'            => 'driver',
            'subject_uuid'            => 'driver-2',
            'name'                    => 'No templates',
            'timezone'                => 'UTC',
            'status'                  => 'active',
            'materialization_horizon' => '2026-07-01',
            'created_at'              => now(),
            'updated_at'              => now(),
        ],
        [
            'uuid'                    => 'schedule-error',
            'company_uuid'            => 'company-1',
            'subject_type'            => 'driver',
            'subject_uuid'            => 'driver-3',
            'name'                    => 'Runtime error',
            'timezone'                => 'UTC',
            'status'                  => 'active',
            'materialization_horizon' => null,
            'created_at'              => now(),
            'updated_at'              => now(),
        ],
        [
            'uuid'                    => 'schedule-current',
            'company_uuid'            => 'company-1',
            'subject_type'            => 'driver',
            'subject_uuid'            => 'driver-4',
            'name'                    => 'Already current',
            'timezone'                => 'UTC',
            'status'                  => 'active',
            'materialization_horizon' => '2026-10-01',
            'created_at'              => now(),
            'updated_at'              => now(),
        ],
    ]);
    $capsule->getConnection()->table('schedule_templates')->insert([
        [
            'uuid'           => 'template-materialized',
            'company_uuid'   => 'company-1',
            'schedule_uuid'  => 'schedule-materialized',
            'subject_type'   => 'driver',
            'subject_uuid'   => 'driver-1',
            'name'           => 'Daily shift',
            'start_time'     => '08:00',
            'end_time'       => null,
            'duration'       => 120,
            'break_duration' => null,
            'rrule'          => 'FREQ=DAILY;COUNT=1',
            'created_at'     => now(),
            'updated_at'     => now(),
        ],
        [
            'uuid'           => 'template-error',
            'company_uuid'   => 'company-1',
            'schedule_uuid'  => 'schedule-error',
            'subject_type'   => 'driver',
            'subject_uuid'   => 'driver-3',
            'name'           => 'Bad shift',
            'start_time'     => '08:00',
            'end_time'       => null,
            'duration'       => 120,
            'break_duration' => null,
            'rrule'          => 'FREQ=DAILY;COUNT=1',
            'created_at'     => now(),
            'updated_at'     => now(),
        ],
    ]);

    $stats = (new ScheduleServiceMaterializeAllFake())->materializeAll();

    expect($stats)->toBe(['materialized' => 1, 'skipped' => 1, 'errors' => 1])
        ->and(ScheduleItem::where('schedule_uuid', 'schedule-materialized')->count())->toBe(1)
        ->and(ScheduleItem::where('schedule_uuid', 'schedule-current')->count())->toBe(0)
        ->and(Schedule::find('schedule-materialized')->materialization_horizon->toDateString())->toBe('2026-09-16')
        ->and(Schedule::find('schedule-skipped')->materialization_horizon->toDateString())->toBe('2026-09-16')
        ->and(Schedule::find('schedule-error')->materialization_horizon)->toBeNull();
});
