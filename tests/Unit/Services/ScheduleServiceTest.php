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
use Illuminate\Support\Facades\Facade;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\PendingActivityLog;

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

    expect($schedules->pluck('uuid')->all())->toBe(['schedule-active-window'])
        ->and($schedules->first()->relationLoaded('items'))->toBeTrue()
        ->and($schedules->first()->relationLoaded('templates'))->toBeTrue()
        ->and($schedules->first()->relationLoaded('exceptions'))->toBeTrue()
        ->and($exceptions->pluck('uuid')->all())->toBe(['exception-match']);
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
