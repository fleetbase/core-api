<?php

use Fleetbase\Http\Controllers\Internal\v1\RateLimitController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Support\ApiConsumerMetrics;
use Fleetbase\Support\ApiRateLimits;
use Fleetbase\Tests\Fixtures\Support\RedisMetricsFake;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;

function rate_limit_controller_fixture(array $config = []): RedisMetricsFake
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container(array_merge([
        'api.cache.enabled'            => false,
        'api.throttle.enabled'         => true,
        'api.throttle.max_attempts'    => 120,
        'api.throttle.decay_minutes'   => 1,
        'api.throttle.track_consumers' => true,
        'api.throttle.unlimited_keys'  => ['Bearer load-test-1', 'Bearer load-test-2'],
        'database.default'             => 'mysql',
        'database.connections.mysql'   => $connection,
        'fleetbase.connection.db'      => 'mysql',
    ], $config));

    $cache = new CacheRepository(new ArrayStore());
    $container->instance('cache', $cache);
    $container->instance(RateLimiter::class, new RateLimiter($cache));
    $redis = new RedisMetricsFake();
    $container->instance('redis', $redis);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-acme', 'public_id' => 'company_acme', 'name' => 'Acme'],
        ['uuid' => 'company-globex', 'public_id' => 'company_globex', 'name' => 'Globex'],
    ]);

    // Mirrors the `validate` request macro the framework's FoundationServiceProvider registers.
    $validation = new ValidationFactory(new Translator(new ArrayLoader(), 'en'));
    $validation->setPresenceVerifier(new DatabasePresenceVerifier($capsule->getDatabaseManager()));
    Request::macro('validate', function (array $rules) use ($validation) {
        return $validation->make($this->all(), $rules)->validate();
    });

    return $redis;
}

function rate_limit_controller_request(string $method = 'GET', array $input = [], string $uri = '/int/v1/rate-limits/settings'): AdminRequest
{
    return AdminRequest::create($uri, $method, $input);
}

afterEach(function () {
    Carbon::setTestNow();

    $macros = new ReflectionProperty(Request::class, 'macros');
    $macros->setAccessible(true);
    $macros->setValue(null, array_diff_key($macros->getValue(), ['validate' => true]));

    config(['api.throttle' => array_diff_key((array) config('api.throttle', []), ['track_consumers' => true, 'unlimited_keys' => true])]);
    app()->forgetInstance('redis');
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('rate limit controller returns the effective settings with the environment defaults beneath them', function () {
    rate_limit_controller_fixture();
    ApiRateLimits::store(['max_attempts' => 60, 'overrides' => [
        ['company_uuid' => 'company-acme', 'unlimited' => true, 'note' => 'Partner'],
        ['company_uuid' => 'company-deleted', 'max_attempts' => 5],
    ]]);

    $response = (new RateLimitController())->getSettings(rate_limit_controller_request());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'settings' => [
                'enabled'         => true,
                'max_attempts'    => 60,
                'decay_minutes'   => 1,
                'track_consumers' => true,
                'overrides'       => [
                    ['company_uuid' => 'company-acme', 'unlimited' => true, 'max_attempts' => null, 'note' => 'Partner', 'company_id' => 'company_acme', 'company_name' => 'Acme'],
                    ['company_uuid' => 'company-deleted', 'unlimited' => false, 'max_attempts' => 5, 'note' => '', 'company_id' => null, 'company_name' => null],
                ],
            ],
            'defaults' => [
                'enabled'         => true,
                'max_attempts'    => 120,
                'decay_minutes'   => 1,
                'track_consumers' => true,
                'overrides'       => [],
            ],
            'unlimited_keys' => 2,
        ]);
});

test('rate limit controller saves validated settings and applies them from the next request', function () {
    rate_limit_controller_fixture();

    $response = (new RateLimitController())->saveSettings(rate_limit_controller_request('POST', [
        'enabled'         => true,
        'max_attempts'    => 250,
        'decay_minutes'   => 2,
        'track_consumers' => false,
        'overrides'       => [
            ['company_uuid' => 'company-globex', 'max_attempts' => 1000, 'note' => 'Bulk importer'],
        ],
    ]));

    expect($response->getData(true)['settings'])->toBe([
        'enabled'         => true,
        'max_attempts'    => 250,
        'decay_minutes'   => 2,
        'track_consumers' => false,
        'overrides'       => [
            ['company_uuid' => 'company-globex', 'unlimited' => false, 'max_attempts' => 1000, 'note' => 'Bulk importer', 'company_id' => 'company_globex', 'company_name' => 'Globex'],
        ],
    ])
        ->and(ApiRateLimits::settings()['max_attempts'])->toBe(250)
        ->and(ApiRateLimits::limitFor('company-globex'))->toBe(1000);
});

