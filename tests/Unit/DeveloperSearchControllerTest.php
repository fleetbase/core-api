<?php

use Fleetbase\Http\Controllers\Internal\v1\DeveloperSearchController;
use Fleetbase\Models\User as FleetbaseUser;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class DeveloperSearchAdminUser extends FleetbaseUser
{
    public function isAdmin(): bool
    {
        return true;
    }
}

function developer_search_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'            => false,
        'database.default'             => 'testing',
        'database.connections.testing' => $connection,
        'fleetbase.connection.db'      => 'testing',
    ]);

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => new DeveloperSearchAdminUser(),
    ]);

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

    $schema->create('api_credentials', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->string('_key')->nullable();
        $table->boolean('test_mode')->default(false);
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('webhook_endpoints', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('url')->nullable();
        $table->string('description')->nullable();
        $table->string('status')->nullable();
        $table->string('mode')->nullable();
        $table->string('version')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('api_request_logs', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('api_credential_uuid')->nullable()->index();
        $table->string('method')->nullable();
        $table->string('path')->nullable();
        $table->string('full_url')->nullable();
        $table->integer('status_code')->nullable();
        $table->string('reason_phrase')->nullable();
        $table->string('ip_address')->nullable();
        $table->string('version')->nullable();
        $table->string('source')->nullable();
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
        $table->string('method')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $capsule;
}

function developer_search_seed(Capsule $capsule): void
{
    $db = $capsule->getConnection('testing');

    $db->table('api_credentials')->insert([
        ['uuid' => 'credential-live', 'company_uuid' => 'company-1', 'name' => 'Live Orders', 'key' => 'flb_live_orders', '_key' => 'live_orders_hash', 'test_mode' => 0, 'expires_at' => null],
        ['uuid' => 'credential-test', 'company_uuid' => 'company-1', 'name' => null, 'key' => 'flb_test_orders', '_key' => 'test_orders_hash', 'test_mode' => 1, 'expires_at' => null],
        ['uuid' => 'credential-other', 'company_uuid' => 'company-2', 'name' => 'Other Orders', 'key' => 'flb_live_other', '_key' => 'other_orders_hash', 'test_mode' => 0, 'expires_at' => null],
    ]);

    $db->table('webhook_endpoints')->insert([
        ['uuid' => 'webhook-live', 'company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/orders', 'description' => 'Order hooks', 'status' => 'enabled', 'mode' => 'live', 'version' => '2026-01'],
        ['uuid' => 'webhook-test', 'company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/fallback', 'description' => null, 'status' => 'disabled', 'mode' => 'test', 'version' => '2026-02'],
        ['uuid' => 'webhook-other', 'company_uuid' => 'company-2', 'url' => 'https://hooks.example.test/other-orders', 'description' => 'Other tenant', 'status' => 'enabled', 'mode' => 'live', 'version' => '2026-01'],
    ]);

    $db->table('api_request_logs')->insert([
        ['uuid' => 'log-1', 'public_id' => 'req_orders_1', 'company_uuid' => 'company-1', 'api_credential_uuid' => 'credential-live', 'method' => 'GET', 'path' => 'v1/orders', 'full_url' => 'https://api.example.test/v1/orders', 'status_code' => 200, 'reason_phrase' => 'OK', 'ip_address' => '198.51.100.10', 'version' => 'v1', 'source' => 'api'],
        ['uuid' => 'log-2', 'public_id' => null, 'company_uuid' => 'company-1', 'api_credential_uuid' => 'credential-test', 'method' => 'POST', 'path' => 'v1/order%_special', 'full_url' => 'https://api.example.test/v1/order%25_special', 'status_code' => 500, 'reason_phrase' => 'Server Error', 'ip_address' => '198.51.100.11', 'version' => 'v1', 'source' => 'api'],
        ['uuid' => 'log-other', 'public_id' => 'req_other', 'company_uuid' => 'company-2', 'api_credential_uuid' => 'credential-other', 'method' => 'DELETE', 'path' => 'v1/orders/other', 'full_url' => 'https://api.example.test/v1/orders/other', 'status_code' => 404, 'reason_phrase' => 'Not Found', 'ip_address' => '198.51.100.12', 'version' => 'v1', 'source' => 'api'],
    ]);

    $db->table('api_events')->insert([
        ['uuid' => 'event-1', 'public_id' => 'event_orders_1', 'company_uuid' => 'company-1', 'event' => 'order.created', 'source' => 'api', 'description' => 'Order created', 'method' => 'POST'],
        ['uuid' => 'event-2', 'public_id' => 'event_invoice_1', 'company_uuid' => 'company-1', 'event' => null, 'source' => 'webhook', 'description' => null, 'method' => 'GET'],
        ['uuid' => 'event-other', 'public_id' => 'event_other', 'company_uuid' => 'company-2', 'event' => 'order.deleted', 'source' => 'api', 'description' => 'Other tenant', 'method' => 'DELETE'],
    ]);
}

function developer_search_controller(): DeveloperSearchController
{
    $capsule = developer_search_database();
    developer_search_seed($capsule);

    return new DeveloperSearchController();
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('developer search returns empty results for blank query', function () {
    $controller = new DeveloperSearchController();
    $response   = $controller->search(Request::create('/developers/search', 'GET', ['query' => '   ']));
    $payload    = json_decode($response->getContent(), true);

    expect($payload)->toBe(['results' => []]);
});

test('developer search returns tenant scoped results across all searchable developer resources', function () {
    $response = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'query' => 'orders',
        'limit' => 8,
    ]));

    $payload = $response->getData(true);
    $types   = array_column($payload['results'], 'type');

    expect($response->getStatusCode())->toBe(200)
        ->and($types)->toContain('API Key', 'Webhook', 'Request Log', 'Event')
        ->and($payload['results'])->toContain([
            'label'       => 'Live Orders',
            'description' => 'Live API key - flb_live_orders',
            'icon'        => 'key',
            'type'        => 'API Key',
            'route'       => 'console.developers.api-keys.index',
            'breadcrumb'  => 'Developers > API Keys',
            'queryParams' => [
                'query'        => 'orders',
                'view_api_key' => 'credential-live',
            ],
        ])
        ->and($payload['results'])->toContain([
            'label'       => 'https://hooks.example.test/orders',
            'description' => 'Order hooks',
            'icon'        => 'globe-asia',
            'type'        => 'Webhook',
            'route'       => 'console.developers.webhooks.view',
            'models'      => ['webhook-live'],
            'breadcrumb'  => 'Developers > Webhooks',
        ])
        ->and($payload['results'])->toContain([
            'label'       => 'req_orders_1',
            'description' => 'GET /v1/orders 200 OK',
            'icon'        => 'file-lines',
            'type'        => 'Request Log',
            'route'       => 'console.developers.logs.view',
            'models'      => ['req_orders_1'],
            'breadcrumb'  => 'Developers > Logs',
        ])
        ->and($payload['results'])->toContain([
            'label'       => 'order.created',
            'description' => 'Order created',
            'icon'        => 'calendar-day',
            'type'        => 'Event',
            'route'       => 'console.developers.events.view',
            'models'      => ['event_orders_1'],
            'breadcrumb'  => 'Developers > Events',
        ])
        ->and(collect($payload['results'])->pluck('label')->implode('|'))->not->toContain('Other Orders')
        ->and(collect($payload['results'])->pluck('models')->flatten()->implode('|'))->not->toContain('event_other');
});

