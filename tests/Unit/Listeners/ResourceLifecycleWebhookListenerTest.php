<?php

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(Dispatchable::class)) {
        trait Dispatchable
        {
        }
    }

    if (!class_exists(PendingDispatch::class)) {
        class PendingDispatch
        {
            public function __construct(public mixed $job)
            {
            }
        }
    }
}

namespace Fleetbase\Listeners {
    if (!function_exists('Fleetbase\\Listeners\\logger')) {
        function logger(): mixed
        {
            return app('log');
        }
    }
}

namespace Fleetbase\Webhook {
    if (!function_exists('Fleetbase\\Webhook\\dispatch')) {
        function dispatch(mixed $job): \Illuminate\Foundation\Bus\PendingDispatch
        {
            app('bus')->dispatch($job);

            return new \Illuminate\Foundation\Bus\PendingDispatch($job);
        }
    }
}

namespace {
    use Fleetbase\Events\ResourceLifecycleEvent;
    use Fleetbase\Listeners\SendResourceLifecycleWebhook;
    use Fleetbase\Models\ApiEvent;
    use Fleetbase\Models\Model as FleetbaseModel;
    use Fleetbase\Webhook\BackoffStrategy\ExponentialBackoffStrategy;
    use Fleetbase\Webhook\CallWebhookJob;
    use Fleetbase\Webhook\Signer\DefaultSigner;
    use Fleetbase\Webhook\Signer\Signer;
    use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Eloquent\Model as EloquentModel;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Http\Resources\Json\JsonResource;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Facade;

    class ResourceLifecycleWebhookListenerJob extends CallWebhookJob
    {
    }

    class ResourceLifecycleWebhookListenerFailingSigner implements Signer
    {
        public function signatureHeaderName(): string
        {
            return 'X-Fleetbase-Signature';
        }

        public function calculateSignature(string $webhookUrl, array $payload, string $secret): string
        {
            throw new ResourceLifecycleWebhookListenerRequestException();
        }
    }

    class ResourceLifecycleWebhookListenerRequestException extends Exception
    {
        public function __construct()
        {
            parent::__construct('Webhook dispatch failed');
        }

        public function getStatusCode(): int
        {
            return 502;
        }

        public function getResponse(): object
        {
            return new class {
                public function getReasonPhrase(): string
                {
                    return 'Bad Gateway';
                }

                public function getBody(): string
                {
                    return 'upstream unavailable';
                }
            };
        }

        public function getRequest(): object
        {
            return new class {
                public function getMethod(): string
                {
                    return 'POST';
                }

                public function getUri(): string
                {
                    return 'https://hooks.example.test/events';
                }

                public function getHeaders(): array
                {
                    return ['Content-Type' => ['application/json']];
                }
            };
        }
    }

    class ResourceLifecycleWebhookListenerBusFake
    {
        public array $jobs = [];

        public function dispatch(mixed $job): mixed
        {
            $this->jobs[] = $job;

            return $job;
        }
    }

    class ResourceLifecycleWebhookListenerCacheFake
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

        public function rememberForever(string $key, Closure $callback): mixed
        {
            return $this->values[$key] ??= $callback();
        }

        public function forget(string $key): bool
        {
            unset($this->values[$key]);

            return true;
        }

        public function increment(string $key, int $value = 1): int
        {
            $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

            return $this->values[$key];
        }

