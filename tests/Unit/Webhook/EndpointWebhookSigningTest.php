<?php

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(Dispatchable::class)) {
        trait Dispatchable
        {
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

namespace {
    use Fleetbase\Http\Controllers\Api\v1\WebhookEndpointController as ApiWebhookEndpointController;
    use Fleetbase\Http\Controllers\Internal\v1\WebhookEndpointController as InternalWebhookEndpointController;
    use Fleetbase\Http\Requests\Api\WebhookEndpointRequest;
    use Fleetbase\Listeners\SendResourceLifecycleWebhook;
    use Fleetbase\Models\WebhookEndpoint;
    use Fleetbase\Webhook\CallWebhookJob;
    use Fleetbase\Webhook\Signer\DefaultSigner;
    use Fleetbase\Webhook\Signer\EndpointSignature;
    use Fleetbase\Webhook\WebhookCall;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\Facade;

    /**
     * Stand-in for the host app's encrypter (core-api does not ship illuminate/encryption): reversible,
     * and visibly different from the plain value so tests can tell stored ciphertext from the secret.
     */
    class EndpointSigningEncrypterFake implements Illuminate\Contracts\Encryption\Encrypter, Illuminate\Contracts\Encryption\StringEncrypter
    {
        public function encrypt($value, $serialize = true)
        {
            return 'enc:' . base64_encode($serialize ? serialize($value) : (string) $value);
        }

        public function decrypt($payload, $unserialize = true)
        {
            $value = base64_decode(substr((string) $payload, 4), true);

            return $unserialize ? unserialize($value) : $value;
        }

        public function encryptString($value)
        {
            return $this->encrypt($value, false);
        }

        public function decryptString($payload)
        {
            return $this->decrypt($payload, false);
        }

        public function getKey()
        {
            return 'test';
        }

        public function getAllKeys()
        {
            return ['test'];
        }

        public function getPreviousKeys()
        {
            return [];
        }
    }

    class EndpointSigningCacheFake
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
            return $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;
        }

        public function flush(): bool
        {
            $this->values = [];

            return true;
        }
    }

    /**
     * Per-endpoint webhook signing secrets: the X-Fleetbase-Signature header, secret storage on the
     * endpoint model, the API-key webhook endpoint routes, and the console reveal/rotate actions.
     */
    function endpoint_signing_boot(array $config = []): Capsule
    {
        $container = bind_test_container(array_merge([
            'database.default'                     => 'mysql',
            'database.connections.mysql'           => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'fleetbase.connection.db'              => 'mysql',
            'activitylog.enabled'                  => false,
            'api.cache.enabled'                    => false,
            'api.events'                           => ['order.created', 'order.updated'],
            'api.versions'                         => ['2023-01-01', '2026-10-01'],
            'webhook-server.signer'                => DefaultSigner::class,
            'webhook-server.signature_header_name' => 'Signature',
            'webhook-server.headers'               => ['Content-Type' => 'application/json'],
            'webhook-server.http_verb'             => 'post',
            'webhook-server.timeout_in_seconds'    => 5,
        ], $config));
        $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));
        $container->instance('encrypter', new EndpointSigningEncrypterFake());
        $container->instance('cache', new EndpointSigningCacheFake());
        $container->instance('responsecache', new class {
            public function clear(array $tags = []): bool
            {
                return true;
            }
        });
        Facade::clearResolvedInstances();

        $capsule = new Capsule($container);
        $capsule->addConnection(config('database.connections.mysql'), 'mysql');
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        // Model event listeners register once per boot; re-boot so they attach to this dispatcher
        Illuminate\Database\Eloquent\Model::clearBootedModels();
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');
        $container->instance('db', $capsule->getDatabaseManager());

        $capsule->getConnection('mysql')->getSchemaBuilder()->create('webhook_endpoints', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('_key')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('created_by_uuid')->nullable();
            $table->string('updated_by_uuid')->nullable();
            $table->string('api_credential_uuid')->nullable();
            $table->string('url')->nullable();
            $table->string('mode')->nullable();
            $table->string('version')->nullable();
            $table->text('secret')->nullable();
            $table->string('description')->nullable();
            $table->text('events')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        return $capsule;
    }

    function endpoint_signing_job(WebhookCall $call): CallWebhookJob
    {
        $prepare = new ReflectionMethod($call, 'prepareForDispatch');
        $prepare->setAccessible(true);
        $prepare->invoke($call);

        $property = new ReflectionProperty($call, 'callWebhookJob');
        $property->setAccessible(true);

        return $property->getValue($call);
    }

    afterEach(function () {
        Facade::clearResolvedInstances();
    });

    test('endpoint signatures cover timestamp and body and reject tampering, age and malformed headers', function () {
        $header = EndpointSignature::header('{"a":1}', 'whsec_test', 1700000000);

        expect($header)->toBe('t=1700000000,v1=' . hash_hmac('sha256', '1700000000.{"a":1}', 'whsec_test'))
            ->and(EndpointSignature::verify('{"a":1}', $header, 'whsec_test', 300, 1700000100))->toBeTrue()
            ->and(EndpointSignature::verify('{"a":2}', $header, 'whsec_test', 300, 1700000100))->toBeFalse()
            ->and(EndpointSignature::verify('{"a":1}', $header, 'other', 300, 1700000100))->toBeFalse()
            ->and(EndpointSignature::verify('{"a":1}', $header, 'whsec_test', 60, 1700000100))->toBeFalse()
            ->and(EndpointSignature::verify('{"a":1}', null, 'whsec_test'))->toBeFalse()
            ->and(EndpointSignature::verify('{"a":1}', $header, ''))->toBeFalse()
            ->and(EndpointSignature::verify('{"a":1}', 'v1=abc', 'whsec_test'))->toBeFalse()
            ->and(EndpointSignature::verify('{"a":1}', 't=abc,v1=x', 'whsec_test'))->toBeFalse();

        // Several v1 values (during a secret rotation) pass if any matches; a bare key is ignored
        $rotating = 't=1700000000,v1=deadbeef,junk,' . explode(',', $header)[1];
        expect(EndpointSignature::verify('{"a":1}', $rotating, 'whsec_test', 300, 1700000000))->toBeTrue()
            ->and(strlen(explode('=', EndpointSignature::header('x', 's'))[1]))->toBeGreaterThan(9);
    });

    test('webhook calls add the endpoint signature for body requests only', function () {
        endpoint_signing_boot();

        $job = endpoint_signing_job(WebhookCall::create()->url('https://hooks.example.test/x')->payload(['event' => 'order.updated'])->useSecret('legacy')->useEndpointSecret('whsec_abc'));
        expect($job->headers)->toHaveKeys(['Signature', EndpointSignature::HEADER])
            ->and(EndpointSignature::verify(json_encode(['event' => 'order.updated']), $job->headers[EndpointSignature::HEADER], 'whsec_abc'))->toBeTrue();

        $get  = endpoint_signing_job(WebhookCall::create()->url('https://hooks.example.test/x')->payload(['a' => 1])->useHttpVerb('GET')->useSecret('legacy')->useEndpointSecret('whsec_abc'));
        $none = endpoint_signing_job(WebhookCall::create()->url('https://hooks.example.test/x')->payload(['a' => 1])->useSecret('legacy')->useEndpointSecret(''));
        expect($get->headers)->not->toHaveKey(EndpointSignature::HEADER)
            ->and($none->headers)->not->toHaveKey(EndpointSignature::HEADER);
    });

    test('a legacy header configured under the same name is not overwritten', function () {
        endpoint_signing_boot(['webhook-server.signature_header_name' => 'X-Fleetbase-Signature']);

        $job = endpoint_signing_job(WebhookCall::create()->url('https://hooks.example.test/x')->payload(['a' => 1])->useSecret('legacy')->useEndpointSecret('whsec_abc'));
        expect($job->headers['X-Fleetbase-Signature'])->toBe(hash_hmac('sha256', json_encode(['a' => 1]), 'legacy'));
    });

    test('endpoints get an encrypted hidden secret on creation, on first use, and on rotation', function () {
        $capsule = endpoint_signing_boot();

        $endpoint = WebhookEndpoint::create(['company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/a', 'mode' => 'live', 'status' => 'enabled']);
        $raw      = $capsule->getConnection('mysql')->table('webhook_endpoints')->where('uuid', $endpoint->uuid)->value('secret');

        expect($endpoint->secret)->toStartWith(WebhookEndpoint::SECRET_PREFIX)
            ->and(strlen($endpoint->secret))->toBe(strlen(WebhookEndpoint::SECRET_PREFIX) + 48)
            ->and($raw)->not->toBe($endpoint->secret)
            ->and($raw)->not->toContain('whsec_')
            ->and($endpoint->getHidden())->toContain('secret')
            ->and($endpoint->signingSecret())->toBe($endpoint->secret);

        // An endpoint created before secrets existed gets one the first time it is needed
        $capsule->getConnection('mysql')->table('webhook_endpoints')->insert(['uuid' => 'legacy-1', 'company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/b', 'mode' => 'live']);
        $legacy = WebhookEndpoint::where('uuid', 'legacy-1')->first();
        $first  = $legacy->signingSecret();
        expect($first)->toStartWith('whsec_')
            ->and(WebhookEndpoint::where('uuid', 'legacy-1')->first()->secret)->toBe($first);

        $rotated = $legacy->rotateSecret();
        expect($rotated)->not->toBe($first)
            ->and(WebhookEndpoint::where('uuid', 'legacy-1')->first()->secret)->toBe($rotated);
    });

    test('the lifecycle listener falls back to legacy signing when a secret cannot be loaded', function () {
        endpoint_signing_boot();
        $listener = new class extends SendResourceLifecycleWebhook {
            public function secretFor(WebhookEndpoint $webhook): ?string
            {
                return $this->endpointSecret($webhook);
            }
        };
        $broken = new class extends WebhookEndpoint {
            public function signingSecret(): string
            {
                throw new RuntimeException('decrypt failed');
            }
        };
        $working = new WebhookEndpoint();
        $working->setRawAttributes(['uuid' => 'w'], true);
        $working->secret = 'whsec_known';

        expect($listener->secretFor($broken))->toBeNull()
            ->and($listener->secretFor($working))->toBe('whsec_known');
    });

    function endpoint_api_request(string $method, array $input = []): WebhookEndpointRequest
    {
        return WebhookEndpointRequest::create('/v1/webhook-endpoints', $method, $input);
    }

    test('api keys manage only their company and environment endpoints and see secrets once', function () {
        endpoint_signing_boot();
        session(['company' => 'company-1', 'api_credential' => 'cred-1', 'api_environment' => 'test']);
        $controller = new ApiWebhookEndpointController();

        $created = $controller->create(endpoint_api_request('POST', ['url' => 'https://hooks.example.test/shop', 'events' => ['order.updated'], 'description' => 'Shopify']));
        $body    = $created->getData(true);
        expect($body['id'] ?? null)->toBeString();
        expect($created->getStatusCode())->toBe(201)
            ->and($body['secret'])->toStartWith('whsec_')
            ->and($body['mode'])->toBe('test')
            ->and($body['version'])->toBe('2026-10-01')
            ->and($body['events'])->toBe(['order.updated'])
            ->and(WebhookEndpoint::where('uuid', $body['id'])->value('api_credential_uuid'))->toBe('cred-1');

        // Another company's endpoint and a live endpoint are invisible to this test key
        WebhookEndpoint::create(['company_uuid' => 'company-2', 'url' => 'https://hooks.example.test/other', 'mode' => 'test']);
        $live = WebhookEndpoint::create(['company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/live', 'mode' => 'live']);

        $list = $controller->query(new Illuminate\Http\Request())->getData(true);
        expect($list)->toHaveCount(1)
            ->and($list[0])->not->toHaveKey('secret')
            ->and($controller->query(new Illuminate\Http\Request(['url' => 'https://nope.test']))->getData(true))->toBe([])
            ->and($controller->find($live->uuid)->getStatusCode())->toBe(404)
            ->and($controller->find($body['id'])->getData(true))->not->toHaveKey('secret');

        $updated = $controller->update($body['id'], endpoint_api_request('PUT', ['status' => 'disabled', 'events' => []]))->getData(true);
        expect($updated['status'])->toBe('disabled')
            ->and($updated['events'])->toBe([])
            ->and($controller->update($live->uuid, endpoint_api_request('PUT', ['status' => 'disabled']))->getStatusCode())->toBe(404);

        $rotated = $controller->rotateSecret($body['id'])->getData(true);
        expect($rotated['secret'])->not->toBe($body['secret'])
            ->and($controller->rotateSecret($live->uuid)->getStatusCode())->toBe(404);

        expect($controller->delete($live->uuid)->getStatusCode())->toBe(404)
            ->and($controller->delete($body['id'])->getData(true))->toBe(['id' => $body['id'], 'deleted' => true])
            ->and(WebhookEndpoint::where('uuid', $body['id'])->first())->toBeNull();

        // Live keys create live endpoints; no configured versions leaves the version empty
        session(['api_environment' => 'live']);
        config()->set('api.versions', []);
        $liveBody = $controller->create(endpoint_api_request('POST', ['url' => 'https://hooks.example.test/live2']))->getData(true);
        expect($liveBody['mode'])->toBe('live')
            ->and($liveBody['version'])->toBeNull();
    });

    test('the api webhook request validates urls, events and status', function () {
        endpoint_signing_boot();
        session(['company' => 'company-1', 'api_credential' => 'cred-1']);

        $post = endpoint_api_request('POST')->rules();
        $put  = endpoint_api_request('PUT')->rules();
        expect($post['url'][0])->toBe('required')
            ->and($put['url'][0])->toBe('sometimes')
            ->and($post['events.*'])->toBe(['string', 'in:order.created,order.updated'])
            ->and($post['status'])->toBe(['sometimes', 'in:enabled,disabled'])
            ->and(endpoint_api_request('POST')->authorize())->toBeTrue()
            ->and(endpoint_api_request('POST')->messages())->toHaveKeys(['url.required', 'url.url', 'events.*.in']);

        config()->set('api.events', []);
        expect(endpoint_api_request('POST')->rules()['events.*'])->toBe(['string', 'string']);

        session(['api_credential' => null]);
        expect(endpoint_api_request('POST')->authorize())->toBeFalse();
    });

    test('console users can reveal and rotate an endpoint secret in their company', function () {
        endpoint_signing_boot();
        session(['company' => 'company-1']);
        $endpoint = WebhookEndpoint::create(['company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/c', 'mode' => 'live']);
        WebhookEndpoint::create(['company_uuid' => 'company-2', 'url' => 'https://hooks.example.test/d', 'mode' => 'live', 'uuid' => 'foreign-1']);
        $controller = (new ReflectionClass(InternalWebhookEndpointController::class))->newInstanceWithoutConstructor();

        $revealed = $controller->revealSecret($endpoint->uuid)->getData(true);
        $rotated  = $controller->rotateSecret($endpoint->uuid)->getData(true);
        expect($revealed['secret'])->toBe($endpoint->secret)
            ->and($rotated['secret'])->not->toBe($revealed['secret'])
            ->and($controller->revealSecret('foreign-1')->getStatusCode())->toBe(404)
            ->and($controller->rotateSecret('missing')->getStatusCode())->toBe(404);
    });
}
