<?php

use Fleetbase\Http\Controllers\Internal\v1\OnboardController;
use Fleetbase\Models\User;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class OnboardControllerTaggedCacheFake
{
    public function tags(array|string $tags): self
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

    public function delete(string $key): bool
    {
        return true;
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}

class OnboardControllerResponseCacheFake
{
    public function clear(): void
    {
    }
}

function onboard_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.key'                    => 'base64:' . base64_encode(str_repeat('a', 32)),
        'api.cache.enabled'          => false,
        'activitylog.enabled'        => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
        'sanctum.expiration'         => null,
    ]);

    $cache = new OnboardControllerTaggedCacheFake();
    $container->instance('cache', $cache);
    $container->instance('responsecache', new OnboardControllerResponseCacheFake());
    Cache::swap($cache);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance(ConfigRepositoryContract::class, $container->make('config'));
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->timestamp('onboarding_completed_at')->nullable();
        $table->string('onboarding_completed_by_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('username')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->string('timezone')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('verification_codes', function ($table) {
        $table->string('uuid')->primary();
        $table->string('subject_uuid')->nullable()->index();
        $table->string('subject_type')->nullable();
        $table->string('code')->nullable();
        $table->string('for')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->text('meta')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type');
        $table->string('tokenable_id');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    return $capsule;
}

function onboard_controller_seed_user(Capsule $capsule, array $user = [], array $company = []): void
{
    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('companies')->insert(array_merge([
        'uuid'                         => 'company-1',
        'public_id'                    => 'company_1',
        'name'                         => 'Acme Logistics',
        'owner_uuid'                   => '11111111-1111-4111-8111-111111111111',
        'onboarding_completed_at'      => null,
        'onboarding_completed_by_uuid' => null,
        'deleted_at'                   => null,
        'created_at'                   => $now,
        'updated_at'                   => $now,
    ], $company));
    $capsule->getConnection('mysql')->table('users')->insert(array_merge([
        'uuid'              => '11111111-1111-4111-8111-111111111111',
        'public_id'         => 'user_1',
        'company_id'        => 'company-1',
        'company_uuid'      => 'company-1',
        'name'              => 'Ada Lovelace',
        'username'          => 'ada',
        'email'             => 'ada@example.test',
        'phone'             => '+15555550123',
        'type'              => 'user',
        'timezone'          => 'UTC',
        'status'            => 'pending',
        'email_verified_at' => null,
        'phone_verified_at' => null,
        'last_login'        => null,
        'deleted_at'        => null,
        'created_at'        => $now,
        'updated_at'        => $now,
    ], $user));
}

function onboard_controller_seed_code(Capsule $capsule, array $attributes = []): void
{
    $capsule->getConnection('mysql')->table('verification_codes')->insert(array_merge([
        'uuid'         => 'verification-code-1',
        'subject_uuid' => '11111111-1111-4111-8111-111111111111',
        'subject_type' => User::class,
        'code'         => '123456',
        'for'          => 'email_verification',
        'expires_at'   => '2026-07-18 12:00:00',
        'meta'         => json_encode([]),
        'status'       => 'active',
        'deleted_at'   => null,
        'created_at'   => '2026-07-18 00:00:00',
        'updated_at'   => '2026-07-18 00:00:00',
    ], $attributes));
}

function onboard_controller(): OnboardController
{
    return new OnboardController();
}

