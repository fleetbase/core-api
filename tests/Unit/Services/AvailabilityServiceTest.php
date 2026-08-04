<?php

use Fleetbase\Models\ScheduleAvailability;
use Fleetbase\Services\Scheduling\AvailabilityService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\PendingActivityLog;

class AvailabilityServiceTaggedCacheFake
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

class AvailabilityServiceResponseCacheFake
{
    public function clear(): void
    {
    }
}

class AvailabilityServiceActivityFake
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

class AvailabilityServiceActivityLoggerFake extends ActivityLogger
{
    public function __construct(private AvailabilityServiceActivityFake $activityFake)
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

class AvailabilityServicePendingActivityLogFake extends PendingActivityLog
{
    public function __construct(private AvailabilityServiceActivityLoggerFake $activityLogger)
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

function availability_service_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'            => false,
        'database.default'             => 'testing',
        'database.connections.testing' => $connectionConfig,
        'database.connections.mysql'   => $connectionConfig,
        'fleetbase.connection.db'      => 'testing',
        'activitylog.default_log_name' => 'default',
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
    $container->instance('responsecache', new AvailabilityServiceResponseCacheFake());
    Cache::swap(new AvailabilityServiceTaggedCacheFake());
    Facade::clearResolvedInstances();

    $capsule->getConnection('testing')->getSchemaBuilder()->create('schedule_availability', function ($table) {
        $table->string('uuid')->primary();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->dateTime('start_at')->nullable();
        $table->dateTime('end_at')->nullable();
        $table->boolean('is_available')->default(true);
        $table->integer('preference_level')->nullable();
        $table->text('rrule')->nullable();
        $table->string('reason')->nullable();
        $table->string('notes')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

function availability_service_bind_activity(): AvailabilityServiceActivityFake
{
    $activity = new AvailabilityServiceActivityFake();
    app()->instance(PendingActivityLog::class, new AvailabilityServicePendingActivityLogFake(new AvailabilityServiceActivityLoggerFake($activity)));

    return $activity;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

it('sets availability inside a transaction and records the activity contract', function () {
    availability_service_database();
    $activity = availability_service_bind_activity();

    $data = [
        'uuid'             => 'availability-1',
        'subject_type'     => 'driver',
        'subject_uuid'     => 'driver-1',
        'start_at'         => '2026-10-01 09:00:00',
        'end_at'           => '2026-10-01 17:00:00',
        'is_available'     => true,
        'preference_level' => 5,
        'reason'           => 'Preferred daytime route',
        'notes'            => 'Only local routes',
    ];

    $availability = (new AvailabilityService())->setAvailability($data);

    expect($availability)->toBeInstanceOf(ScheduleAvailability::class)
        ->and($availability->uuid)->toBeString()
        ->and($availability->uuid)->not->toBe('')
        ->and($availability->is_available)->toBeTrue()
        ->and($availability->preference_level)->toBe(5)
        ->and(ScheduleAvailability::query()->count())->toBe(1)
        ->and($activity->entries)->toHaveCount(1)
        ->and($activity->entries[0]['subject'])->toBe($availability)
        ->and($activity->entries[0]['event'])->toBe('availability.set')
        ->and($activity->entries[0]['properties'])->toBe($data)
        ->and($activity->entries[0]['message'])->toBe('Availability set');
});

it('treats overlapping unavailable periods as unavailable while ignoring available and unrelated records', function () {
    $capsule = availability_service_database();
    $service = new AvailabilityService();

    $capsule->getConnection()->table('schedule_availability')->insert([
        [
            'uuid'         => 'availability-overlap',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-02 08:00:00',
            'end_at'       => '2026-10-02 12:00:00',
            'is_available' => false,
            'reason'       => 'Doctor appointment',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-positive',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-02 13:00:00',
            'end_at'       => '2026-10-02 17:00:00',
            'is_available' => true,
            'reason'       => 'Available later',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-other-subject',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-2',
            'start_at'     => '2026-10-02 09:00:00',
            'end_at'       => '2026-10-02 10:00:00',
            'is_available' => false,
            'reason'       => 'Other driver unavailable',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);

    expect($service->checkAvailability('driver', 'driver-1', '2026-10-02 09:30:00', '2026-10-02 10:30:00'))->toBeFalse()
        ->and($service->checkAvailability('driver', 'driver-1', '2026-10-02 13:30:00', '2026-10-02 14:30:00'))->toBeTrue()
        ->and($service->checkAvailability('driver', 'driver-2', '2026-10-02 09:30:00', '2026-10-02 10:30:00'))->toBeFalse()
        ->and($service->checkAvailability('vehicle', 'driver-1', '2026-10-02 09:30:00', '2026-10-02 10:30:00'))->toBeTrue();
});

it('returns overlapping availability records in chronological order for a subject', function () {
    $capsule = availability_service_database();
    $service = new AvailabilityService();

    $capsule->getConnection()->table('schedule_availability')->insert([
        [
            'uuid'         => 'availability-later',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-03 15:00:00',
            'end_at'       => '2026-10-03 16:00:00',
            'is_available' => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-earlier',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-03 09:00:00',
            'end_at'       => '2026-10-03 10:00:00',
            'is_available' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-outside-window',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-04 09:00:00',
            'end_at'       => '2026-10-04 10:00:00',
            'is_available' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-other-subject',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-2',
            'start_at'     => '2026-10-03 08:00:00',
            'end_at'       => '2026-10-03 09:00:00',
            'is_available' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);

    $availability = $service->getAvailability('driver', 'driver-1', '2026-10-03 00:00:00', '2026-10-03 23:59:59');

    expect($availability->pluck('uuid')->all())->toBe(['availability-earlier', 'availability-later'])
        ->and($availability->first()->is_available)->toBeFalse()
        ->and($availability->last()->is_available)->toBeTrue();
});

it('filters schedule availability through direct model scopes and relationship keys', function () {
    $capsule = availability_service_database();

    $capsule->getConnection()->table('schedule_availability')->insert([
        [
            'uuid'         => 'availability-inside-window',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-04 09:00:00',
            'end_at'       => '2026-10-04 11:00:00',
            'is_available' => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-encloses-window',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-04 07:00:00',
            'end_at'       => '2026-10-04 18:00:00',
            'is_available' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-other-subject',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-2',
            'start_at'     => '2026-10-04 09:00:00',
            'end_at'       => '2026-10-04 11:00:00',
            'is_available' => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);

    $relation = (new ScheduleAvailability())->subject();

    expect(ScheduleAvailability::forSubject('driver', 'driver-1')->available()->pluck('uuid')->all())->toBe(['availability-inside-window'])
        ->and(ScheduleAvailability::forSubject('driver', 'driver-1')->unavailable()->pluck('uuid')->all())->toBe(['availability-encloses-window'])
        ->and(ScheduleAvailability::forSubject('driver', 'driver-1')->withinTimeRange('2026-10-04 08:00:00', '2026-10-04 12:00:00')->pluck('uuid')->all())->toBe([
            'availability-inside-window',
            'availability-encloses-window',
        ])
        ->and($relation->getMorphType())->toBe('subject_type')
        ->and($relation->getForeignKeyName())->toBe('subject_uuid');
});

it('reports unique unavailable resource ids for a subject type within a time range', function () {
    $capsule = availability_service_database();
    $service = new AvailabilityService();

    $capsule->getConnection()->table('schedule_availability')->insert([
        [
            'uuid'         => 'availability-driver-1-a',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-05 08:00:00',
            'end_at'       => '2026-10-05 12:00:00',
            'is_available' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-driver-1-b',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'start_at'     => '2026-10-05 13:00:00',
            'end_at'       => '2026-10-05 17:00:00',
            'is_available' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-driver-2',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-2',
            'start_at'     => '2026-10-05 09:00:00',
            'end_at'       => '2026-10-05 10:00:00',
            'is_available' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'availability-vehicle-1',
            'subject_type' => 'vehicle',
            'subject_uuid' => 'vehicle-1',
            'start_at'     => '2026-10-05 09:00:00',
            'end_at'       => '2026-10-05 10:00:00',
            'is_available' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);

    expect($service->getAvailableResources('driver', '2026-10-05 09:30:00', '2026-10-05 11:00:00'))->toBe([
        'unavailable_subjects' => ['driver-1', 'driver-2'],
    ]);
});

it('deletes availability inside a transaction and records the activity contract', function () {
    availability_service_database();
    $activity = availability_service_bind_activity();

    $availability = ScheduleAvailability::create([
        'uuid'         => 'availability-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'start_at'     => '2026-10-06 09:00:00',
        'end_at'       => '2026-10-06 17:00:00',
        'is_available' => false,
        'reason'       => 'Personal day',
    ]);

    $availabilityUuid = $availability->uuid;

    expect((new AvailabilityService())->deleteAvailability($availability))->toBeTrue()
        ->and(ScheduleAvailability::withTrashed()->where('uuid', $availabilityUuid)->first()->trashed())->toBeTrue()
        ->and($activity->entries)->toHaveCount(1)
        ->and($activity->entries[0]['subject']->uuid)->toBe($availabilityUuid)
        ->and($activity->entries[0]['event'])->toBe('availability.deleted')
        ->and($activity->entries[0]['message'])->toBe('Availability deleted');
});
