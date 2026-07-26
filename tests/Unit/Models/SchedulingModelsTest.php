<?php

use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleConstraint;
use Fleetbase\Models\ScheduleException;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\ScheduleTemplate;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

if (!class_exists('Log')) {
    class_alias(Illuminate\Support\Facades\Log::class, 'Log');
}

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

class SchedulingModelsLogFake
{
    public array $entries = [];

    public function warning(string $message, array $context = []): void
    {
        $this->entries[] = ['warning', $message, $context];
    }
}

function scheduling_models_database(): Capsule
{
    Illuminate\Database\Eloquent\Model::clearBootedModels();

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'            => false,
        'database.default'             => 'testing',
        'database.connections.mysql'   => $connectionConfig,
        'database.connections.testing' => $connectionConfig,
        'fleetbase.connection.db'      => 'testing',
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
        $table->text('description')->nullable();
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
    $schema->create('schedule_constraints', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('type')->nullable();
        $table->string('category')->nullable();
        $table->string('constraint_key')->nullable();
        $table->string('constraint_value')->nullable();
        $table->string('jurisdiction')->nullable();
        $table->integer('priority')->nullable();
        $table->boolean('is_active')->default(true);
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

it('evaluates schedule horizon and timezone helper contracts', function () {
    scheduling_models_database();

    $schedule = new Schedule();
    $schedule->setRawAttributes([
        'timezone'                => null,
        'materialization_horizon' => null,
        'company_uuid'            => 'company-1',
        'subject_type'            => User::class,
        'subject_uuid'            => 'driver-1',
    ], true);

    expect($schedule->getEffectiveTimezone())->toBe('UTC')
        ->and($schedule->needsMaterializationUpTo(Carbon::parse('2026-01-15')))->toBeTrue()
        ->and($schedule->subject()->getMorphType())->toBe('subject_type')
        ->and($schedule->subject()->getForeignKeyName())->toBe('subject_uuid')
        ->and($schedule->company()->getRelated())->toBeInstanceOf(Fleetbase\Models\Company::class)
        ->and($schedule->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($schedule->items()->getRelated())->toBeInstanceOf(ScheduleItem::class)
        ->and($schedule->items()->getForeignKeyName())->toBe('schedule_uuid');

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
        'uuid'         => 'item-1',
        'start_at'     => '2026-03-10 09:00:00',
        'end_at'       => '2026-03-10 17:30:00',
        'status'       => 'scheduled',
        'is_exception' => false,
    ]);

    expect($item->calculateDuration())->toBe(510)
        ->and($item->isActive())->toBeTrue()
        ->and((new ScheduleItem())->calculateDuration())->toBe(0)
        ->and($item->schedule()->getRelated())->toBeInstanceOf(Schedule::class)
        ->and($item->schedule()->getForeignKeyName())->toBe('schedule_uuid')
        ->and($item->template()->getRelated())->toBeInstanceOf(ScheduleTemplate::class)
        ->and($item->template()->getForeignKeyName())->toBe('template_uuid')
        ->and($item->assignee())->toBeInstanceOf(MorphTo::class)
        ->and($item->assignee()->getMorphType())->toBe('assignee_type')
        ->and($item->assignee()->getForeignKeyName())->toBe('assignee_uuid')
        ->and($item->resource())->toBeInstanceOf(MorphTo::class)
        ->and($item->resource()->getMorphType())->toBe('resource_type')
        ->and($item->resource()->getForeignKeyName())->toBe('resource_uuid');

    $item->status   = 'in_progress';
    $item->start_at = Carbon::parse('2026-03-11 09:00:00', 'UTC');
    $item->end_at   = Carbon::parse('2026-03-11 17:00:00', 'UTC');

    expect($item->isActive())->toBeTrue();

    $saved = ScheduleItem::create([
        'uuid'         => 'item-2',
        'start_at'     => '2026-03-12 08:00:00',
        'end_at'       => '2026-03-12 12:00:00',
        'status'       => 'scheduled',
        'is_exception' => false,
    ]);

    $saved->markAsException();

    expect($saved->refresh()->is_exception)->toBeTrue()
        ->and($saved->exception_for_date)->toBe('2026-03-12')
        ->and($saved->duration)->toBe(240);

    $saved->markAsException();

    expect($saved->refresh()->exception_for_date)->toBe('2026-03-12');

    Carbon::setTestNow();
});

it('approves rejects and evaluates schedule exceptions', function () {
    scheduling_models_database();
    Carbon::setTestNow(Carbon::parse('2026-04-10 10:00:00', 'UTC'));

    $exception = ScheduleException::create([
        'uuid'         => 'exception-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'start_at'     => '2026-04-10 00:00:00',
        'end_at'       => '2026-04-11 00:00:00',
        'type'         => 'time_off',
        'status'       => 'pending',
    ]);

    expect($exception->type_label)->toBe('Time Off')
        ->and($exception->is_pending)->toBeTrue()
        ->and($exception->isActive())->toBeFalse()
        ->and(ScheduleException::pending()->pluck('uuid')->all())->toBe([$exception->uuid]);

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

it('defines schedule exception relationship contracts', function () {
    scheduling_models_database();

    $exception  = new ScheduleException();
    $subject    = $exception->subject();
    $schedule   = $exception->schedule();
    $reviewedBy = $exception->reviewedBy();

    expect($subject)->toBeInstanceOf(MorphTo::class)
        ->and($subject->getMorphType())->toBe('subject_type')
        ->and($subject->getForeignKeyName())->toBe('subject_uuid')
        ->and($schedule)->toBeInstanceOf(BelongsTo::class)
        ->and($schedule->getRelated())->toBeInstanceOf(Schedule::class)
        ->and($schedule->getForeignKeyName())->toBe('schedule_uuid')
        ->and($schedule->getOwnerKeyName())->toBe('uuid')
        ->and($reviewedBy)->toBeInstanceOf(BelongsTo::class)
        ->and($reviewedBy->getRelated())->toBeInstanceOf(User::class)
        ->and($reviewedBy->getForeignKeyName())->toBe('reviewed_by_uuid')
        ->and($reviewedBy->getOwnerKeyName())->toBe('uuid');
});

it('reports unavailable schedule template rrule support clearly in this package install', function () {
    scheduling_models_database();

    $template = new ScheduleTemplate();
    $template->setRawAttributes([
        'uuid'       => 'template-1',
        'start_time' => '08:30',
        'rrule'      => 'FREQ=WEEKLY;COUNT=3;BYDAY=MO,WE',
    ], true);

    $from = Carbon::parse('2026-05-04 00:00:00', 'UTC');
    $to   = Carbon::parse('2026-05-12 23:59:59', 'UTC');

    expect($template->hasRrule())->toBeTrue();

    if (class_exists('RRule\\RRule')) {
        expect($template->getOccurrencesBetween($from, $to, 'UTC'))
            ->sequence(
                fn ($occurrence) => $occurrence->toDateTimeString()->toBe('2026-05-04 08:30:00'),
                fn ($occurrence) => $occurrence->toDateTimeString()->toBe('2026-05-06 08:30:00'),
                fn ($occurrence) => $occurrence->toDateTimeString()->toBe('2026-05-11 08:30:00'),
            );
    } else {
        expect(fn () => $template->getOccurrencesBetween($from, $to, 'UTC'))
            ->toThrow(RuntimeException::class, 'php-rrule is not installed.');
    }

    $template->rrule = null;

    expect($template->hasRrule())->toBeFalse()
        ->and($template->getRruleInstance($from, 'UTC'))->toBeNull()
        ->and($template->getOccurrencesBetween($from, $to, 'UTC'))->toBe([]);
});

it('handles schedule template rrule parsing timezones horizons and invalid rules', function () {
    scheduling_models_database();

    $logger = new SchedulingModelsLogFake();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');

    $template = new ScheduleTemplate();
    $template->setRawAttributes([
        'uuid'       => 'template-rrule-branches',
        'start_time' => '08:30',
        'rrule'      => 'RRULE:FREQ=WEEKLY;COUNT=4;BYDAY=MO,WE',
    ], true);

    $from = Carbon::parse('2026-05-04 00:00:00', 'Asia/Ulaanbaatar');
    $to   = Carbon::parse('2026-05-06 23:59:59', 'Asia/Ulaanbaatar');

    expect($template->getRruleInstance($from, 'Asia/Ulaanbaatar'))->not->toBeNull()
        ->and($template->getOccurrencesBetween($from, $to, 'Asia/Ulaanbaatar'))
        ->sequence(
            fn ($occurrence) => $occurrence->toDateTimeString()->toBe('2026-05-04 08:30:00'),
            fn ($occurrence) => $occurrence->toDateTimeString()->toBe('2026-05-06 08:30:00'),
        );

    $template->rrule = 'FREQ=THROW_INVALID_ARGUMENT';

    expect($template->getRruleInstance($from, 'UTC'))->toBeNull()
        ->and($template->getOccurrencesBetween($from, $to, 'UTC'))->toBe([])
        ->and($logger->entries[count($logger->entries) - 1][0])->toBe('warning')
        ->and($logger->entries[count($logger->entries) - 1][1])->toBe('ScheduleTemplate: invalid RRULE string (RFC parse error)')
        ->and($logger->entries[count($logger->entries) - 1][2]['template_uuid'])->toBe('template-rrule-branches')
        ->and($logger->entries[count($logger->entries) - 1][2]['rrule_raw'])->toBe('FREQ=THROW_INVALID_ARGUMENT')
        ->and($logger->entries[count($logger->entries) - 1][2]['rrule_built'])->toContain('RRULE:FREQ=THROW_INVALID_ARGUMENT')
        ->and($logger->entries[count($logger->entries) - 1][2]['error'])->toBeString()->not->toBe('');

    $template->rrule = 'FREQ=THROW_RRULE_EXCEPTION';

    expect($template->getRruleInstance($from, 'UTC'))->toBeNull()
        ->and($logger->entries[count($logger->entries) - 1][0])->toBe('warning')
        ->and($logger->entries[count($logger->entries) - 1][1])->toStartWith('ScheduleTemplate: invalid RRULE string')
        ->and($logger->entries[count($logger->entries) - 1][2]['template_uuid'])->toBe('template-rrule-branches')
        ->and($logger->entries[count($logger->entries) - 1][2]['rrule_raw'])->toBe('FREQ=THROW_RRULE_EXCEPTION')
        ->and($logger->entries[count($logger->entries) - 1][2]['rrule_built'])->toContain('RRULE:FREQ=THROW_RRULE_EXCEPTION')
        ->and($logger->entries[count($logger->entries) - 1][2]['error'])->toBeString()->not->toBe('');
});

it('exposes schedule template relationship keys and rrule dependency failures', function () {
    scheduling_models_database();

    $template = new ScheduleTemplate();
    $template->setRawAttributes([
        'uuid'          => 'template-relations',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => 'schedule-1',
        'subject_type'  => User::class,
        'subject_uuid'  => 'driver-1',
        'start_time'    => '09:15',
        'rrule'         => 'FREQ=DAILY;COUNT=1',
    ], true);

    expect($template->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($template->company()->getOwnerKeyName())->toBe('uuid')
        ->and($template->company()->getRelated())->toBeInstanceOf(Fleetbase\Models\Company::class)
        ->and($template->schedule()->getForeignKeyName())->toBe('schedule_uuid')
        ->and($template->schedule()->getOwnerKeyName())->toBe('uuid')
        ->and($template->subject()->getMorphType())->toBe('subject_type')
        ->and($template->subject()->getForeignKeyName())->toBe('subject_uuid')
        ->and($template->items()->getForeignKeyName())->toBe('template_uuid')
        ->and($template->items()->getQualifiedForeignKeyName())->toBe('schedule_items.template_uuid');

    if (!class_exists('RRule\\RRule')) {
        expect(fn () => $template->getRruleInstance(Carbon::parse('2026-05-04', 'UTC'), 'Asia/Ulaanbaatar'))
            ->toThrow(RuntimeException::class, 'php-rrule is not installed.');
    }
});

it('scopes schedule templates and applies library copies to schedules', function () {
    scheduling_models_database();

    $schedule = Schedule::create([
        'uuid'         => 'schedule-driver-1',
        'company_uuid' => 'company-1',
        'subject_type' => User::class,
        'subject_uuid' => 'driver-1',
        'name'         => 'Driver Schedule',
        'start_date'   => '2026-03-01',
        'timezone'     => 'Asia/Ulaanbaatar',
        'status'       => 'active',
    ]);

    $library = ScheduleTemplate::create([
        'uuid'           => 'template-library',
        'company_uuid'   => 'company-1',
        'name'           => 'Morning Shift',
        'description'    => 'Weekday morning shift',
        'start_time'     => '08:00',
        'end_time'       => '16:00',
        'duration'       => 480,
        'break_duration' => 30,
        'rrule'          => 'RRULE:FREQ=WEEKLY;COUNT=4;BYDAY=MO,WE',
        'color'          => '#2563eb',
        'meta'           => ['source' => 'library'],
    ]);

    $applied = ScheduleTemplate::create([
        'uuid'          => 'template-applied',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => $schedule->uuid,
        'subject_type'  => User::class,
        'subject_uuid'  => 'driver-1',
        'name'          => 'Applied Shift',
        'start_time'    => '10:00',
        'end_time'      => '14:00',
        'duration'      => 240,
        'rrule'         => 'FREQ=DAILY;COUNT=1',
    ]);

    ScheduleItem::create([
        'uuid'          => 'item-template-applied',
        'schedule_uuid' => $schedule->uuid,
        'template_uuid' => $applied->uuid,
        'start_at'      => '2026-03-02 10:00:00',
        'end_at'        => '2026-03-02 14:00:00',
        'status'        => 'scheduled',
    ]);

    expect(ScheduleTemplate::library()->pluck('uuid')->all())->toBe([$library->uuid])
        ->and(ScheduleTemplate::applied()->pluck('uuid')->all())->toBe([$applied->uuid])
        ->and(ScheduleTemplate::forCompany('company-1')->count())->toBe(2)
        ->and(ScheduleTemplate::forSubject(User::class, 'driver-1')->pluck('uuid')->all())->toBe([$applied->uuid])
        ->and($applied->schedule()->first()->uuid)->toBe($schedule->uuid)
        ->and($applied->items()->count())->toBe(1);

    $copy = $library->applyToSchedule($schedule);

    expect($copy->schedule_uuid)->toBe($schedule->uuid)
        ->and($copy->subject_type)->toBe(User::class)
        ->and($copy->subject_uuid)->toBe('driver-1')
        ->and($copy->description)->toBe('Weekday morning shift')
        ->and($copy->meta)->toBe(['source' => 'library']);

    $override = $library->applyToSchedule($schedule, Fleetbase\Models\Company::class, 'vehicle-1');

    expect($override->subject_type)->toBe(Fleetbase\Models\Company::class)
        ->and($override->subject_uuid)->toBe('vehicle-1');
});

it('filters schedule items by assignment recurrence status and time windows', function () {
    scheduling_models_database();
    session()->flush();
    session(['company' => 'session-company']);
    Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'UTC'));

    $schedule = Schedule::create([
        'uuid'         => 'schedule-1',
        'company_uuid' => 'schedule-company',
        'subject_type' => User::class,
        'subject_uuid' => 'driver-1',
        'name'         => 'Driver Schedule',
        'status'       => 'active',
    ]);

    $fromSchedule = ScheduleItem::create([
        'uuid'          => 'item-from-schedule-company',
        'schedule_uuid' => $schedule->uuid,
        'template_uuid' => 'template-1',
        'assignee_type' => User::class,
        'assignee_uuid' => 'driver-1',
        'resource_type' => Fleetbase\Models\Company::class,
        'resource_uuid' => 'vehicle-1',
        'start_at'      => '2026-03-12 08:00:00',
        'end_at'        => '2026-03-12 12:00:00',
        'status'        => 'scheduled',
        'is_exception'  => false,
    ]);

    $fromSession = ScheduleItem::create([
        'uuid'          => 'item-from-session-company',
        'assignee_type' => User::class,
        'assignee_uuid' => 'driver-2',
        'start_at'      => '2026-03-09 08:00:00',
        'end_at'        => '2026-03-09 12:00:00',
        'status'        => 'completed',
        'is_exception'  => true,
    ]);

    $containingWindow = ScheduleItem::create([
        'uuid'          => 'item-window-containing',
        'company_uuid'  => 'company-explicit',
        'template_uuid' => 'template-1',
        'assignee_type' => User::class,
        'assignee_uuid' => 'driver-1',
        'start_at'      => '2026-03-11 00:00:00',
        'end_at'        => '2026-03-15 00:00:00',
        'status'        => 'in_progress',
        'is_exception'  => false,
    ]);

    $sortedUuids = function ($query): array {
        $uuids = $query->pluck('uuid')->all();
        sort($uuids);

        return $uuids;
    };

    $driverOneUuids = [$fromSchedule->uuid, $containingWindow->uuid];
    sort($driverOneUuids);

    expect($fromSchedule->company_uuid)->toBe('schedule-company')
        ->and($fromSchedule->duration)->toBe(240)
        ->and($fromSession->company_uuid)->toBe('session-company')
        ->and($sortedUuids(ScheduleItem::forAssignee(User::class, 'driver-1')))->toBe($driverOneUuids)
        ->and($sortedUuids(ScheduleItem::fromTemplate('template-1')))->toBe($driverOneUuids)
        ->and(ScheduleItem::exceptions()->pluck('uuid')->all())->toBe([$fromSession->uuid])
        ->and($sortedUuids(ScheduleItem::generated()))->toBe($driverOneUuids)
        ->and($sortedUuids(ScheduleItem::withinTimeRange('2026-03-12 09:00:00', '2026-03-12 10:00:00')))->toBe($driverOneUuids)
        ->and(ScheduleItem::onDate('2026-03-12')->pluck('uuid')->all())->toBe([$fromSchedule->uuid])
        ->and(ScheduleItem::upcoming()->pluck('uuid')->all())->toBe([
            $containingWindow->uuid,
            $fromSchedule->uuid,
        ])
        ->and(ScheduleItem::byStatus('completed')->pluck('uuid')->all())->toBe([$fromSession->uuid])
        ->and($sortedUuids(ScheduleItem::byStatus(['scheduled', 'in_progress'])))->toBe($driverOneUuids);

    Carbon::setTestNow();
});

it('marks generated schedule items as exceptions when schedule fields change', function () {
    scheduling_models_database();

    $generated = ScheduleItem::create([
        'uuid'          => 'generated-item',
        'company_uuid'  => 'company-1',
        'template_uuid' => 'template-1',
        'start_at'      => '2026-03-16 08:00:00',
        'end_at'        => '2026-03-16 12:00:00',
        'status'        => 'scheduled',
        'is_exception'  => false,
        'meta'          => ['source' => 'materializer'],
    ]);

    $generated->update(['meta' => ['source' => 'dispatcher-note']]);

    expect($generated->refresh()->is_exception)->toBeFalse()
        ->and($generated->exception_for_date)->toBeNull();

    $generated->update(['break_start_at' => '2026-03-16 10:00:00']);

    expect($generated->refresh()->is_exception)->toBeTrue()
        ->and($generated->exception_for_date)->toBe('2026-03-16');

    $generated->markAsException();

    expect($generated->refresh()->exception_for_date)->toBe('2026-03-16');

    $withoutOriginalStart = ScheduleItem::create([
        'uuid'          => 'generated-item-without-original-start',
        'company_uuid'  => 'company-1',
        'template_uuid' => 'template-1',
        'status'        => 'scheduled',
        'is_exception'  => false,
    ]);

    $withoutOriginalStart->update(['status' => 'completed']);

    expect($withoutOriginalStart->refresh()->is_exception)->toBeTrue()
        ->and($withoutOriginalStart->exception_for_date)->toBeNull();
});

it('scopes schedule constraints by active state type category subject and priority', function () {
    scheduling_models_database();

    DB::table('schedule_constraints')->insert([
        'uuid'             => 'constraint-low',
        'company_uuid'     => 'company-1',
        'subject_type'     => 'driver',
        'subject_uuid'     => 'driver-1',
        'name'             => 'Daily Hours',
        'description'      => 'Maximum duty window',
        'type'             => 'availability',
        'category'         => 'hours',
        'constraint_key'   => 'max_daily_hours',
        'constraint_value' => '8',
        'jurisdiction'     => 'US',
        'priority'         => '5',
        'is_active'        => true,
        'meta'             => '{"source":"policy"}',
    ]);
    DB::table('schedule_constraints')->insert([
        'uuid'           => 'constraint-high',
        'company_uuid'   => 'company-1',
        'subject_type'   => 'driver',
        'subject_uuid'   => 'driver-1',
        'name'           => 'Fatigue Buffer',
        'type'           => 'availability',
        'category'       => 'hours',
        'constraint_key' => 'rest_buffer',
        'jurisdiction'   => 'US',
        'priority'       => 50,
        'is_active'      => true,
    ]);
    DB::table('schedule_constraints')->insert([
        'uuid'           => 'constraint-inactive',
        'company_uuid'   => 'company-1',
        'subject_type'   => 'vehicle',
        'subject_uuid'   => 'vehicle-1',
        'name'           => 'Inactive',
        'type'           => 'maintenance',
        'category'       => 'asset',
        'constraint_key' => 'inspection_window',
        'priority'       => 100,
        'is_active'      => false,
    ]);

    $constraint         = ScheduleConstraint::where('uuid', 'constraint-low')->first();
    $relationConstraint = new ScheduleConstraint();
    $relationConstraint->setRawAttributes([
        'subject_type' => Schedule::class,
        'subject_uuid' => 'schedule-1',
    ], true);

    expect($constraint->priority)->toBe(5)
        ->and($constraint->is_active)->toBeTrue()
        ->and($constraint->meta)->toBe(['source' => 'policy'])
        ->and($constraint->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($constraint->company()->getOwnerKeyName())->toBe('uuid')
        ->and($relationConstraint->subject()->getMorphType())->toBe('subject_type')
        ->and($relationConstraint->subject()->getForeignKeyName())->toBe('subject_uuid')
        ->and(ScheduleConstraint::active()->orderBy('uuid')->pluck('uuid')->all())->toBe(['constraint-high', 'constraint-low'])
        ->and(ScheduleConstraint::byType('availability')->orderBy('uuid')->pluck('uuid')->all())->toBe(['constraint-high', 'constraint-low'])
        ->and(ScheduleConstraint::byCategory('asset')->pluck('uuid')->all())->toBe(['constraint-inactive'])
        ->and(ScheduleConstraint::forSubject('driver', 'driver-1')->orderByPriority()->pluck('uuid')->all())->toBe(['constraint-high', 'constraint-low']);
});
