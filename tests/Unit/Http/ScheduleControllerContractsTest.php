<?php

use Fleetbase\Http\Controllers\Internal\v1\ScheduleExceptionController;
use Fleetbase\Http\Controllers\Internal\v1\ScheduleTemplateController;
use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleException;
use Fleetbase\Models\ScheduleTemplate;
use Fleetbase\Services\Scheduling\ScheduleService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon as TestClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class ScheduleControllerContractsTaggedCacheFake
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

class ScheduleControllerContractsResponseCacheFake
{
    public function clear(): void
    {
    }
}

class ScheduleControllerContractsApplyRequest extends Request
{
    public function validate(array $rules, ...$params): array
    {
        expect($rules)->toBe([
            'schedule_uuid' => 'required|string',
        ]);

        return $this->only(array_keys($rules));
    }
}

class ScheduleControllerContractsServiceFake extends ScheduleService
{
    public array $calls = [];

    public function __construct(private Collection $exceptions = new Collection())
    {
    }

    public function materializeTemplate(ScheduleTemplate $template, Schedule $schedule, ?Carbon\Carbon $horizon = null): int
    {
        $this->calls[] = ['materializeTemplate', $template->uuid, $schedule->uuid, $horizon?->toDateString()];

        return 3;
    }

    public function applyTemplateToSchedule(ScheduleTemplate $template, Schedule $schedule): array
    {
        $this->calls[] = ['applyTemplateToSchedule', $template->uuid, $schedule->uuid];

        $applied = new ScheduleTemplate();
        $applied->setRawAttributes([
            'uuid'          => 'applied-template-1',
            'public_id'     => 'applied_template_1',
            'company_uuid'  => $schedule->company_uuid,
            'schedule_uuid' => $schedule->uuid,
            'subject_type'  => $schedule->subject_type,
            'subject_uuid'  => $schedule->subject_uuid,
            'name'          => $template->name,
            'rrule'         => $template->rrule,
        ], true);
        $applied->exists = true;

        return ['template' => $applied, 'items_created' => 5];
    }

    public function approveException(ScheduleException $exception, ?string $reviewerUuid = null): ScheduleException
    {
        $this->calls[]               = ['approveException', $exception->uuid, $reviewerUuid];
        $exception->status           = 'approved';
        $exception->reviewed_by_uuid = $reviewerUuid;

        return $exception;
    }

    public function rejectException(ScheduleException $exception, ?string $reviewerUuid = null): ScheduleException
    {
        $this->calls[]               = ['rejectException', $exception->uuid, $reviewerUuid];
        $exception->status           = 'rejected';
        $exception->reviewed_by_uuid = $reviewerUuid;

        return $exception;
    }

    public function getExceptionsForSubject(string $subjectType, string $subjectUuid, array $filters = [])
    {
        $this->calls[] = ['getExceptionsForSubject', $subjectType, $subjectUuid, $filters];

        return $this->exceptions;
    }
}

function schedule_controller_contracts_database(): Capsule
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
    $container->instance('responsecache', new ScheduleControllerContractsResponseCacheFake());
    Cache::swap(new ScheduleControllerContractsTaggedCacheFake());
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('schedules', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->string('timezone')->nullable();
        $table->string('status')->nullable();
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

    return $capsule;
}

function schedule_controller_contracts_json($response): array
{
    return json_decode($response->getContent(), true);
}

