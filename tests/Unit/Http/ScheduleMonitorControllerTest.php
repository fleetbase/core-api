<?php

use Fleetbase\Http\Controllers\Internal\v1\ScheduleMonitorController;
use Fleetbase\Http\Requests\AdminRequest;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function schedule_monitor_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
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
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('monitored_scheduled_tasks', function ($table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('type')->nullable();
        $table->string('cron_expression');
        $table->string('timezone')->nullable();
        $table->string('ping_url')->nullable();
        $table->dateTime('last_started_at')->nullable();
        $table->dateTime('last_finished_at')->nullable();
        $table->dateTime('last_failed_at')->nullable();
        $table->dateTime('last_skipped_at')->nullable();
        $table->dateTime('registered_on_oh_dear_at')->nullable();
        $table->dateTime('last_pinged_at')->nullable();
        $table->integer('grace_time_in_minutes');
        $table->timestamps();
    });
    $schema->create('monitored_scheduled_task_log_items', function ($table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('monitored_scheduled_task_id');
        $table->string('type');
        $table->json('meta')->nullable();
        $table->timestamps();
    });

    return $capsule;
}

function schedule_monitor_payload($response): array
{
    return json_decode($response->getContent(), true);
}

function schedule_monitor_admin_request(): AdminRequest
{
    return AdminRequest::create('/int/v1/schedule-monitor', 'GET');
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

it('lists monitored scheduled tasks with formatted lifecycle timestamps and placeholder fallbacks', function () {
    $capsule = schedule_monitor_controller_database();

    $capsule->getConnection()->table('monitored_scheduled_tasks')->insert([
        [
            'id'                    => 1,
            'name'                  => 'fleetbase:telemetry-ping',
            'type'                  => 'command',
            'cron_expression'       => '*/5 * * * *',
            'timezone'              => 'UTC',
            'last_started_at'       => '2026-11-02 09:15:00',
            'last_finished_at'      => '2026-11-02 09:16:00',
            'last_failed_at'        => '2026-11-01 08:00:00',
            'grace_time_in_minutes' => 5,
            'created_at'            => now(),
            'updated_at'            => now(),
        ],
        [
            'id'                    => 2,
            'name'                  => 'fleetbase:materialize-schedules',
            'type'                  => 'command',
            'cron_expression'       => '0 * * * *',
            'timezone'              => 'UTC',
            'last_started_at'       => null,
            'last_finished_at'      => null,
            'last_failed_at'        => null,
            'grace_time_in_minutes' => 10,
            'created_at'            => now(),
            'updated_at'            => now(),
        ],
    ]);

    $payload = schedule_monitor_payload((new ScheduleMonitorController())->tasks(schedule_monitor_admin_request()));

    expect($payload)->toHaveCount(2)
        ->and($payload[0]['name'])->toBe('fleetbase:telemetry-ping')
        ->and($payload[0]['last_started_at_fmt'])->toBe('09:15 2, Nov 2026')
        ->and($payload[0]['last_finished_at_fmt'])->toBe('09:16 2, Nov 2026')
        ->and($payload[0]['last_failed_at_fmt'])->toBe('08:00 1, Nov 2026')
        ->and($payload[1]['name'])->toBe('fleetbase:materialize-schedules')
        ->and($payload[1]['last_started_at_fmt'])->toBe('-')
        ->and($payload[1]['last_finished_at_fmt'])->toBe('-')
        ->and($payload[1]['last_failed_at_fmt'])->toBe('-');
});

it('lists only the latest finished log entries for a monitored scheduled task', function () {
    $capsule = schedule_monitor_controller_database();

    $capsule->getConnection()->table('monitored_scheduled_tasks')->insert([
        'id'                    => 1,
        'name'                  => 'fleetbase:telemetry-ping',
        'type'                  => 'command',
        'cron_expression'       => '*/5 * * * *',
        'timezone'              => 'UTC',
        'grace_time_in_minutes' => 5,
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);

    $rows = [];
    for ($i = 1; $i <= 22; $i++) {
        $rows[] = [
            'id'                          => $i,
            'monitored_scheduled_task_id' => 1,
            'type'                        => 'finished',
            'meta'                        => json_encode(['runtime' => $i]),
            'created_at'                  => Carbon::parse("2026-11-03 10:{$i}:00"),
            'updated_at'                  => Carbon::parse("2026-11-03 10:{$i}:00"),
        ];
    }
    $rows[] = [
        'id'                          => 23,
        'monitored_scheduled_task_id' => 1,
        'type'                        => 'failed',
        'meta'                        => json_encode(['failure_message' => 'Nope']),
        'created_at'                  => '2026-11-03 11:00:00',
        'updated_at'                  => '2026-11-03 11:00:00',
    ];
    $rows[] = [
        'id'                          => 24,
        'monitored_scheduled_task_id' => 2,
        'type'                        => 'finished',
        'meta'                        => json_encode(['runtime' => 999]),
        'created_at'                  => '2026-11-03 12:00:00',
        'updated_at'                  => '2026-11-03 12:00:00',
    ];
    $capsule->getConnection()->table('monitored_scheduled_task_log_items')->insert($rows);

    $payload = schedule_monitor_payload((new ScheduleMonitorController())->logs(1, schedule_monitor_admin_request()));

    expect($payload)->toHaveCount(20)
        ->and($payload[0]['id'])->toBe(22)
        ->and($payload[0]['created_at_fmt'])->toBe('10:22 2026-11-03')
        ->and($payload[19]['id'])->toBe(3)
        ->and(collect($payload)->pluck('type')->unique()->values()->all())->toBe(['finished'])
        ->and(collect($payload)->pluck('monitored_scheduled_task_id')->unique()->values()->all())->toBe([1]);
});

it('finds a monitored scheduled task by id and formats timestamps', function () {
    $capsule = schedule_monitor_controller_database();

    $capsule->getConnection()->table('monitored_scheduled_tasks')->insert([
        'id'                    => 7,
        'name'                  => 'fleetbase:queue-status',
        'type'                  => 'command',
        'cron_expression'       => '* * * * *',
        'timezone'              => 'UTC',
        'last_started_at'       => '2026-11-04 01:00:00',
        'last_finished_at'      => '2026-11-04 01:01:00',
        'last_failed_at'        => null,
        'grace_time_in_minutes' => 1,
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);

    $payload = schedule_monitor_payload((new ScheduleMonitorController())->findRecord(7, schedule_monitor_admin_request()));

    expect($payload['id'])->toBe(7)
        ->and($payload['name'])->toBe('fleetbase:queue-status')
        ->and($payload['last_started_at_fmt'])->toBe('01:00 4, Nov 2026')
        ->and($payload['last_finished_at_fmt'])->toBe('01:01 4, Nov 2026')
        ->and($payload['last_failed_at_fmt'])->toBe('-');
});

it('returns the stable not found error contract for missing monitored tasks', function () {
    schedule_monitor_controller_database();

    $response = (new ScheduleMonitorController())->findRecord(404, schedule_monitor_admin_request());

    expect($response->getStatusCode())->toBe(404)
        ->and(schedule_monitor_payload($response))->toBe([
            'errors' => ['No monitored task found.'],
        ]);
});