function onboard_request(array $input = [], ?User $user = null): Request
{
    $request = Request::create('/int/v1/onboard/verify-email', 'POST', $input);
    if ($user) {
        $request->setUserResolver(fn () => $user);
    }

    return $request;
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    config([
        'api.cache.enabled'       => null,
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
    ]);
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('onboard controller reports whether first organization setup is required', function () {
    $capsule = onboard_controller_database();

    $empty = onboard_controller()->shouldOnboard();
    onboard_controller_seed_user($capsule);
    $existing = onboard_controller()->shouldOnboard();

    expect($empty->getStatusCode())->toBe(200)
        ->and($empty->getData(true))->toBe(['should_onboard' => true])
        ->and($existing->getStatusCode())->toBe(200)
        ->and($existing->getData(true))->toBe(['should_onboard' => false]);
});

test('onboard controller validates verification resend session identity before creating codes', function () {
    $capsule = onboard_controller_database();
    onboard_controller_seed_user($capsule);

    $emailMismatch = onboard_controller()->sendVerificationEmail(onboard_request([
        'session' => base64_encode('11111111-1111-4111-8111-111111111111'),
        'email'   => 'other@example.test',
    ]));
    $emailMissing = onboard_controller()->sendVerificationEmail(onboard_request([
        'session' => base64_encode('99999999-9999-4999-8999-999999999999'),
        'email'   => 'missing@example.test',
    ]));
    $smsMismatch = onboard_controller()->sendVerificationSms(onboard_request([
        'session' => base64_encode('11111111-1111-4111-8111-111111111111'),
        'phone'   => '+15555550999',
    ]));

    expect($emailMismatch->getStatusCode())->toBe(400)
        ->and($emailMismatch->getData(true))->toBe(['errors' => ['Email address provided does not match for this verification session.']])
        ->and($emailMissing->getStatusCode())->toBe(400)
        ->and($emailMissing->getData(true))->toBe(['errors' => ['No user found with provided email address.']])
        ->and($smsMismatch->getStatusCode())->toBe(400)
        ->and($smsMismatch->getData(true))->toBe(['errors' => ['Phone number provided does not match for this verification session.']])
        ->and($capsule->getConnection('mysql')->table('verification_codes')->count())->toBe(0);
});

test('onboard controller rejects missing sessions invalid codes and missing users during verification', function () {
    $capsule = onboard_controller_database();
    onboard_controller_seed_user($capsule);
    onboard_controller_seed_code($capsule);

    $missingSession = onboard_controller()->verifyEmail(onboard_request([
        'session' => 'not-base64-or-uuid',
        'code'    => '123456',
    ]));
    $invalidCode = onboard_controller()->verifyEmail(onboard_request([
        'session' => base64_encode('11111111-1111-4111-8111-111111111111'),
        'code'    => '000000',
    ]));
    $missingUser = onboard_controller()->verifyEmail(onboard_request([
        'session' => base64_encode('99999999-9999-4999-8999-999999999999'),
        'code'    => '123456',
    ]));

    expect($missingSession->getStatusCode())->toBe(400)
        ->and($missingSession->getData(true))->toBe(['errors' => ['No session to verify email for.']])
        ->and($invalidCode->getStatusCode())->toBe(400)
        ->and($invalidCode->getData(true))->toBe(['errors' => ['Invalid verification code.']])
        ->and($missingUser->getStatusCode())->toBe(400)
        ->and($missingUser->getData(true))->toBe(['errors' => ['Invalid verification code.']]);
});

test('onboard controller verifies email creates token updates login and completes onboarding', function () {
    $capsule = onboard_controller_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 09:30:00', 'UTC'));
    onboard_controller_seed_user($capsule);
    onboard_controller_seed_code($capsule);

    $response = onboard_controller()->verifyEmail(onboard_request([
        'session' => base64_encode('11111111-1111-4111-8111-111111111111'),
        'code'    => '123456',
    ]));

    $payload = $response->getData(true);
    $user    = $capsule->getConnection('mysql')->table('users')->where('uuid', '11111111-1111-4111-8111-111111111111')->first();
    $company = $capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['verified_at'])->toBe('2026-07-18T09:30:00.000000Z')
        ->and($payload['token'])->toContain('|')
        ->and($user->email_verified_at)->toBe('2026-07-18 09:30:00')
        ->and($user->phone_verified_at)->toBeNull()
        ->and($user->status)->toBe('active')
        ->and($user->last_login)->toBe('2026-07-18 09:30:00')
        ->and($company->onboarding_completed_at)->toBe('2026-07-18 09:30:00')
        ->and($company->onboarding_completed_by_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
});

test('onboard controller verifies phone codes without overwriting completed onboarding', function () {
    $capsule = onboard_controller_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:30:00', 'UTC'));
    onboard_controller_seed_user(
        $capsule,
        ['email_verified_at'       => '2026-07-17 08:00:00'],
        ['onboarding_completed_at' => '2026-07-17 08:00:00', 'onboarding_completed_by_uuid' => '22222222-2222-4222-8222-222222222222']
    );
    onboard_controller_seed_code($capsule, [
        'uuid' => 'verification-code-2',
        'code' => '654321',
        'for'  => 'phone_verification',
    ]);

    $response = onboard_controller()->verifyEmail(onboard_request([
        'session' => '11111111-1111-4111-8111-111111111111',
        'code'    => '654321',
    ]));

    $user    = $capsule->getConnection('mysql')->table('users')->where('uuid', '11111111-1111-4111-8111-111111111111')->first();
    $company = $capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('ok')
        ->and($user->email_verified_at)->toBe('2026-07-17 08:00:00')
        ->and($user->phone_verified_at)->toBe('2026-07-18 10:30:00')
        ->and($company->onboarding_completed_at)->toBe('2026-07-17 08:00:00')
        ->and($company->onboarding_completed_by_uuid)->toBe('22222222-2222-4222-8222-222222222222');
});