test('developer search honors requested types fallback labels q aliases and hard limits', function () {
    $response = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'q'     => 'orders',
        'types' => 'logs,events,not-real',
        'limit' => 1,
    ]));

    $payload = $response->getData(true);

    expect($payload['results'])->toHaveCount(1)
        ->and($payload['results'][0])->toMatchArray([
            'label'       => 'req_orders_1',
            'description' => 'GET /v1/orders 200 OK',
            'type'        => 'Request Log',
            'models'      => ['req_orders_1'],
        ]);

    $webhookResponse = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'query' => 'disabled',
        'types' => ['webhooks'],
        'limit' => 24,
    ]));

    expect($webhookResponse->getData(true)['results'])->toBe([
        [
            'label'       => 'https://hooks.example.test/fallback',
            'description' => 'test disabled 2026-02',
            'icon'        => 'globe-asia',
            'type'        => 'Webhook',
            'route'       => 'console.developers.webhooks.view',
            'models'      => ['webhook-test'],
            'breadcrumb'  => 'Developers > Webhooks',
        ],
    ]);

    $eventResponse = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'query' => 'webhook',
        'types' => ['events'],
    ]));

    expect($eventResponse->getData(true)['results'])->toBe([
        [
            'label'       => 'event_invoice_1',
            'description' => 'webhook GET',
            'icon'        => 'calendar-day',
            'type'        => 'Event',
            'route'       => 'console.developers.events.view',
            'models'      => ['event_invoice_1'],
            'breadcrumb'  => 'Developers > Events',
        ],
    ]);
});
