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
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');
        $container->instance('db', $capsule->getDatabaseManager());

        $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
        $schema->create('api_events', function ($table) {
            $table->string('uuid')->primary();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('api_credential_uuid')->nullable();
            $table->integer('access_token_id')->nullable();
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

        $now = '2026-07-18 15:00:00';
        $capsule->getConnection('mysql')->table('webhook_endpoints')->insert([
            ['uuid' => 'webhook-enabled', 'company_uuid' => 'company-uuid', 'url' => 'https://hooks.example.test/events', 'mode' => 'live', 'events' => json_encode(['order.updated']), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'webhook-other-event', 'company_uuid' => 'company-uuid', 'url' => 'https://hooks.example.test/skipped-event', 'mode' => 'live', 'events' => json_encode(['order.deleted']), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'webhook-disabled', 'company_uuid' => 'company-uuid', 'url' => 'https://hooks.example.test/disabled', 'mode' => 'live', 'events' => json_encode([]), 'status' => 'disabled', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'webhook-sandbox', 'company_uuid' => 'company-uuid', 'url' => 'https://hooks.example.test/sandbox', 'mode' => 'sandbox', 'events' => json_encode([]), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'webhook-other-company', 'company_uuid' => 'other-company', 'url' => 'https://hooks.example.test/other-company', 'mode' => 'live', 'events' => json_encode([]), 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now],
        ]);

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
}
