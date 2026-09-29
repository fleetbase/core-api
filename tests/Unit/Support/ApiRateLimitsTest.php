<?php

use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Fleetbase\Support\ApiRateLimits;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

function api_rate_limits_database(array $config = []): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container(array_merge([
        'api.cache.enabled'           => false,
        'api.throttle.enabled'        => true,
        'api.throttle.max_attempts'   => 120,
        'api.throttle.decay_minutes'  => 1,
        'api.throttle.track_consumers'=> true,
        'database.default'            => 'mysql',
        'database.connections.mysql'  => $connection,
        'fleetbase.connection.db'     => 'mysql',
    ], $config));
    $container->instance('cache', new CacheRepository(new ArrayStore()));
    app()->forgetInstance('redis');
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->addConnection($connection, 'sandbox');
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
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type');
        $table->string('tokenable_id');
        $table->string('name')->nullable();
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    foreach (['mysql', 'sandbox'] as $name) {
        $capsule->getConnection($name)->getSchemaBuilder()->create('api_credentials', function ($table) {
            $table->string('uuid')->primary();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('key')->nullable();
            $table->string('secret')->nullable();
            $table->boolean('test_mode')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    $db = $capsule->getConnection('mysql');
    $db->table('companies')->insert([
        ['uuid' => 'company-acme', 'public_id' => 'company_acme', 'name' => 'Acme'],
        ['uuid' => 'company-sandbox', 'public_id' => 'company_sandbox', 'name' => 'Sandbox Org'],
    ]);
    $db->table('users')->insert([
        ['uuid' => 'user-ada', 'company_uuid' => 'company-acme', 'name' => 'Ada Lovelace', 'email' => 'ada@example.test'],
        ['uuid' => 'user-nameless', 'company_uuid' => null, 'name' => null, 'email' => 'nameless@example.test'],
    ]);
    $db->table('personal_access_tokens')->insert([
        ['id' => 1, 'tokenable_type' => User::class, 'tokenable_id' => 'user-ada', 'name' => 'console', 'token' => hash('sha256', 'ada-plain-token'), 'abilities' => '["*"]'],
        ['id' => 2, 'tokenable_type' => User::class, 'tokenable_id' => 'user-nameless', 'name' => 'cli', 'token' => hash('sha256', 'nameless-plain-token'), 'abilities' => '["*"]'],
        ['id' => 3, 'tokenable_type' => User::class, 'tokenable_id' => 'user-missing', 'name' => 'orphan', 'token' => hash('sha256', 'orphan-plain-token'), 'abilities' => '["*"]'],
    ]);
    $db->table('api_credentials')->insert([
        ['uuid' => 'credential-live', 'company_uuid' => 'company-acme', 'name' => 'Production', 'key' => 'flb_live_abcdefghijklmnop', 'secret' => '$live_secret_value', 'test_mode' => 0],
        ['uuid' => 'credential-unnamed', 'company_uuid' => 'company-gone', 'name' => '', 'key' => 'flb_live_unnamed_key', 'secret' => '$unnamed_secret', 'test_mode' => 0],
    ]);
    $capsule->getConnection('sandbox')->table('api_credentials')->insert([
        ['uuid' => 'credential-test', 'company_uuid' => 'company-sandbox', 'name' => 'Sandbox', 'key' => 'flb_test_abcdefghijklmnop', 'secret' => '$test_secret_value', 'test_mode' => 1],
    ]);

    return $capsule;
}

afterEach(function () {
    config(['api.throttle' => array_diff_key((array) config('api.throttle', []), ['track_consumers' => true])]);
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('api rate limits defaults come from the environment configuration', function () {
    bind_test_container([
        'api.throttle.enabled'         => false,
        'api.throttle.max_attempts'    => '300',
        'api.throttle.decay_minutes'   => '5',
        'api.throttle.track_consumers' => false,
    ]);

    expect(ApiRateLimits::defaults())->toBe([
        'enabled'         => false,
        'max_attempts'    => 300,
        'decay_minutes'   => 5,
        'track_consumers' => false,
        'overrides'       => [],
    ]);
});

test('api rate limits settings fall back to the defaults when the cache or database is unavailable', function () {
    // The minimal container cache has no remember(), as on a broken install.
    bind_test_container([
        'api.throttle.enabled'         => true,
        'api.throttle.max_attempts'    => 60,
        'api.throttle.decay_minutes'   => 2,
        'api.throttle.track_consumers' => true,
    ]);
    Facade::clearResolvedInstance('cache');

    expect(ApiRateLimits::settings())->toBe([
        'enabled'         => true,
        'max_attempts'    => 60,
        'decay_minutes'   => 2,
        'track_consumers' => true,
        'overrides'       => [],
    ]);
});

test('api rate limits settings apply the stored administrator override and cache it', function () {
    $capsule = api_rate_limits_database();

    expect(ApiRateLimits::settings()['max_attempts'])->toBe(120);

    // Cached: a row written behind the cache's back is not seen until the cache is cleared.
    $capsule->getConnection('mysql')->table('settings')->insert([
        'key'   => 'system.rate-limits',
        'value' => json_encode(['max_attempts' => 30, 'overrides' => [['company_uuid' => 'company-acme', 'unlimited' => true]]]),
    ]);

    expect(ApiRateLimits::settings()['max_attempts'])->toBe(120);

    cache()->forget(ApiRateLimits::SETTINGS_CACHE_KEY);

    expect(ApiRateLimits::settings())->toBe([
        'enabled'         => true,
        'max_attempts'    => 30,
        'decay_minutes'   => 1,
        'track_consumers' => true,
        'overrides'       => [[
            'company_uuid' => 'company-acme',
            'unlimited'    => true,
            'max_attempts' => null,
            'note'         => '',
        ]],
    ]);
});

test('api rate limits settings ignore a stored value that is not an object', function () {
    $capsule = api_rate_limits_database();
    $capsule->getConnection('mysql')->table('settings')->insert([
        'key'   => 'system.rate-limits',
        'value' => json_encode('garbage'),
    ]);

    expect(ApiRateLimits::settings()['max_attempts'])->toBe(120);
});

test('api rate limits store persists normalized settings and makes them effective immediately', function () {
    api_rate_limits_database();

    expect(ApiRateLimits::settings()['max_attempts'])->toBe(120);

    $stored = ApiRateLimits::store([
        'max_attempts'  => 45,
        'decay_minutes' => 0,
        'overrides'     => [['company_uuid' => 'company-acme', 'max_attempts' => '500', 'note' => 'Partner']],
    ]);

    $expected = [
        'enabled'         => true,
        'max_attempts'    => 45,
        'decay_minutes'   => 1,
        'track_consumers' => true,
        'overrides'       => [[
            'company_uuid' => 'company-acme',
            'unlimited'    => false,
            'max_attempts' => 500,
            'note'         => 'Partner',
        ]],
    ];

    expect($stored)->toBe($expected)
        ->and(Setting::where('key', 'system.rate-limits')->value('value'))->toBe($expected)
        ->and(ApiRateLimits::settings())->toBe($expected);
});

test('api rate limits reset drops the override and returns the environment defaults', function () {
    api_rate_limits_database();
    ApiRateLimits::store(['max_attempts' => 10, 'enabled' => false]);

    expect(ApiRateLimits::settings()['max_attempts'])->toBe(10);

    $reset = ApiRateLimits::reset();

    expect($reset['max_attempts'])->toBe(120)
        ->and($reset['enabled'])->toBeTrue()
        ->and(Setting::where('key', 'system.rate-limits')->exists())->toBeFalse();
});

test('api rate limits normalize coerces values and drops overrides without an organization', function () {
    expect(ApiRateLimits::normalize([
        'enabled'         => 0,
        'max_attempts'    => '-5',
        'decay_minutes'   => '3',
        'track_consumers' => '',
        'overrides'       => [
            ['company_uuid' => 'company-a', 'max_attempts' => 'lots', 'unlimited' => 1, 'note' => null],
            ['company_uuid' => ''],
            ['company_uuid' => 42],
            ['max_attempts' => 5],
            ['company_uuid' => 'company-b', 'max_attempts' => 0],
            // A later override for the same organization replaces the earlier one.
            ['company_uuid' => 'company-a', 'max_attempts' => 7],
        ],
    ]))->toBe([
        'enabled'         => false,
        'max_attempts'    => 1,
        'decay_minutes'   => 3,
        'track_consumers' => false,
        'overrides'       => [
            ['company_uuid' => 'company-a', 'unlimited' => false, 'max_attempts' => 7, 'note' => ''],
            ['company_uuid' => 'company-b', 'unlimited' => false, 'max_attempts' => 1, 'note' => ''],
        ],
    ])
        ->and(ApiRateLimits::normalize([]))->toBe([
            'enabled'         => true,
            'max_attempts'    => 120,
            'decay_minutes'   => 1,
            'track_consumers' => true,
            'overrides'       => [],
        ]);
});

test('api rate limits limit for applies organization overrides and lifts unlimited ones', function () {
    $settings = ApiRateLimits::normalize([
        'max_attempts' => 100,
        'overrides'    => [
            ['company_uuid' => 'company-raised', 'max_attempts' => 1000],
            ['company_uuid' => 'company-default'],
            ['company_uuid' => 'company-unlimited', 'unlimited' => true, 'max_attempts' => 5],
        ],
    ]);

    expect(ApiRateLimits::limitFor(null, $settings))->toBe(100)
        ->and(ApiRateLimits::limitFor('company-other', $settings))->toBe(100)
        ->and(ApiRateLimits::limitFor('company-raised', $settings))->toBe(1000)
        ->and(ApiRateLimits::limitFor('company-default', $settings))->toBe(100)
        ->and(ApiRateLimits::limitFor('company-unlimited', $settings))->toBeNull();
});

test('api rate limits limit for reads the effective settings when none are given', function () {
    api_rate_limits_database();
    ApiRateLimits::store(['max_attempts' => 80, 'overrides' => [['company_uuid' => 'company-acme', 'unlimited' => true]]]);

    expect(ApiRateLimits::limitFor(null))->toBe(80)
        ->and(ApiRateLimits::limitFor('company-acme'))->toBeNull();
});

test('api rate limits identify resolves live and sandbox api keys by key or secret', function () {
    api_rate_limits_database();

    expect(ApiRateLimits::identify('Bearer flb_live_abcdefghijklmnop'))->toBe([
        'type'            => 'api_key',
        'label'           => 'Production',
        'detail'          => 'flb_live_abc…',
        'test_mode'       => false,
        'credential_uuid' => 'credential-live',
        'company_uuid'    => 'company-acme',
        'company_id'      => 'company_acme',
        'company_name'    => 'Acme',
    ])
        ->and(ApiRateLimits::identify('Bearer $live_secret_value')['credential_uuid'])->toBe('credential-live')
        // A secret carries no mode prefix, so a live miss falls through to the sandbox.
        ->and(ApiRateLimits::identify('Bearer $test_secret_value')['credential_uuid'])->toBe('credential-test')
        ->and(ApiRateLimits::identify('Bearer flb_test_abcdefghijklmnop'))->toBe([
            'type'            => 'api_key',
            'label'           => 'Sandbox',
            'detail'          => 'flb_test_abc…',
            'test_mode'       => true,
            'credential_uuid' => 'credential-test',
            'company_uuid'    => 'company-sandbox',
            'company_id'      => 'company_sandbox',
            'company_name'    => 'Sandbox Org',
        ])
        // An unnamed key whose organization no longer exists keeps the raw organization id.
        ->and(ApiRateLimits::identify('flb_live_unnamed_key'))->toMatchArray([
            'label'        => 'API key',
            'company_uuid' => 'company-gone',
            'company_id'   => null,
            'company_name' => null,
        ]);
});

test('api rate limits identify resolves sanctum personal access tokens to their user', function () {
    api_rate_limits_database();

    expect(ApiRateLimits::identify('Bearer 1|ada-plain-token'))->toBe([
        'type'            => 'token',
        'label'           => 'Ada Lovelace',
        'detail'          => 'console',
        'test_mode'       => false,
        'credential_uuid' => null,
        'company_uuid'    => 'company-acme',
        'company_id'      => 'company_acme',
        'company_name'    => 'Acme',
    ])
        ->and(ApiRateLimits::identify('Bearer 2|nameless-plain-token'))->toMatchArray([
            'type'         => 'token',
            'label'        => 'nameless@example.test',
            'detail'       => 'cli',
            'company_uuid' => null,
            'company_id'   => null,
        ])
        ->and(ApiRateLimits::identify('Bearer 3|orphan-plain-token')['type'])->toBe('unknown')
        ->and(ApiRateLimits::identify('Bearer 1|wrong-plain-token')['type'])->toBe('unknown');
});

test('api rate limits identify describes unknown credentials without revealing them and caches the answer', function () {
    $capsule = api_rate_limits_database();

    $unknown = ApiRateLimits::identify('Bearer flb_live_unknown_credential');

    expect($unknown)->toBe([
        'type'            => 'unknown',
        'label'           => 'Unrecognized credential',
        'detail'          => 'flb_live_unk…',
        'test_mode'       => false,
        'credential_uuid' => null,
        'company_uuid'    => null,
        'company_id'      => null,
        'company_name'    => null,
    ])
        ->and(ApiRateLimits::identify('Basic:someone')['detail'])->toBe('Basic:…');

    // A credential created after its first lookup stays unknown until the cache expires.
    $capsule->getConnection('mysql')->table('api_credentials')->insert([
        'uuid' => 'credential-late', 'company_uuid' => 'company-acme', 'name' => 'Late', 'key' => 'flb_live_unknown_credential', 'secret' => '$late', 'test_mode' => 0,
    ]);

    expect(ApiRateLimits::identify('Bearer flb_live_unknown_credential')['type'])->toBe('unknown');

    cache()->forget(ApiRateLimits::CONSUMER_CACHE_PREFIX . sha1('Bearer flb_live_unknown_credential'));

    expect(ApiRateLimits::identify('Bearer flb_live_unknown_credential')['label'])->toBe('Late');
});

test('api rate limits identify reports an unknown consumer when the lookup fails', function () {
    bind_test_container();
    Facade::clearResolvedInstance('cache');

    expect(ApiRateLimits::identify('Bearer flb_live_abcdefghijklmnop'))->toMatchArray([
        'type'   => 'unknown',
        'detail' => 'flb_live_abc…',
    ])
        ->and(ApiRateLimits::identify('Bearer ')['detail'])->toBe('Bea…');
});

test('api rate limits mask keeps at most half of a value and never more than twelve characters', function () {
    expect(ApiRateLimits::mask(null))->toBeNull()
        ->and(ApiRateLimits::mask(''))->toBeNull()
        ->and(ApiRateLimits::mask('abcd'))->toBe('ab…')
        ->and(ApiRateLimits::mask('flb_live_abcdefghijklmnop'))->toBe('flb_live_abc…');
});
