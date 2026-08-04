<?php

use Fleetbase\Http\Controllers\Internal\v1\DeveloperMetricsController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function developer_metrics_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'             => false,
        'database.default'              => 'testing',
        'database.connections.testing'  => $connection,
        'fleetbase.connection.db'       => 'testing',
        'api.events'                    => ['order.created', 'order.updated'],
    ]);

    session()->flush();
    session(['company' => 'company-1']);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('api_request_logs', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('method')->nullable();
        $table->string('path')->nullable();
        $table->integer('status_code')->nullable();
        $table->decimal('duration', 10, 4)->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('webhook_request_logs', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('webhook_uuid')->nullable()->index();
        $table->string('url')->nullable();
        $table->integer('status_code')->nullable();
        $table->decimal('duration', 10, 4)->nullable();
        $table->integer('attempt')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('api_credentials', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->boolean('test_mode')->default(false);
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('webhook_endpoints', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('url')->nullable();
        $table->string('status')->nullable();
        $table->string('mode')->nullable();
        $table->text('events')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('api_events', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('event')->nullable();
        $table->string('source')->nullable();
        $table->string('description')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $capsule;
}

function developer_metrics_seed(Capsule $capsule): void
{
    $db = $capsule->getConnection('testing');

    $db->table('api_request_logs')->insert([
        ['uuid' => 'api-log-1', 'public_id' => 'req_1', 'company_uuid' => 'company-1', 'method' => 'GET', 'path' => '/v1/orders', 'status_code' => 200, 'duration' => 0.125, 'created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00', 'deleted_at' => null],
        ['uuid' => 'api-log-2', 'public_id' => 'req_2', 'company_uuid' => 'company-1', 'method' => 'POST', 'path' => '/v1/orders', 'status_code' => 500, 'duration' => 0.250, 'created_at' => '2026-07-18 11:00:00', 'updated_at' => '2026-07-18 11:00:00', 'deleted_at' => null],
        ['uuid' => 'api-log-3', 'public_id' => 'req_3', 'company_uuid' => 'company-1', 'method' => 'GET', 'path' => '/v1/customers', 'status_code' => 404, 'duration' => 0.050, 'created_at' => '2026-07-17 09:00:00', 'updated_at' => '2026-07-17 09:00:00', 'deleted_at' => null],
        ['uuid' => 'api-log-prev', 'public_id' => 'req_prev', 'company_uuid' => 'company-1', 'method' => 'GET', 'path' => '/v1/previous', 'status_code' => 200, 'duration' => 0.100, 'created_at' => '2026-07-08 09:00:00', 'updated_at' => '2026-07-08 09:00:00', 'deleted_at' => null],
        ['uuid' => 'api-log-other', 'public_id' => 'req_other', 'company_uuid' => 'company-2', 'method' => 'DELETE', 'path' => '/v1/orders/1', 'status_code' => 500, 'duration' => 1.000, 'created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00', 'deleted_at' => null],
    ]);

    $db->table('webhook_request_logs')->insert([
        ['uuid' => 'webhook-log-1', 'public_id' => 'webhook_req_1', 'company_uuid' => 'company-1', 'webhook_uuid' => 'webhook-1', 'url' => 'https://hooks.example.test/orders', 'status_code' => 200, 'duration' => 0.300, 'attempt' => 1, 'created_at' => '2026-07-18 10:30:00', 'updated_at' => '2026-07-18 10:30:00', 'deleted_at' => null],
        ['uuid' => 'webhook-log-2', 'public_id' => 'webhook_req_2', 'company_uuid' => 'company-1', 'webhook_uuid' => 'webhook-1', 'url' => 'https://hooks.example.test/orders', 'status_code' => 503, 'duration' => 0.600, 'attempt' => 3, 'created_at' => '2026-07-18 11:30:00', 'updated_at' => '2026-07-18 11:30:00', 'deleted_at' => null],
        ['uuid' => 'webhook-log-3', 'public_id' => 'webhook_req_3', 'company_uuid' => 'company-1', 'webhook_uuid' => 'webhook-2', 'url' => 'https://hooks.example.test/invoices', 'status_code' => 204, 'duration' => 0.150, 'attempt' => 1, 'created_at' => '2026-07-17 12:00:00', 'updated_at' => '2026-07-17 12:00:00', 'deleted_at' => null],
        ['uuid' => 'webhook-log-prev', 'public_id' => 'webhook_req_prev', 'company_uuid' => 'company-1', 'webhook_uuid' => 'webhook-1', 'url' => 'https://hooks.example.test/previous', 'status_code' => 500, 'duration' => 0.500, 'attempt' => 2, 'created_at' => '2026-07-08 12:00:00', 'updated_at' => '2026-07-08 12:00:00', 'deleted_at' => null],
    ]);

    $db->table('api_credentials')->insert([
        ['uuid' => 'credential-live', 'company_uuid' => 'company-1', 'name' => 'Live Orders', 'key' => 'flb_live_orders', 'test_mode' => 0, 'last_used_at' => '2026-07-18 08:00:00', 'expires_at' => '2026-08-01 00:00:00', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-18 08:00:00', 'deleted_at' => null],
        ['uuid' => 'credential-test', 'company_uuid' => 'company-1', 'name' => null, 'key' => 'flb_test_tools', 'test_mode' => 1, 'last_used_at' => '2026-06-01 08:00:00', 'expires_at' => null, 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 08:00:00', 'deleted_at' => null],
        ['uuid' => 'credential-deleted', 'company_uuid' => 'company-1', 'name' => 'Deleted', 'key' => 'flb_live_deleted', 'test_mode' => 0, 'last_used_at' => '2026-07-18 08:00:00', 'expires_at' => null, 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-18 08:00:00', 'deleted_at' => '2026-07-18 09:00:00'],
    ]);

    $db->table('webhook_endpoints')->insert([
        ['uuid' => 'webhook-1', 'company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/orders', 'status' => 'enabled', 'mode' => 'live', 'events' => json_encode(['order.created']), 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-18 09:00:00', 'deleted_at' => null],
        ['uuid' => 'webhook-2', 'company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/invoices', 'status' => 'disabled', 'mode' => 'test', 'events' => json_encode(['invoice.created']), 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-17 09:00:00', 'deleted_at' => null],
        ['uuid' => 'webhook-deleted', 'company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/deleted', 'status' => 'enabled', 'mode' => 'live', 'events' => json_encode([]), 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-18 09:00:00', 'deleted_at' => '2026-07-18 09:30:00'],
    ]);

    $db->table('api_events')->insert([
        ['uuid' => 'event-1', 'public_id' => 'event_1', 'company_uuid' => 'company-1', 'event' => 'order.created', 'source' => 'api', 'description' => 'Order created', 'created_at' => '2026-07-18 10:45:00', 'updated_at' => '2026-07-18 10:45:00', 'deleted_at' => null],
        ['uuid' => 'event-2', 'public_id' => 'event_2', 'company_uuid' => 'company-1', 'event' => 'order.created', 'source' => 'webhook', 'description' => null, 'created_at' => '2026-07-18 11:45:00', 'updated_at' => '2026-07-18 11:45:00', 'deleted_at' => null],
        ['uuid' => 'event-prev', 'public_id' => 'event_prev', 'company_uuid' => 'company-1', 'event' => 'order.cancelled', 'source' => 'api', 'description' => 'Previous event', 'created_at' => '2026-07-08 10:45:00', 'updated_at' => '2026-07-08 10:45:00', 'deleted_at' => null],
    ]);
}

function developer_metrics_request(array $query = []): Request
{
    return Request::create('/int/v1/metrics/dev', 'GET', $query);
}

function developer_metrics_controller(): DeveloperMetricsController
{
    $capsule = developer_metrics_database();
    developer_metrics_seed($capsule);

    return new DeveloperMetricsController();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    config([
        'activitylog.table_name' => 'activities',
        'api.events'             => [],
    ]);
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('developer metrics kpis compare current and previous periods with tenant scoped counts', function () {
    $payload = developer_metrics_controller()->kpis(developer_metrics_request(['period' => '7d']))->getData(true);

    expect($payload['period']['start'])->toBe('2026-07-12T00:00:00.000000Z')
        ->and($payload['period']['end'])->toBe('2026-07-18T23:59:59.999999Z')
        ->and($payload['metrics']['api_requests'])->toMatchArray(['label' => 'API Requests', 'value' => 3, 'format' => 'count', 'inverse' => false, 'delta_percent' => 200.0])
        ->and($payload['metrics']['api_error_rate'])->toMatchArray(['label' => 'API Error Rate', 'value' => 67, 'format' => 'percent', 'inverse' => true])
        ->and($payload['metrics']['avg_api_latency'])->toMatchArray(['label' => 'Avg API Latency', 'value' => 142, 'format' => 'duration', 'inverse' => true])
        ->and($payload['metrics']['webhook_success_rate'])->toMatchArray(['label' => 'Webhook Success Rate', 'value' => 67, 'format' => 'percent', 'inverse' => false])
        ->and($payload['metrics']['active_api_keys']['value'])->toBe(2)
        ->and($payload['metrics']['active_webhooks']['value'])->toBe(1)
        ->and($payload['metrics']['webhook_failures'])->toMatchArray(['value' => 1, 'inverse' => true, 'delta_percent' => 0.0])
        ->and($payload['metrics']['events_emitted'])->toMatchArray(['value' => 2, 'delta_percent' => 100.0]);
});

test('developer metrics period selector supports long range and default windows', function () {
    expect(developer_metrics_controller()->kpis(developer_metrics_request(['period' => '90d']))->getData(true)['period']['start'])->toBe('2026-04-20T00:00:00.000000Z')
        ->and(developer_metrics_controller()->kpis(developer_metrics_request(['period' => '180d']))->getData(true)['period']['start'])->toBe('2026-01-20T00:00:00.000000Z')
        ->and(developer_metrics_controller()->kpis(developer_metrics_request(['period' => '365d']))->getData(true)['period']['start'])->toBe('2025-07-19T00:00:00.000000Z')
        ->and(developer_metrics_controller()->kpis(developer_metrics_request(['period' => 'unexpected']))->getData(true)['period']['start'])->toBe('2026-06-19T00:00:00.000000Z');
});

test('developer metrics api traffic buckets requests errors methods and success counts by day', function () {
    $payload = developer_metrics_controller()->apiTraffic(developer_metrics_request(['period' => '7d']))->getData(true);

    expect($payload['labels'])->toBe(['Jul 12', 'Jul 13', 'Jul 14', 'Jul 15', 'Jul 16', 'Jul 17', 'Jul 18'])
        ->and($payload['datasets'])->toBe([
            ['label' => 'Requests', 'data' => [0, 0, 0, 0, 0, 1, 2]],
            ['label' => 'Success', 'data' => [0, 0, 0, 0, 0, 0, 1]],
            ['label' => 'Errors', 'data' => [0, 0, 0, 0, 0, 1, 1]],
        ])
        ->and($payload['methods'])->toContain(['label' => 'GET', 'value' => 2])
        ->and($payload['methods'])->toContain(['label' => 'POST', 'value' => 1]);
});

test('developer metrics webhook delivery summarizes success failures attempts and duration', function () {
    $payload = developer_metrics_controller()->webhookDelivery(developer_metrics_request(['period' => '7d']))->getData(true);

    expect($payload['summary'])->toBe([
        'sent'                => 3,
        'succeeded'           => 2,
        'failed'              => 1,
        'success_rate'        => 67,
        'average_attempts'    => 1.67,
        'average_duration_ms' => 350,
    ])
        ->and($payload['datasets'])->toBe([
            ['label' => 'Sent', 'data' => [0, 0, 0, 0, 0, 1, 2]],
            ['label' => 'Succeeded', 'data' => [0, 0, 0, 0, 0, 1, 1]],
            ['label' => 'Failed', 'data' => [0, 0, 0, 0, 0, 0, 1]],
        ]);
});

test('developer metrics credentials summarize active keys and expose stable item metadata', function () {
    $payload = developer_metrics_controller()->credentials()->getData(true);

    expect($payload['summary'])->toBe([
        'total'         => 2,
        'live'          => 1,
        'test'          => 1,
        'recently_used' => 1,
        'expiring_soon' => 1,
    ])
        ->and($payload['items'][0])->toMatchArray([
            'id'          => 'credential-live',
            'name'        => 'Live Orders',
            'environment' => 'Live',
            'expires_at'  => '2026-08-01T00:00:00.000000Z',
        ])
        ->and($payload['items'][1])->toMatchArray([
            'id'          => 'credential-test',
            'name'        => 'flb_test_tools',
            'environment' => 'Test',
        ]);
});

test('developer metrics endpoint health merges endpoint inventory with delivery stats', function () {
    $payload = developer_metrics_controller()->endpointHealth(developer_metrics_request(['period' => '7d']))->getData(true);

    expect($payload['items'])->toHaveCount(2)
        ->and($payload['items'][0])->toMatchArray([
            'id'                  => 'webhook-1',
            'url'                 => 'https://hooks.example.test/orders',
            'status'              => 'enabled',
            'mode'                => 'live',
            'success_rate'        => 50,
            'deliveries'          => 2,
            'failures'            => 1,
            'average_duration_ms' => 450,
            'last_delivery_at'    => '2026-07-18T11:30:00.000000Z',
        ])
        ->and($payload['items'][1])->toMatchArray([
            'id'                  => 'webhook-2',
            'success_rate'        => 100,
            'deliveries'          => 1,
            'failures'            => 0,
            'average_duration_ms' => 150,
        ]);
});

test('developer metrics events and activity keep tenant scoped aggregate response contracts', function () {
    $controller = developer_metrics_controller();

    $events   = $controller->events(developer_metrics_request(['period' => '7d']))->getData(true);
    $activity = $controller->activity(developer_metrics_request(['limit' => 2]))->getData(true);

    expect($events['total'])->toBe(2)
        ->and($events['types'])->toBe([
            ['label' => 'order.created', 'value' => 2],
        ])
        ->and($events['sources'])->toContain(['label' => 'api', 'value' => 1])
        ->and($events['sources'])->toContain(['label' => 'webhook', 'value' => 1])
        ->and($activity['items'])->toHaveCount(2)
        ->and($activity['items'][0])->toMatchArray([
            'id'     => 'event_2',
            'type'   => 'event',
            'label'  => 'order.created',
            'status' => 'order.created',
        ])
        ->and($activity['items'][1])->toMatchArray([
            'id'          => 'webhook_req_2',
            'type'        => 'webhook',
            'label'       => 'https://hooks.example.test/orders',
            'status'      => 503,
            'duration_ms' => 600,
        ]);
});