        public function flush(): bool
        {
            $this->values = [];

            return true;
        }
    }

    class ResourceLifecycleWebhookListenerResponseCacheFake
    {
        public function clear(array $tags = []): bool
        {
            return true;
        }
    }

    class ResourceLifecycleWebhookListenerResource extends JsonResource
    {
        public function toWebhookPayload(): array
        {
            return [
                'id'         => $this->resource->public_id,
                'uuid'       => $this->resource->uuid,
                'status'     => $this->resource->status,
                'company_id' => $this->resource->company_uuid,
                'related'    => $this->resource->relatedResource,
                'seen_at'    => Carbon::parse('2026-07-18 15:10:00', 'UTC'),
            ];
        }
    }

    class ResourceLifecycleWebhookListenerEvent extends ResourceLifecycleEvent
    {
        public ?EloquentModel $record  = null;
        public ?JsonResource $resource = null;

        public static function fake(array $properties, ?EloquentModel $record = null, ?JsonResource $resource = null): self
        {
            $reflection = new ReflectionClass(self::class);
            /** @var self $event */
            $event = $reflection->newInstanceWithoutConstructor();

            foreach ($properties as $property => $value) {
                $event->{$property} = $value;
            }

            $event->record   = $record;
            $event->resource = $resource;

            return $event;
        }

        public function getModelRecord(): ?EloquentModel
        {
            return $this->record;
        }

        public function getModelResource($model, ?string $namespace = null, ?int $version = null): JsonResource
        {
            return $this->resource ?? new JsonResource($model);
        }
    }

    function resource_lifecycle_webhook_listener_database(): array
    {
        EloquentModel::clearBootedModels();

        $connection = [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ];

        $container = bind_test_container([
            'database.default'                          => 'mysql',
            'database.connections.mysql'                => $connection,
            'fleetbase.connection.db'                   => 'mysql',
            'api.cache.enabled'                         => false,
            'webhook-server.webhook_job'                => ResourceLifecycleWebhookListenerJob::class,
            'webhook-server.queue'                      => 'webhooks',
            'webhook-server.connection'                 => 'sync',
            'webhook-server.http_verb'                  => 'post',
            'webhook-server.tries'                      => 3,
            'webhook-server.backoff_strategy'           => ExponentialBackoffStrategy::class,
            'webhook-server.timeout_in_seconds'         => 10,
            'webhook-server.signer'                     => DefaultSigner::class,
            'webhook-server.headers'                    => ['Content-Type' => 'application/json'],
            'webhook-server.tags'                       => ['lifecycle'],
            'webhook-server.verify_ssl'                 => false,
            'webhook-server.throw_exception_on_failure' => false,
            'webhook-server.proxy'                      => null,
            'webhook-server.signature_header_name'      => 'X-Fleetbase-Signature',
        ]);

        $bus = new ResourceLifecycleWebhookListenerBusFake();
        $container->instance('bus', $bus);
        $container->instance(BusDispatcher::class, $bus);
        $container->instance('cache', new ResourceLifecycleWebhookListenerCacheFake());
        $container->instance('responsecache', new ResourceLifecycleWebhookListenerResponseCacheFake());
        Facade::clearResolvedInstance('bus');
        Facade::clearResolvedInstance(BusDispatcher::class);
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

        foreach (['mysql', 'sandbox'] as $connectionName) {
            $schema = $capsule->getConnection($connectionName)->getSchemaBuilder();
            $schema->create('api_events', function ($table) {
                $table->string('uuid')->primary();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('api_credential_uuid')->nullable();
                $table->unsignedBigInteger('access_token_id')->nullable();
                $table->string('event')->nullable();
                $table->string('source')->nullable();
                $table->text('data')->nullable();
                $table->string('description')->nullable();
                $table->string('method')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
            $schema->create('webhook_endpoints', function ($table) {
                $table->string('uuid')->primary();
                $table->string('company_uuid')->nullable()->index();
                $table->string('url')->nullable();
                $table->string('mode')->nullable();
                $table->text('events')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
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
            $schema->create('users', function ($table) {
                $table->string('uuid')->primary();
                $table->string('name')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $now = '2026-07-18 15:00:00';
        $capsule->getConnection('mysql')->table('webhook_endpoints')->insert([
            ['uuid' => 'webhook-enabled', 'company_uuid' => 'company-uuid', 'url' => 'https://hooks.example.test/events', 'mode' => 'live', 'events' => json_encode(['order.updated']), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'webhook-other-event', 'company_uuid' => 'company-uuid', 'url' => 'https://hooks.example.test/skipped-event', 'mode' => 'live', 'events' => json_encode(['order.deleted']), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'webhook-disabled', 'company_uuid' => 'company-uuid', 'url' => 'https://hooks.example.test/disabled', 'mode' => 'live', 'events' => json_encode([]), 'status' => 'disabled', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'webhook-sandbox', 'company_uuid' => 'company-uuid', 'url' => 'https://hooks.example.test/sandbox', 'mode' => 'sandbox', 'events' => json_encode([]), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'webhook-other-company', 'company_uuid' => 'other-company', 'url' => 'https://hooks.example.test/other-company', 'mode' => 'live', 'events' => json_encode([]), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now],
        ]);
        foreach (['mysql', 'sandbox'] as $connectionName) {
            $capsule->getConnection($connectionName)->table('api_credentials')->insert([
                'uuid'         => '11111111-1111-4111-8111-111111111111',
                'company_uuid' => 'company-uuid',
                'key'          => 'flb_live_key',
                'expires_at'   => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $capsule->getConnection($connectionName)->table('personal_access_tokens')->insert([
                'id'             => 44,
                'tokenable_type' => 'Fleetbase\\Models\\User',
                'tokenable_id'   => 1,
                'name'           => 'Console token',
                'token'          => str_repeat('b', 64),
                'abilities'      => json_encode(['*']),
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $capsule->getConnection($connectionName)->table('users')->insert([
                'uuid'       => 'user-uuid',
                'name'       => 'Ron Tester',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        session()->flush();

        return [$capsule, $bus];
    }

    afterEach(function () {
        session()->flush();
        Carbon::setTestNow();
        EloquentModel::clearBootedModels();
        Facade::clearResolvedInstances();
    });

    test('resource lifecycle webhook listener persists api events and dispatches matching enabled webhooks', function () {
        [$capsule, $bus] = resource_lifecycle_webhook_listener_database();
        Carbon::setTestNow(Carbon::parse('2026-07-18 15:30:00', 'UTC'));

        $related = new FleetbaseModel();
        $related->setRawAttributes([
            'uuid'      => 'related-uuid',
            'public_id' => 'related_1234567',
        ], true);

        $record = new FleetbaseModel();
        $record->setRawAttributes([
            'uuid'         => 'record-uuid',
            'public_id'    => 'order_1234567',
            'company_uuid' => 'company-uuid',
            'name'         => 'Order 1001',
            'status'       => 'dispatched',
        ], true);
        $record->relatedResource = new JsonResource($related);

        $event = ResourceLifecycleWebhookListenerEvent::fake([
            'modelName'           => 'order',
            'modelClassNamespace' => FleetbaseModel::class,
            'modelClassName'      => 'Order',
            'modelHumanName'      => 'order',
            'modelRecordName'     => 'Order 1001',
            'modelUuid'           => 'record-uuid',
            'namespace'           => '\\Fleetbase',
            'version'             => 1,
            'eventName'           => 'updated',
            'sentAt'              => '2026-07-18 15:25:00',
            'eventId'             => 'event_lifecycle',
            'apiVersion'          => 'v1',
            'requestMethod'       => 'PATCH',
            'apiCredential'       => 'internal-console',
            'apiSecret'           => 'top-secret',
            'apiKey'              => 'flb_live_key',
            'apiEnvironment'      => 'live',
            'isSandbox'           => false,
            'data'                => [],
            'userSession'         => 'user-uuid',
            'companySession'      => 'company-uuid',
        ], $record, new ResourceLifecycleWebhookListenerResource($record));

        (new SendResourceLifecycleWebhook())->handle($event);

        $apiEvent = ApiEvent::first();
        $payload  = $apiEvent->data;

        expect($capsule->getConnection('mysql')->table('api_events')->count())->toBe(1)
            ->and($apiEvent->company_uuid)->toBe('company-uuid')
            ->and($apiEvent->event)->toBe('order.updated')
            ->and($apiEvent->source)->toBe('api')
            ->and($apiEvent->method)->toBe('PATCH')
            ->and($apiEvent->description)->toBe('A order (Order 1001) was updated via API')
            ->and($apiEvent->api_credential_uuid)->toBeNull()
            ->and($apiEvent->access_token_id)->toBeNull()
            ->and($payload['id'])->toBe('event_lifecycle')
            ->and($payload['api_version'])->toBe('v1')
            ->and($payload['event'])->toBe('order.updated')
            ->and($payload['created_at'])->toBe('2026-07-18 15:25:00')
            ->and($payload['data'])->toBe([
                'id'         => 'order_1234567',
                'uuid'       => 'record-uuid',
                'status'     => 'dispatched',
                'company_id' => 'company-uuid',
                'related'    => 'related_1234567',
                'seen_at'    => '2026-07-18 15:10:00',
            ])
            ->and($bus->jobs)->toHaveCount(1)
            ->and($bus->jobs[0])->toBeInstanceOf(ResourceLifecycleWebhookListenerJob::class)
            ->and($bus->jobs[0]->webhookUrl)->toBe('https://hooks.example.test/events')
            ->and($bus->jobs[0]->payload)->toBe($payload)
            ->and($bus->jobs[0]->queue)->toBe('webhooks')
            ->and($bus->jobs[0]->connection)->toBe('sync')
            ->and($bus->jobs[0]->meta['is_sandbox'])->toBeFalse()
            ->and($bus->jobs[0]->meta['api_key'])->toBe('flb_live_key')
            ->and($bus->jobs[0]->meta['company_uuid'])->toBe('company-uuid')
            ->and($bus->jobs[0]->meta['api_event_uuid'])->toBe($apiEvent->uuid)
            ->and($bus->jobs[0]->meta['webhook_uuid'])->toBe('webhook-enabled')
            ->and($bus->jobs[0]->headers)->toHaveKey('X-Fleetbase-Signature')
            ->and(session('company'))->toBe('company-uuid')
            ->and(session('api_environment'))->toBe('live');
    });

    test('resource lifecycle webhook listener preserves session credential attribution', function () {
        [$capsule, $bus] = resource_lifecycle_webhook_listener_database();

        session()->put('api_credential', '11111111-1111-4111-8111-111111111111');
        session()->put('api_key', 'session-api-key');
        session()->put('api_secret', 'session-secret');

        $record = new FleetbaseModel();
        $record->setRawAttributes([
            'uuid'         => 'record-uuid',
            'public_id'    => 'order_1234567',
            'company_uuid' => 'company-uuid',
            'status'       => 'dispatched',
        ], true);

        $event = ResourceLifecycleWebhookListenerEvent::fake([
            'modelName'           => 'order',
            'modelClassNamespace' => FleetbaseModel::class,
            'modelClassName'      => 'Order',
            'modelHumanName'      => 'order',
            'modelUuid'           => 'record-uuid',
            'namespace'           => '\\Fleetbase',
            'version'             => 1,
            'eventName'           => 'updated',
            'sentAt'              => '2026-07-18 15:25:00',
            'eventId'             => 'event_lifecycle',
            'apiVersion'          => 'v1',
            'requestMethod'       => 'PATCH',
            'apiCredential'       => 'internal-console',
            'apiSecret'           => 'event-secret',
            'apiKey'              => 'event-api-key',
            'apiEnvironment'      => 'live',
            'isSandbox'           => false,
            'data'                => [],
            'userSession'         => null,
            'companySession'      => 'company-uuid',
        ], $record, new ResourceLifecycleWebhookListenerResource($record));

        (new SendResourceLifecycleWebhook())->handle($event);

        $apiEvent = ApiEvent::first();

        expect($apiEvent->api_credential_uuid)->toBe('11111111-1111-4111-8111-111111111111')
            ->and($apiEvent->access_token_id)->toBeNull()
            ->and($bus->jobs)->toHaveCount(1)
            ->and($bus->jobs[0]->meta['api_key'])->toBe('session-api-key')
            ->and($bus->jobs[0]->meta['api_credential_uuid'])->toBe('11111111-1111-4111-8111-111111111111')
            ->and($bus->jobs[0]->meta['access_token_id'])->toBeNull()
            ->and($bus->jobs[0]->headers)->toHaveKey('X-Fleetbase-Signature');
    });

    test('resource lifecycle webhook listener logs failed sandbox dispatches with access token context', function () {
        [$capsule, $bus] = resource_lifecycle_webhook_listener_database();

        config()->set('webhook-server.signer', ResourceLifecycleWebhookListenerFailingSigner::class);
        session()->put('api_credential', '44');
        session()->put('api_environment', 'sandbox');
        session()->put('is_sandbox', true);

        $record = new FleetbaseModel();
        $record->setRawAttributes([
            'uuid'         => 'record-uuid',
            'public_id'    => 'order_1234567',
            'company_uuid' => 'company-uuid',
            'status'       => 'dispatched',
        ], true);

        $event = ResourceLifecycleWebhookListenerEvent::fake([
            'modelName'           => 'order',
            'modelClassNamespace' => FleetbaseModel::class,
            'modelClassName'      => 'Order',
            'modelHumanName'      => 'order',
            'modelUuid'           => 'record-uuid',
            'namespace'           => '\\Fleetbase',
            'version'             => 1,
            'eventName'           => 'updated',
            'sentAt'              => '2026-07-18 15:25:00',
            'eventId'             => 'event_lifecycle',
            'apiVersion'          => 'v1',
            'requestMethod'       => 'PATCH',
            'apiCredential'       => null,
            'apiSecret'           => 'event-secret',
            'apiKey'              => 'event-api-key',
            'apiEnvironment'      => 'live',
            'isSandbox'           => false,
            'data'                => [],
            'userSession'         => null,
            'companySession'      => 'company-uuid',
        ], $record, new ResourceLifecycleWebhookListenerResource($record));

        (new SendResourceLifecycleWebhook())->handle($event);

        $log = $capsule->getConnection('sandbox')->table('webhook_request_logs')->first();

        expect($bus->jobs)->toBeEmpty()
            ->and($capsule->getConnection('mysql')->table('webhook_request_logs')->count())->toBe(0)
            ->and($log->company_uuid)->toBe('company-uuid')
            ->and($log->webhook_uuid)->toBe('webhook-sandbox')
            ->and($log->access_token_id)->toBe(44)
            ->and($log->api_credential_uuid)->toBeNull()
            ->and($log->method)->toBe('POST')
            ->and($log->status_code)->toBe(502)
            ->and($log->reason_phrase)->toBe('Bad Gateway')
            ->and($log->url)->toBe('https://hooks.example.test/events')
            ->and(json_decode($log->response, true))->toBe('upstream unavailable')
            ->and($log->status)->toBe('failed')
            ->and(json_decode($log->headers, true))->toBe(['Content-Type' => ['application/json']])
            ->and(json_decode($log->meta, true)['exception_message'])->toBe('Webhook dispatch failed');
    });

    test('resource lifecycle webhook listener logs failed dispatches with api credential context', function () {
        [$capsule, $bus] = resource_lifecycle_webhook_listener_database();

        config()->set('webhook-server.signer', ResourceLifecycleWebhookListenerFailingSigner::class);
        session()->put('api_credential', '11111111-1111-4111-8111-111111111111');

        $record = new FleetbaseModel();
        $record->setRawAttributes([
            'uuid'         => 'record-uuid',
            'public_id'    => 'order_1234567',
            'company_uuid' => 'company-uuid',
            'status'       => 'dispatched',
        ], true);

        $event = ResourceLifecycleWebhookListenerEvent::fake([
            'modelName'           => 'order',
            'modelClassNamespace' => FleetbaseModel::class,
            'modelClassName'      => 'Order',
            'modelHumanName'      => 'order',
            'modelUuid'           => 'record-uuid',
            'namespace'           => '\\Fleetbase',
            'version'             => 1,
            'eventName'           => 'updated',
            'sentAt'              => '2026-07-18 15:25:00',
            'eventId'             => 'event_lifecycle',
            'apiVersion'          => 'v1',
            'requestMethod'       => 'PATCH',
            'apiCredential'       => null,
            'apiSecret'           => 'event-secret',
            'apiKey'              => 'event-api-key',
            'apiEnvironment'      => 'live',
            'isSandbox'           => false,
            'data'                => [],
            'userSession'         => null,
            'companySession'      => 'company-uuid',
        ], $record, new ResourceLifecycleWebhookListenerResource($record));

        (new SendResourceLifecycleWebhook())->handle($event);

        $log = $capsule->getConnection('mysql')->table('webhook_request_logs')->first();

        expect($bus->jobs)->toBeEmpty()
            ->and($log->webhook_uuid)->toBe('webhook-enabled')
            ->and($log->api_credential_uuid)->toBe('11111111-1111-4111-8111-111111111111')
            ->and($log->access_token_id)->toBeNull()
            ->and($log->status)->toBe('failed');
    });

    test('resource lifecycle webhook listener stops dispatching when api event persistence fails', function () {
        [$capsule, $bus] = resource_lifecycle_webhook_listener_database();
        $capsule->getConnection('mysql')->getSchemaBuilder()->drop('api_events');

        $record = new FleetbaseModel();
        $record->setRawAttributes([
            'uuid'         => 'record-uuid',
            'public_id'    => 'order_1234567',
            'company_uuid' => 'company-uuid',
            'status'       => 'dispatched',
        ], true);

        $event = ResourceLifecycleWebhookListenerEvent::fake([
            'modelName'           => 'order',
            'modelClassNamespace' => FleetbaseModel::class,
            'modelClassName'      => 'Order',
            'modelHumanName'      => 'order',
            'modelUuid'           => 'record-uuid',
            'namespace'           => '\\Fleetbase',
            'version'             => 1,
            'eventName'           => 'updated',
            'sentAt'              => '2026-07-18 15:25:00',
            'eventId'             => 'event_lifecycle',
            'apiVersion'          => 'v1',
            'requestMethod'       => 'PATCH',
            'apiCredential'       => null,
            'apiSecret'           => 'event-secret',
            'apiKey'              => 'event-api-key',
            'apiEnvironment'      => 'live',
            'isSandbox'           => false,
            'data'                => [],
            'userSession'         => null,
            'companySession'      => 'company-uuid',
        ], $record, new ResourceLifecycleWebhookListenerResource($record));

        (new SendResourceLifecycleWebhook())->handle($event);

        expect($bus->jobs)->toBeEmpty();
    });

    test('resource lifecycle webhook listener describes unnamed console events generically', function () {
        resource_lifecycle_webhook_listener_database();

        $event = ResourceLifecycleWebhookListenerEvent::fake([
            'modelHumanName' => 'order',
            'eventName'      => 'deleted',
            'apiEnvironment' => null,
            'apiKey'         => null,
            'userSession'    => null,
        ]);

        expect((new SendResourceLifecycleWebhook())->getHumanReadableEventDescription($event))->toBe('order was deleted');
    });

    test('resource lifecycle webhook listener describes console user events', function () {
        resource_lifecycle_webhook_listener_database();

        $event = ResourceLifecycleWebhookListenerEvent::fake([
            'modelHumanName' => 'order',
            'eventName'      => 'deleted',
            'apiEnvironment' => null,
            'apiKey'         => null,
            'userSession'    => 'user-uuid',
        ]);

        expect((new SendResourceLifecycleWebhook())->getHumanReadableEventDescription($event))->toBe('order was deleted by Ron Tester');
    });
}
