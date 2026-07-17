<?php

use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleException;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\ScheduleTemplate;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class SchedulingModelsTaggedCacheFake
{
    public function tags(array $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }

    public function get(string $key): mixed
    {
        return null;
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

class SchedulingModelsResponseCacheFake
{
    public int $clears = 0;

    public function clear(): void
    {
        $this->clears++;
    }
}

function scheduling_models_database(): Capsule
{
    $connectionConfig = [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled' => false,
        'database.default' => 'testing',
        'database.connections.testing' => $connectionConfig,
        'fleetbase.connection.db' => 'testing',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    $container->instance('responsecache', new SchedulingModelsResponseCacheFake());
    Cache::swap(new SchedulingModelsTaggedCacheFake());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('schema');

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
        $table->dateTime('start_at')->nullable();
        $table->dateTime('end_at')->nullable();
        $table->integer('duration')->nullable();
        $table->string('status')->nullable();
        $table->boolean('is_exception')->default(false);
        $table->date('exception_for_date')->nullable();
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
        $table->string('reviewed_by_uuid')->nullable();
        $table->dateTime('reviewed_at')->nullable();
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

it('evaluates schedule horizon and timezone helper contracts', function () {
    scheduling_models_database();

    $schedule = new Schedule();
    $schedule->setRawAttributes([
        'timezone' => null,
        'materialization_horizon' => null,
    ], true);

    expect($schedule->getEffectiveTimezone())->toBe('UTC')
        ->and($schedule->needsMaterializationUpTo(Carbon::parse('2026-01-15')))->toBeTrue();

    $schedule->setAttribute('timezone', 'Asia/Ulaanbaatar');
    $schedule->setAttribute('materialization_horizon', '2026-01-31');

    expect($schedule->getEffectiveTimezone())->toBe('Asia/Ulaanbaatar')
        ->and($schedule->needsMaterializationUpTo(Carbon::parse('2026-01-15')))->toBeFalse()
        ->and($schedule->needsMaterializationUpTo(Carbon::parse('2026-02-01')))->toBeTrue();
});

it('calculates schedule item duration active state and exception flagging', function () {
    scheduling_models_database();
    Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'UTC'));

    $item = new ScheduleItem([
        'uuid' => 'item-1',
        'start_at' => '2026-03-10 09:00:00',
        'end_at' => '2026-03-10 17:30:00',
        'status' => 'scheduled',
        'is_exception' => false,
    ]);

    expect($item->calculateDuration())->toBe(510)
        ->and($item->isActive())->toBeTrue();

    $item->status = 'in_progress';
    $item->start_at = Carbon::parse('2026-03-11 09:00:00', 'UTC');
    $item->end_at = Carbon::parse('2026-03-11 17:00:00', 'UTC');

    expect($item->isActive())->toBeTrue();

    $saved = ScheduleItem::create([
        'uuid' => 'item-2',
        'start_at' => '2026-03-12 08:00:00',
        'end_at' => '2026-03-12 12:00:00',
        'status' => 'scheduled',
        'is_exception' => false,
    ]);

    $saved->markAsException();

    expect($saved->refresh()->is_exception)->toBeTrue()
        ->and($saved->exception_for_date)->toBe('2026-03-12')
        ->and($saved->duration)->toBe(240);

    Carbon::setTestNow();
});

it('approves rejects and evaluates schedule exceptions', function () {
    scheduling_models_database();
    Carbon::setTestNow(Carbon::parse('2026-04-10 10:00:00', 'UTC'));

    $exception = ScheduleException::create([
        'uuid' => 'exception-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'start_at' => '2026-04-10 00:00:00',
        'end_at' => '2026-04-11 00:00:00',
        'type' => 'time_off',
        'status' => 'pending',
    ]);

    expect($exception->type_label)->toBe('Time Off')
        ->and($exception->is_pending)->toBeTrue()
        ->and($exception->isActive())->toBeFalse();

    $exception->approve('reviewer-1');

    expect($exception->refresh()->status)->toBe('approved')
        ->and($exception->reviewed_by_uuid)->toBe('reviewer-1')
        ->and($exception->reviewed_at->toDateTimeString())->toBe('2026-04-10 10:00:00')
        ->and($exception->isActive())->toBeTrue();

    $exception->reject('reviewer-2');

    expect($exception->refresh()->status)->toBe('rejected')
        ->and($exception->reviewed_by_uuid)->toBe('reviewer-2')
        ->and($exception->isActive())->toBeFalse();

    $exception->type = 'custom_training';

    expect($exception->type_label)->toBe('Custom training');

    Carbon::setTestNow();
});

it('reports unavailable schedule template rrule support clearly in this package install', function () {
    scheduling_models_database();

    $template = new ScheduleTemplate();
    $template->setRawAttributes([
        'uuid' => 'template-1',
        'start_time' => '08:30',
        'rrule' => 'FREQ=WEEKLY;COUNT=3;BYDAY=MO,WE',
    ], true);

    $from = Carbon::parse('2026-05-04 00:00:00', 'UTC');
    $to = Carbon::parse('2026-05-12 23:59:59', 'UTC');

    expect($template->hasRrule())->toBeTrue()
        ->and(fn () => $template->getOccurrencesBetween($from, $to, 'UTC'))
        ->toThrow(RuntimeException::class, 'php-rrule is not installed.');

    $template->rrule = null;

    expect($template->hasRrule())->toBeFalse()
        ->and($template->getRruleInstance($from, 'UTC'))->toBeNull()
        ->and($template->getOccurrencesBetween($from, $to, 'UTC'))->toBe([]);
});
