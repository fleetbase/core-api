<?php

use Fleetbase\Listeners\LogFailedWebhook;
use Fleetbase\Listeners\LogFinalWebhookAttempt;
use Fleetbase\Listeners\LogSuccessfulWebhook;
use Fleetbase\Webhook\Events\FinalWebhookCallFailedEvent;
use Fleetbase\Webhook\Events\WebhookCallEvent;
use Fleetbase\Webhook\Events\WebhookCallFailedEvent;
use Fleetbase\Webhook\Events\WebhookCallSucceededEvent;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class WebhookLoggingCacheFake
{
    private array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

function webhook_logging_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'              => false,
        'database.default'               => 'mysql',
        'database.connections.mysql'     => $connection,
        'database.connections.sandbox'   => $connection,
        'fleetbase.connection.db'        => 'mysql',
    ]);
    $container->instance('cache', new WebhookLoggingCacheFake());
    $container->instance('responsecache', new class {
        public function clear(): bool
        {
            return true;
        }
    });
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->addConnection($connection, 'sandbox');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    foreach (['mysql', 'sandbox'] as $connectionName) {
        $schema = $capsule->getConnection($connectionName)->getSchemaBuilder();
        foreach (['webhook_request_logs', 'api_credentials', 'personal_access_tokens', 'api_events'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('webhook_request_logs', function ($table) {
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('_key')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('webhook_uuid')->nullable();
            $table->string('api_credential_uuid')->nullable();
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->string('api_event_uuid')->nullable();
            $table->string('method')->nullable();
            $table->integer('status_code')->nullable();
            $table->string('reason_phrase')->nullable();
            $table->float('duration')->nullable();
            $table->text('url')->nullable();
            $table->integer('attempt')->nullable();
            $table->text('response')->nullable();
            $table->string('status')->nullable();
            $table->text('headers')->nullable();
            $table->text('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('api_credentials', function ($table) {
            $table->string('uuid')->primary();
            $table->string('company_uuid')->nullable();
            $table->string('key')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('personal_access_tokens', function ($table) {
            $table->id();
            $table->string('tokenable_type')->nullable();
            $table->unsignedBigInteger('tokenable_id')->nullable();
            $table->string('name')->nullable();
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        $schema->create('api_events', function ($table) {
            $table->string('uuid')->primary();
            $table->string('event')->nullable();
            $table->timestamps();
        });

        $db = $capsule->getConnection($connectionName);
        $db->table('api_credentials')->insert([
            'uuid'         => '11111111-1111-4111-8111-111111111111',
            'company_uuid' => 'company-1',
            'key'          => 'live_key',
            'expires_at'   => null,
            'created_at'   => '2026-07-17 10:00:00',
            'updated_at'   => '2026-07-17 10:00:00',
        ]);
        $db->table('personal_access_tokens')->insert([
            'id'             => 44,
            'tokenable_type' => 'Fleetbase\\Models\\User',
            'tokenable_id'   => 1,
            'name'           => 'Console token',
            'token'          => str_repeat('b', 64),
            'abilities'      => json_encode(['*']),
            'created_at'     => '2026-07-17 10:00:00',
            'updated_at'     => '2026-07-17 10:00:00',
        ]);
    }

    return $capsule;
}

function webhook_logging_event(string $class, array $overrides = []): WebhookCallEvent
{
    $meta = array_merge([
        'api_key'             => 'live_key',
        'company_uuid'        => 'company-1',
        'webhook_uuid'        => 'webhook-1',
        'api_event_uuid'      => 'event-1',
        'api_credential_uuid' => '11111111-1111-4111-8111-111111111111',
        'access_token_id'     => null,
        'sent_at'             => '2026-07-17 10:45:00',
        'is_sandbox'          => false,
    ], $overrides['meta'] ?? []);

    $response = array_key_exists('response', $overrides)
        ? $overrides['response']
        : new PsrResponse(202, ['X-Hook' => 'accepted'], '{"ok":true}');
    $request = new PsrRequest($overrides['httpVerb'] ?? 'post', $overrides['webhookUrl'] ?? 'https://example.test/hooks/orders');
    $stats   = $overrides['transferStats'] ?? new TransferStats($request, $response, $overrides['transferTime'] ?? 0.345);

    return new $class(
        $overrides['httpVerb'] ?? 'post',
        $overrides['webhookUrl'] ?? 'https://example.test/hooks/orders',
        $overrides['payload'] ?? ['event' => 'order.created'],
        $overrides['headers'] ?? ['X-Fleetbase-Signature' => 'signature'],
        $meta,
        $overrides['tags'] ?? ['orders'],
        $overrides['attempt'] ?? 2,
        $response,
        $overrides['errorType'] ?? null,
        $overrides['errorMessage'] ?? null,
        $overrides['uuid'] ?? 'call-1',
        $stats,
    );
}

function webhook_log_row(Capsule $capsule, string $connection = 'mysql'): object
{
    return $capsule->getConnection($connection)->table('webhook_request_logs')->first();
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('logs successful webhook attempts with credential attribution on the live connection', function () {
    $capsule = webhook_logging_database();

    (new LogSuccessfulWebhook())->handle(webhook_logging_event(WebhookCallSucceededEvent::class, [
        'meta' => [
            'access_token_id' => '44',
        ],
    ]));

    $row = webhook_log_row($capsule);

    expect($capsule->getConnection('mysql')->table('webhook_request_logs')->count())->toBe(1)
        ->and($capsule->getConnection('sandbox')->table('webhook_request_logs')->count())->toBe(0)
        ->and($row->_key)->toBe('live_key')
        ->and($row->company_uuid)->toBe('company-1')
        ->and($row->webhook_uuid)->toBe('webhook-1')
        ->and($row->api_event_uuid)->toBe('event-1')
        ->and($row->api_credential_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($row->access_token_id)->toBe(44)
        ->and($row->method)->toBe('POST')
        ->and($row->status_code)->toBe(202)
        ->and($row->reason_phrase)->toBe('Accepted')
        ->and($row->duration)->toBe(0.345)
        ->and($row->url)->toBe('https://example.test/hooks/orders')
        ->and($row->attempt)->toBe(2)
        ->and($row->response)->toBe('{}')
        ->and($row->status)->toBe('successful')
        ->and(json_decode($row->headers, true))->toBe(['X-Fleetbase-Signature' => 'signature'])
        ->and(json_decode($row->meta, true))->toMatchArray([
            'api_key'             => 'live_key',
            'api_credential_uuid' => '11111111-1111-4111-8111-111111111111',
            'is_sandbox'          => false,
        ])
        ->and($row->sent_at)->toBe('2026-07-17 10:45:00');
});

it('logs failed webhook attempts to sandbox and attributes valid personal access tokens', function () {
    $capsule = webhook_logging_database();

    $event = webhook_logging_event(WebhookCallFailedEvent::class, [
        'response' => new PsrResponse(503, ['X-Hook' => 'failed'], '{"error":"down"}'),
        'meta'     => [
            'is_sandbox'          => true,
            'api_credential_uuid' => '11111111-1111-4111-8111-111111111111',
            'access_token_id'     => '44',
        ],
        'attempt'      => 5,
        'transferTime' => 1.25,
    ]);

    (new LogFailedWebhook())->handle($event);

    $row = webhook_log_row($capsule, 'sandbox');

    expect($capsule->getConnection('mysql')->table('webhook_request_logs')->count())->toBe(0)
        ->and($capsule->getConnection('sandbox')->table('webhook_request_logs')->count())->toBe(1)
        ->and($row->api_credential_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($row->access_token_id)->toBe(44)
        ->and($row->status_code)->toBe(503)
        ->and($row->reason_phrase)->toBe('Service Unavailable')
        ->and($row->duration)->toBe(1.25)
        ->and($row->attempt)->toBe(5)
        ->and($row->response)->toBe('{}')
        ->and($row->status)->toBe('failed')
        ->and(json_decode($row->meta, true))->toMatchArray([
            'is_sandbox'          => true,
            'api_credential_uuid' => '11111111-1111-4111-8111-111111111111',
            'access_token_id'     => '44',
        ]);
});

it('classifies final webhook attempts by response status and handles missing responses', function () {
    $capsule = webhook_logging_database();

    (new LogFinalWebhookAttempt())->handle(webhook_logging_event(FinalWebhookCallFailedEvent::class, [
        'response' => new PsrResponse(204, [], ''),
        'attempt'  => 3,
        'meta'     => [
            'access_token_id' => '44',
        ],
    ]));
    (new LogFinalWebhookAttempt())->handle(webhook_logging_event(FinalWebhookCallFailedEvent::class, [
        'response'      => null,
        'transferStats' => new TransferStats(new PsrRequest('delete', 'https://example.test/hooks/orders'), null, 0.75),
        'httpVerb'      => 'delete',
        'attempt'       => 6,
        'meta'          => [
            'api_credential_uuid' => 'missing-credential',
            'access_token_id'     => 'missing-token',
        ],
    ]));

    $rows = $capsule->getConnection('mysql')->table('webhook_request_logs')->orderBy('attempt')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status_code)->toBe(204)
        ->and($rows[0]->reason_phrase)->toBe('No Content')
        ->and($rows[0]->status)->toBe('successful')
        ->and($rows[0]->access_token_id)->toBe(44)
        ->and($rows[0]->response)->toBe('{}')
        ->and($rows[1]->method)->toBe('DELETE')
        ->and($rows[1]->status_code)->toBe(500)
        ->and($rows[1]->reason_phrase)->toBe('ERR')
        ->and($rows[1]->duration)->toBe(0.75)
        ->and($rows[1]->response)->toBe('null')
        ->and($rows[1]->status)->toBe('failed')
        ->and($rows[1]->api_credential_uuid)->toBeNull()
        ->and($rows[1]->access_token_id)->toBeNull();
});
