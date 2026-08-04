<?php

use Fleetbase\Http\Controllers\Internal\v1\TwoFaController;
use Fleetbase\Http\Requests\TwoFaValidationRequest;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class TwoFaControllerRedisFake
{
    public array $values  = [];
    public array $deleted = [];

    public function set(string $key, mixed $value, mixed ...$options): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function del(?string $key): bool
    {
        $this->deleted[] = $key;
        unset($this->values[$key]);

        return true;
    }

    public function connection(): self
    {
        return $this;
    }

    public function keys(string $pattern): array
    {
        return [];
    }
}

class TwoFaControllerCacheFake
{
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function rememberForever(string $key, callable $callback): mixed
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
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function getPrefix(): string
    {
        return 'fleetbase_cache:';
    }
}

class TwoFaControllerMailerFake
{
    public array $recipients = [];

    public array $sent = [];

    public function to(mixed $recipient): self
    {
        $this->recipients[] = $recipient;

        return $this;
    }

    public function send(mixed $mail): void
    {
        $this->sent[] = $mail;
    }
}

class TwoFaControllerResponseCacheFake
{
    public function clear(): void
    {
    }
}

function two_fa_controller_database(): TwoFaControllerRedisFake
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00'));

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.name'                   => 'Fleetbase',
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        });
    }

    $redis = new TwoFaControllerRedisFake();
    $container->instance('redis', $redis);
    $container->instance('cache', new TwoFaControllerCacheFake());
    $container->instance('mail.manager', new TwoFaControllerMailerFake());
    $container->instance('responsecache', new TwoFaControllerResponseCacheFake());
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));
    Facade::clearResolvedInstance('redis');
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('mail.manager');
    Facade::clearResolvedInstance('responsecache');

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
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('verification_codes', function ($table) {
        $table->string('uuid')->primary();
        $table->string('subject_uuid')->nullable();
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
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    $companyUuid = '22222222-2222-4222-8222-222222222222';
    $userUuid    = '11111111-1111-4111-8111-111111111111';
    app('db')->table('companies')->insert([
        'uuid' => $companyUuid,
        'name' => 'Test Company',
    ]);
    app('db')->table('users')->insert([
        'uuid'         => $userUuid,
        'company_uuid' => $companyUuid,
        'email'        => 'user@example.com',
        'phone'        => '+15555550100',
        'username'     => 'test-user',
        'name'         => 'Test User',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);

    session()->flush();
    session([
        'company' => $companyUuid,
        'user'    => $userUuid,
    ]);

    return $redis;
}

function two_fa_controller(): TwoFaController
{
    return new TwoFaController();
}

function two_fa_controller_validation_request(array $input): TwoFaValidationRequest
{
    return TwoFaValidationRequest::create('/int/v1/two-fa/validate', 'POST', $input);
}

function two_fa_controller_user(): User
{
    return User::where('uuid', '11111111-1111-4111-8111-111111111111')->firstOrFail();
}

function two_fa_controller_verification_code(User $user, Carbon $expiresAt, string $uuid = '33333333-3333-4333-8333-333333333333'): VerificationCode
{
    app('db')->table('verification_codes')->insert([
        'uuid'         => $uuid,
        'subject_uuid' => $user->uuid,
        'subject_type' => User::class,
        'code'         => '123456',
        'for'          => '2fa',
        'expires_at'   => $expiresAt->toDateTimeString(),
        'meta'         => json_encode([]),
        'status'       => 'active',
    ]);

    $verificationCode               = new VerificationCode();
    $verificationCode->uuid         = $uuid;
    $verificationCode->subject_uuid = $user->uuid;
    $verificationCode->subject_type = User::class;
    $verificationCode->code         = '123456';
    $verificationCode->for          = '2fa';
    $verificationCode->expires_at   = $expiresAt;
    $verificationCode->meta         = [];
    $verificationCode->status       = 'active';
    $verificationCode->exists       = true;

    return $verificationCode;
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    config([
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
    ]);
    EloquentModel::clearBootedModels();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('two fa controller saves disabled system config with enforcement cleared and fetches defaults', function () {
    two_fa_controller_database();
    $controller = two_fa_controller();

    $saved = $controller->saveSystemConfig(Request::create('/int/v1/two-fa/config', 'POST', [
        'twoFaSettings' => [
            'enabled'  => false,
            'method'   => 'sms',
            'enforced' => true,
        ],
    ]));
    $fetched = $controller->getSystemConfig();

    expect($saved->getData(true))->toBe([
        'enabled'  => false,
        'method'   => 'sms',
        'enforced' => false,
    ])
        ->and($fetched->getData(true))->toBe([
            'enabled'  => false,
            'method'   => 'sms',
            'enforced' => false,
        ])
        ->and(Setting::where('key', 'system.2fa')->value('value'))->not->toBeNull();
});

test('two fa controller reports enabled sessions only when user level two factor is enabled', function () {
    two_fa_controller_database();
    $user       = two_fa_controller_user();
    $controller = two_fa_controller();

    $disabled = $controller->checkTwoFactor(Request::create('/int/v1/two-fa/check', 'POST', [
        'identity' => $user->email,
    ]));

    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);
    $enabled = $controller->checkTwoFactor(Request::create('/int/v1/two-fa/check', 'POST', [
        'identity' => $user->email,
    ]));

    expect($disabled->getData(true))->toBe([
        'twoFaSession'   => null,
        'isTwoFaEnabled' => false,
    ])
        ->and($enabled->getData(true)['isTwoFaEnabled'])->toBeTrue()
        ->and($enabled->getData(true)['twoFaSession'])->toBeString()
        ->and($enabled->getData(true)['twoFaSession'])->not->toContain('two_fa_session');
});

test('two fa controller validates sessions returning existing client tokens expired states and errors', function () {
    $redis = two_fa_controller_database();
    $user  = two_fa_controller_user();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);
    $token            = TwoFactorAuth::start($user->email, 10);
    $verificationCode = two_fa_controller_verification_code($user, Carbon::now()->addMinutes(5));
    $clientToken      = TwoFactorAuth::createClientSessionToken($verificationCode);
    $expiredCode      = two_fa_controller_verification_code($user, Carbon::now()->subMinute(), '44444444-4444-4444-8444-444444444444');
    $expiredToken     = TwoFactorAuth::createClientSessionToken($expiredCode);

    $valid = two_fa_controller()->validateSession(two_fa_controller_validation_request([
        'token'       => $token,
        'identity'    => $user->email,
        'clientToken' => $clientToken,
    ]));
    $expired = two_fa_controller()->validateSession(two_fa_controller_validation_request([
        'token'       => $token,
        'identity'    => $user->email,
        'clientToken' => $expiredToken,
    ]));
    $missingIdentity = two_fa_controller()->validateSession(two_fa_controller_validation_request([
        'token'    => $token,
        'identity' => 'missing@example.test',
    ]));

    expect($valid->getData(true))->toBe([
        'clientToken' => $clientToken,
        'expired'     => false,
    ])
        ->and($expired->getData(true))->toBe(['expired' => true])
        ->and($missingIdentity->getStatusCode())->toBe(400)
        ->and($missingIdentity->getData(true))->toBe(['errors' => ['No user found for the identity provided.']])
        ->and($redis->deleted)->not->toBeEmpty();
});

test('two fa controller verify resend and invalidate expose success and failure contracts', function () {
    $redis = two_fa_controller_database();
    $user  = two_fa_controller_user();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);
    $token            = TwoFactorAuth::start($user->email, 10);
    $verificationCode = two_fa_controller_verification_code($user, Carbon::now()->addMinutes(5));
    $clientToken      = TwoFactorAuth::createClientSessionToken($verificationCode);

    $verifySuccess = two_fa_controller()->verifyCode(Request::create('/int/v1/two-fa/verify', 'POST', [
        'code'        => '123456',
        'token'       => $token,
        'clientToken' => $clientToken,
    ]));

    $resendToken   = TwoFactorAuth::start($user->email, 10);
    $resendSuccess = two_fa_controller()->resendCode(Request::create('/int/v1/two-fa/resend', 'POST', [
        'identity' => $user->email,
        'token'    => $resendToken,
    ]));

    $verifyFailure = two_fa_controller()->verifyCode(Request::create('/int/v1/two-fa/verify', 'POST', [
        'code'        => '000000',
        'token'       => $token,
        'clientToken' => base64_encode('invalid|missing-code|token'),
    ]));
    $resendFailure = two_fa_controller()->resendCode(Request::create('/int/v1/two-fa/resend', 'POST', [
        'identity' => 'missing@example.test',
        'token'    => $token,
    ]));
    $invalidated = two_fa_controller()->invalidateSession(Request::create('/int/v1/two-fa/invalidate', 'POST', [
        'identity' => $user->email,
        'token'    => $token,
    ]));
    $invalidateFailure = two_fa_controller()->invalidateSession(Request::create('/int/v1/two-fa/invalidate', 'POST', [
        'identity' => 'missing@example.test',
        'token'    => $token,
    ]));

    expect($verifySuccess->getData(true)['authToken'])->toContain('|')
        ->and(app('db')->table('personal_access_tokens')->where('tokenable_id', $user->uuid)->count())->toBe(1)
        ->and($resendSuccess->getData(true)['clientToken'])->toBeString()
        ->and(app('db')->table('verification_codes')->count())->toBe(2)
        ->and($verifyFailure->getStatusCode())->toBe(400)
        ->and($verifyFailure->getData(true))->toBe(['errors' => ['Verification code is invalid.']])
        ->and($resendFailure->getStatusCode())->toBe(400)
        ->and($resendFailure->getData(true))->toBe(['errors' => ['No user found using the provided identity']])
        ->and($invalidated->getData(true))->toBe(['ok' => true])
        ->and($invalidateFailure->getData(true))->toBe(['ok' => false])
        ->and($redis->deleted)->not->toBeEmpty();
});

test('two fa controller reports enforcement for the resolved request user', function () {
    two_fa_controller_database();
    $user = two_fa_controller_user();
    TwoFactorAuth::configureTwoFaSettings(['enabled' => false, 'method' => 'email', 'enforced' => true]);
    $request = Request::create('/int/v1/two-fa/should-enforce', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = two_fa_controller()->shouldEnforce($request);

    expect($response->getData(true))->toBe(['shouldEnforce' => true]);
});