function schedule_controller_contracts_controller(string $class, ScheduleService $service)
{
    $reflection = new ReflectionClass($class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $property   = $reflection->getProperty('scheduleService');
    $property->setAccessible(true);
    $property->setValue($controller, $service);

    return $controller;
}

// Carbon is aliased because the fake above type hints the relative name `Carbon\Carbon`.
beforeEach(function () {
    TestClock::setTestNow(TestClock::parse('2026-08-03 12:00:00', 'UTC'));
});

afterEach(function () {
    TestClock::setTestNow();
    Facade::clearResolvedInstances();
});

it('wires schedule controller services through constructors', function () {
    schedule_controller_contracts_database();
    $service = new ScheduleControllerContractsServiceFake();

    $exceptionController = new ScheduleExceptionController($service);
    $templateController  = new ScheduleTemplateController($service);

    $exceptionReflection = new ReflectionClass($exceptionController);
    $templateReflection  = new ReflectionClass($templateController);

    $exceptionProperty = $exceptionReflection->getProperty('scheduleService');
    $templateProperty  = $templateReflection->getProperty('scheduleService');
    $exceptionProperty->setAccessible(true);
    $templateProperty->setAccessible(true);

    expect($exceptionProperty->getValue($exceptionController))->toBe($service)
        ->and($templateProperty->getValue($templateController))->toBe($service);
});

it('materializes an applied schedule template scoped to the active company and returns item count', function () {
    $capsule = schedule_controller_contracts_database();
    session(['company' => 'company-1']);

    $capsule->getConnection()->table('schedules')->insert([
        'uuid'         => 'schedule-1',
        'public_id'    => 'schedule_public_1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'name'         => 'Driver schedule',
        'status'       => 'active',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $capsule->getConnection()->table('schedule_templates')->insert([
        [
            'uuid'          => 'template-1',
            'public_id'     => 'template_public_1',
            'company_uuid'  => 'company-1',
            'schedule_uuid' => 'schedule-1',
            'subject_type'  => 'driver',
            'subject_uuid'  => 'driver-1',
            'name'          => 'Morning route',
            'rrule'         => 'FREQ=WEEKLY;BYDAY=MO',
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
        [
            'uuid'          => 'template-other-company',
            'public_id'     => 'template_public_other',
            'company_uuid'  => 'company-2',
            'schedule_uuid' => 'schedule-1',
            'subject_type'  => 'driver',
            'subject_uuid'  => 'driver-1',
            'name'          => 'Wrong tenant',
            'rrule'         => 'FREQ=WEEKLY;BYDAY=MO',
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
    ]);

    $service  = new ScheduleControllerContractsServiceFake();
    $response = schedule_controller_contracts_controller(ScheduleTemplateController::class, $service)->materialize('template_public_1');
    $payload  = schedule_controller_contracts_json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload)->toBe([
            'status'        => 'ok',
            'items_created' => 3,
        ])
        ->and($service->calls)->toBe([
            ['materializeTemplate', 'template-1', 'schedule-1', null],
        ]);
});

it('applies a library schedule template to a tenant schedule and returns the applied resource', function () {
    $capsule = schedule_controller_contracts_database();
    session(['company' => 'company-1']);

    $capsule->getConnection()->table('schedules')->insert([
        'uuid'         => 'schedule-1',
        'public_id'    => 'schedule_public_1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'name'         => 'Driver schedule',
        'status'       => 'draft',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $capsule->getConnection()->table('schedule_templates')->insert([
        [
            'uuid'         => 'template-1',
            'public_id'    => 'template_public_1',
            'company_uuid' => 'company-1',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'name'         => 'Library route',
            'rrule'        => 'FREQ=WEEKLY;BYDAY=TU',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
        [
            'uuid'         => 'template-other-company',
            'public_id'    => 'template_public_other',
            'company_uuid' => 'company-2',
            'subject_type' => 'driver',
            'subject_uuid' => 'driver-1',
            'name'         => 'Wrong tenant',
            'rrule'        => 'FREQ=WEEKLY;BYDAY=TU',
            'created_at'   => now(),
            'updated_at'   => now(),
        ],
    ]);

    $service  = new ScheduleControllerContractsServiceFake();
    $request  = ScheduleControllerContractsApplyRequest::create('/int/v1/schedule-templates/template_public_1/apply', 'POST', ['schedule_uuid' => 'schedule_public_1']);
    $response = schedule_controller_contracts_controller(ScheduleTemplateController::class, $service)->apply($request, 'template_public_1');
    $payload  = schedule_controller_contracts_json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['items_created'])->toBe(5)
        ->and($payload['schedule_template']['uuid'])->toBe('applied-template-1')
        ->and($payload['schedule_template']['public_id'])->toBe('applied_template_1')
        ->and($payload['schedule_template']['schedule_uuid'])->toBe('schedule-1')
        ->and($service->calls)->toBe([
            ['applyTemplateToSchedule', 'template-1', 'schedule-1'],
        ]);
});

it('returns an unprocessable response when materializing a template without an applied schedule', function () {
    $capsule = schedule_controller_contracts_database();
    session(['company' => 'company-1']);

    $capsule->getConnection()->table('schedule_templates')->insert([
        'uuid'          => 'template-1',
        'public_id'     => 'template_public_1',
        'company_uuid'  => 'company-1',
        'schedule_uuid' => 'missing-schedule',
        'subject_type'  => 'driver',
        'subject_uuid'  => 'driver-1',
        'name'          => 'Orphan applied template',
        'rrule'         => 'FREQ=WEEKLY;BYDAY=MO',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $controller = schedule_controller_contracts_controller(ScheduleTemplateController::class, new ScheduleControllerContractsServiceFake());
    $response   = $controller->materialize('template-1');

    expect($response->getStatusCode())->toBe(422)
        ->and(schedule_controller_contracts_json($response))->toBe([
            'error' => 'Template is not applied to any schedule.',
        ]);
});

it('approves and rejects schedule exceptions with reviewer attribution from the auth user', function () {
    $capsule = schedule_controller_contracts_database();
    session([
        'company' => 'company-1',
        'user'    => (object) ['uuid' => 'reviewer-1'],
    ]);

    $capsule->getConnection()->table('schedule_exceptions')->insert([
        'uuid'         => 'exception-1',
        'public_id'    => 'exception_public_1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'start_at'     => '2026-11-01 00:00:00',
        'end_at'       => '2026-11-02 00:00:00',
        'type'         => 'time_off',
        'status'       => 'pending',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $service    = new ScheduleControllerContractsServiceFake();
    $controller = schedule_controller_contracts_controller(ScheduleExceptionController::class, $service);
    $approved   = schedule_controller_contracts_json($controller->approve('exception_public_1'));
    $rejected   = schedule_controller_contracts_json($controller->reject('exception-1'));

    expect($service->calls)->toBe([
        ['approveException', 'exception-1', 'reviewer-1'],
        ['rejectException', 'exception-1', 'reviewer-1'],
    ])
        ->and($approved['status'])->toBe('ok')
        ->and($approved['schedule_exception']['uuid'])->toBe('exception-1')
        ->and($approved['schedule_exception']['status'])->toBe('approved')
        ->and($approved['schedule_exception']['reviewed_by_uuid'])->toBe('reviewer-1')
        ->and($rejected['status'])->toBe('ok')
        ->and($rejected['schedule_exception']['uuid'])->toBe('exception-1')
        ->and($rejected['schedule_exception']['status'])->toBe('rejected')
        ->and($rejected['schedule_exception']['reviewed_by_uuid'])->toBe('reviewer-1');
});

it('requires subject filters before listing schedule exceptions for a subject', function () {
    schedule_controller_contracts_database();
    session(['company' => 'company-1']);

    $request    = Request::create('/int/v1/schedule-exceptions', 'GET', ['subject_type' => 'driver']);
    $controller = schedule_controller_contracts_controller(ScheduleExceptionController::class, new ScheduleControllerContractsServiceFake());
    $response   = $controller->forSubject($request);

    expect($response->getStatusCode())->toBe(422)
        ->and(schedule_controller_contracts_json($response))->toBe([
            'error' => 'subject_type and subject_uuid are required',
        ]);
});

it('lists subject exceptions after applying service filters and active-company scoping', function () {
    schedule_controller_contracts_database();
    session(['company' => 'company-1']);

    $matching = new ScheduleException();
    $matching->setRawAttributes([
        'uuid'         => 'exception-1',
        'company_uuid' => 'company-1',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'type'         => 'sick',
        'status'       => 'approved',
    ], true);
    $otherCompany = new ScheduleException();
    $otherCompany->setRawAttributes([
        'uuid'         => 'exception-2',
        'company_uuid' => 'company-2',
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'type'         => 'sick',
        'status'       => 'approved',
    ], true);

    $service = new ScheduleControllerContractsServiceFake(new Collection([$matching, $otherCompany]));
    $request = Request::create('/int/v1/schedule-exceptions', 'GET', [
        'subject_type' => 'driver',
        'subject_uuid' => 'driver-1',
        'status'       => 'approved',
        'type'         => 'sick',
        'start_at'     => '2026-11-01 00:00:00',
        'end_at'       => '2026-11-30 23:59:59',
    ]);

    $response = schedule_controller_contracts_controller(ScheduleExceptionController::class, $service)->forSubject($request);
    $payload  = schedule_controller_contracts_json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($service->calls)->toBe([
            ['getExceptionsForSubject', 'driver', 'driver-1', [
                'status'   => 'approved',
                'type'     => 'sick',
                'start_at' => '2026-11-01 00:00:00',
                'end_at'   => '2026-11-30 23:59:59',
            ]],
        ])
        ->and($payload['schedule_exceptions'])->toHaveCount(1)
        ->and($payload['schedule_exceptions'][0]['uuid'])->toBe('exception-1')
        ->and($payload['schedule_exceptions'][0]['company_uuid'])->toBe('company-1');
});