test('rate limit controller rejects invalid settings and overrides for unknown organizations', function (array $input) {
    rate_limit_controller_fixture();

    expect(fn () => (new RateLimitController())->saveSettings(rate_limit_controller_request('POST', $input)))
        ->toThrow(ValidationException::class);

    expect(ApiRateLimits::settings()['max_attempts'])->toBe(120);
})->with([
    'missing limit'        => [['enabled' => true, 'decay_minutes' => 1]],
    'zero limit'           => [['enabled' => true, 'max_attempts' => 0, 'decay_minutes' => 1]],
    'decay over a day'     => [['enabled' => true, 'max_attempts' => 10, 'decay_minutes' => 1441]],
    'unknown organization' => [['enabled' => true, 'max_attempts' => 10, 'decay_minutes' => 1, 'overrides' => [['company_uuid' => 'company-missing']]]],
    'duplicate override'   => [['enabled' => true, 'max_attempts' => 10, 'decay_minutes' => 1, 'overrides' => [['company_uuid' => 'company-acme'], ['company_uuid' => 'company-acme']]]],
]);

test('rate limit controller reset discards the administrator settings', function () {
    rate_limit_controller_fixture();
    ApiRateLimits::store(['max_attempts' => 5, 'overrides' => [['company_uuid' => 'company-acme', 'unlimited' => true]]]);

    $response = (new RateLimitController())->resetSettings(rate_limit_controller_request('DELETE'));

    expect($response->getData(true)['settings'])->toBe([
        'enabled'         => true,
        'max_attempts'    => 120,
        'decay_minutes'   => 1,
        'track_consumers' => true,
        'overrides'       => [],
    ])
        ->and(ApiRateLimits::settings()['max_attempts'])->toBe(120);
});

test('rate limit controller lists the busiest consumers with the limits that apply', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:30:00', 'UTC'));
    rate_limit_controller_fixture();
    ApiRateLimits::store(['max_attempts' => 90, 'decay_minutes' => 3, 'track_consumers' => false]);

    ApiConsumerMetrics::record('sig-busy', ['label' => 'Busy']);
    ApiConsumerMetrics::record('sig-busy', ['label' => 'Busy'], true);
    ApiConsumerMetrics::record('sig-quiet', ['label' => 'Quiet']);

    $defaults = (new RateLimitController())->consumers(rate_limit_controller_request('GET', [], '/int/v1/rate-limits/consumers'))->getData(true);
    $sorted   = (new RateLimitController())->consumers(rate_limit_controller_request('GET', ['window' => 60, 'sort' => 'throttled', 'limit' => 1], '/int/v1/rate-limits/consumers'))->getData(true);

    expect($defaults)->toMatchArray([
        'available'      => true,
        'window'         => 15,
        'sort'           => 'hits',
        'total_requests' => 3,
        'consumer_count' => 2,
        'tracking'       => false,
        'default_limit'  => 90,
        'decay_minutes'  => 3,
    ])
        ->and(array_column($defaults['consumers'], 'label'))->toBe(['Busy', 'Quiet'])
        ->and($sorted)->toMatchArray(['window' => 60, 'sort' => 'throttled'])
        ->and(array_column($sorted['consumers'], 'signature'))->toBe(['sig-busy']);
});

test('rate limit controller rejects consumer windows the view does not offer', function () {
    rate_limit_controller_fixture();

    expect(fn () => (new RateLimitController())->consumers(rate_limit_controller_request('GET', ['window' => 7], '/int/v1/rate-limits/consumers')))
        ->toThrow(ValidationException::class);
});

test('rate limit controller resets a consumer limiter and refuses malformed signatures', function () {
    rate_limit_controller_fixture();
    $signature = sha1('fleetbase-throttle|v1|credential|Bearer flb_live_noisy');
    $limiter   = app(RateLimiter::class);
    $limiter->hit($signature, 60);
    $limiter->hit($signature, 60);

    $invalid = (new RateLimitController())->resetConsumer(rate_limit_controller_request('POST'), 'not-a-signature');

    expect($invalid->getStatusCode())->toBe(422)
        ->and($invalid->getData(true))->toBe(['errors' => ['Invalid consumer.']])
        ->and($limiter->attempts($signature))->toBe(2);

    $reset = (new RateLimitController())->resetConsumer(rate_limit_controller_request('POST'), $signature);

    expect($reset->getData(true))->toBe(['status' => 'OK'])
        ->and($limiter->attempts($signature))->toBe(0);
});
