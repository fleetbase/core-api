<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Services\SmsService;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

if (!function_exists('cache')) {
    function cache(mixed $key = null, mixed $default = null): mixed
    {
        $cache = app('cache');

        if ($key === null) {
            return $cache;
        }

        return $cache->get($key, $default);
    }
}

class TwoFactorAuthRedisFake
{
    public array $values  = [];
    public array $sets    = [];
    public array $deleted = [];

    public function set(string $key, mixed $value, mixed ...$options): bool
    {
        $this->values[$key] = $value;
        $this->sets[]       = compact('key', 'value', 'options');

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

class TwoFactorAuthCacheFake
{
    public array $values    = [];
    public array $forgotten = [];

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
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;
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

class TwoFactorAuthMailerFake
{
    public array $recipients = [];
    public array $sent       = [];

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

class TwoFactorAuthTwilioFake
{
    public array $messages = [];

    public function message(string $to, string $message, array $mediaUrls = [], array $params = []): void
    {
        $this->messages[] = compact('to', 'message', 'mediaUrls', 'params');
    }
}

class TwoFactorAuthResponseCacheFake
{
    public function clear(): void
    {
    }
}

class TwoFactorAuthMissingSystemSettings extends TwoFactorAuth
{
    public static function getTwoFaConfiguration(): ?Setting
    {
        return null;
    }
}

function two_factor_auth_fixtures(): array
{
    $container = bind_test_container([
        'app.name'                => 'Fleetbase',
        'fleetbase.connection.db' => 'mysql',
    ]);

    $redis = new TwoFactorAuthRedisFake();
    $mail  = new TwoFactorAuthMailerFake();
    $container->instance('redis', $redis);
    $container->instance('cache', new TwoFactorAuthCacheFake());
    $container->instance('mail.manager', $mail);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));
    $container->instance('responsecache', new TwoFactorAuthResponseCacheFake());
    Facade::clearResolvedInstance('redis');
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('mail.manager');
    Facade::clearResolvedInstance('responsecache');

    session()->flush();
    two_factor_auth_database();

    $company = new Company([
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'name' => 'Test Company',
    ]);
    $company->exists = true;
    $company->setAttribute($company->getKeyName(), $company->uuid);

    $user = new User([
        'uuid'         => '11111111-1111-4111-8111-111111111111',
        'company_uuid' => $company->uuid,
        'email'        => 'user@example.com',
        'phone'        => '+15555550100',
        'username'     => 'test-user',
        'name'         => 'Test User',
    ]);
    $user->exists = true;
    $user->setRelation('company', $company);
    $user->setAttribute($user->getKeyName(), $user->uuid);

    app('db')->table('companies')->insert([
        'uuid'       => $company->uuid,
        'name'       => $company->name,
        'options'    => json_encode([]),
        'deleted_at' => null,
    ]);
    app('db')->table('users')->insert([
        'uuid'         => $user->uuid,
        'company_uuid' => $company->uuid,
        'email'        => $user->email,
        'phone'        => $user->phone,
        'username'     => $user->username,
        'name'         => $user->name,
        'deleted_at'   => null,
        'created_at'   => '2026-07-17 10:00:00',
        'updated_at'   => '2026-07-17 10:00:00',
    ]);

    return [$user, $company, $redis];
}

function two_factor_auth_database(): void
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = app();
    config([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connectionConfig,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');
    EloquentModel::clearBootedModels();

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    foreach (['personal_access_tokens', 'verification_codes', 'settings', 'users', 'companies'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->text('options')->nullable();
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
}

function two_factor_auth_verification_code(User $user, Carbon $expiresAt): VerificationCode
{
    app('db')->table('verification_codes')->insert([
        'uuid'         => '33333333-3333-4333-8333-333333333333',
        'subject_uuid' => $user->uuid,
        'subject_type' => User::class,
        'code'         => '123456',
        'for'          => '2fa',
        'expires_at'   => $expiresAt->toDateTimeString(),
        'meta'         => json_encode([]),
        'status'       => 'active',
    ]);

    $verificationCode               = new VerificationCode();
    $verificationCode->uuid         = '33333333-3333-4333-8333-333333333333';
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
});

test('two factor auth creates default system and subject settings when none exist', function () {
    [$user, $company] = two_factor_auth_fixtures();

    $system          = TwoFactorAuth::getTwoFaConfiguration();
    $userSettings    = TwoFactorAuth::getTwoFaSettingsForUser($user);
    $companySettings = TwoFactorAuth::getTwoFaSettingsForCompany($company);

    expect($system)->toBeInstanceOf(Setting::class)
        ->and($system->key)->toBe('system.2fa')
        ->and($system->getBoolean('enabled'))->toBeFalse()
        ->and($system->getValue('method'))->toBe('email')
        ->and($system->getBoolean('enforced'))->toBeFalse()
        ->and($userSettings->key)->toBe('user.' . $user->uuid . '.2fa')
        ->and($userSettings->getBoolean('enabled'))->toBeFalse()
        ->and($companySettings->key)->toBe('company.' . $company->uuid . '.2fa')
        ->and($companySettings->getBoolean('enabled'))->toBeFalse();
});

test('two factor auth evaluates user company and system enforcement settings', function () {
    [$user, $company] = two_factor_auth_fixtures();

    TwoFactorAuth::configureTwoFaSettings(['enabled' => false, 'method' => 'email', 'enforced' => true]);
    TwoFactorAuth::saveTwoFaSettingsForCompany($company, ['enabled' => false, 'method' => 'email', 'enforced' => true]);

    expect(TwoFactorAuth::isSystemEnforced())->toBeTrue()
        ->and(TwoFactorAuth::isCompanyEnforced($company))->toBeTrue()
        ->and(TwoFactorAuth::isEnabled($user))->toBeFalse()
        ->and(TwoFactorAuth::shouldEnforce($user))->toBeTrue();

    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'sms']);

    expect(TwoFactorAuth::isEnabled($user))->toBeTrue()
        ->and(TwoFactorAuth::shouldEnforce($user))->toBeFalse();
});

test('two factor auth treats unavailable system settings as not enforced', function () {
    expect(TwoFactorAuthMissingSystemSettings::isSystemEnforced())->toBeFalse();
});

test('two factor auth starts encrypted redis backed sessions and validates identities', function () {
    [$user, , $redis] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token = TwoFactorAuth::start($user->email, 12);

    expect($token)->toBeString()
        ->and($token)->not->toContain('two_fa_session')
        ->and($redis->sets)->toHaveCount(1)
        ->and($redis->sets[0]['key'])->toStartWith('two_fa_session:' . $user->uuid . ':')
        ->and($redis->sets[0]['value'])->toBe($user->uuid)
        ->and(TwoFactorAuth::validateSessionToken($token, $user->email))->toBeTrue()
        ->and(TwoFactorAuth::validateSessionToken($token, '+19999999999'))->toBeFalse()
        ->and(TwoFactorAuth::createTwoFaSessionIfEnabled('missing@example.com'))->toBeNull();
});

test('two factor auth rejects disabled sessions and validates client verification tokens', function () {
    [$user, , $redis] = two_factor_auth_fixtures();

    expect(TwoFactorAuth::start('missing@example.com'))->toBeNull();

    $token = TwoFactorAuth::start($user->email, 10);
    expect(TwoFactorAuth::validateSessionToken($token, $user->email))->toBeFalse();

    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);
    $enabledToken     = TwoFactorAuth::start($user->email, 10);
    $verificationCode = two_factor_auth_verification_code($user, Carbon::now()->addMinutes(5));
    $clientToken      = TwoFactorAuth::createClientSessionToken($verificationCode);

    expect(TwoFactorAuth::validateSessionToken($enabledToken, $user->email, $clientToken))->toBeTrue()
        ->and(TwoFactorAuth::validateSessionToken($enabledToken, $user->email, base64_encode('invalid|missing-code|token')))->toBeFalse()
        ->and($redis->deleted)->toContain($redis->sets[1]['key']);
});

test('two factor auth clears redis sessions when client verification code is expired or unavailable', function () {
    [$user, , $redis] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token            = TwoFactorAuth::start($user->phone, 10);
    $verificationCode = two_factor_auth_verification_code($user, Carbon::now()->subMinute());
    $clientToken      = TwoFactorAuth::createClientSessionToken($verificationCode);

    expect(TwoFactorAuth::validateSessionToken($token, $user->phone, $clientToken))->toBeFalse()
        ->and($redis->deleted)->toBe([$redis->sets[0]['key']]);
});

test('two factor auth validates delivery method requirements before creating verification codes', function () {
    [$user] = two_factor_auth_fixtures();

    $user->forceFill(['email' => null, 'phone' => null]);

    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);
    expect(fn () => TwoFactorAuth::sendVerificationCode($user))->toThrow(Exception::class, 'No email to send 2FA code to.');

    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'sms']);
    expect(fn () => TwoFactorAuth::sendVerificationCode($user))->toThrow(Exception::class, 'No phone number to send 2FA code to.');

    $user->forceFill(['email' => 'user@example.com']);
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'push']);

    expect(fn () => TwoFactorAuth::sendVerificationCode($user))->toThrow(Exception::class, 'Invalid 2FA method selected in settings.')
        ->and(app('db')->table('verification_codes')->count())->toBe(0);
});

test('two factor auth sends sms verification codes with configured provider routing', function () {
    [$user] = two_factor_auth_fixtures();
    $twilio = new TwoFactorAuthTwilioFake();

    app()->instance('twilio', $twilio);
    Facade::clearResolvedInstance('twilio');
    config([
        'sms.default_provider' => SmsService::PROVIDER_TWILIO,
        'sms.routing_rules'    => [],
    ]);

    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'sms']);

    $verificationCode = TwoFactorAuth::sendVerificationCode($user);

    expect($verificationCode)->toBeInstanceOf(VerificationCode::class)
        ->and($verificationCode->for)->toBe('2fa')
        ->and($twilio->messages)->toHaveCount(1)
        ->and($twilio->messages[0]['to'])->toBe($user->phone)
        ->and($twilio->messages[0]['message'])->toBe($verificationCode->code . ' is your Fleetbase 2FA Code');
});

test('two factor auth issues and reuses client tokens for valid active sessions', function () {
    [$user, , $redis] = two_factor_auth_fixtures();

    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token       = TwoFactorAuth::createTwoFaSessionIfEnabled($user->email);
    $clientToken = TwoFactorAuth::getClientSessionTokenFromTwoFaSession($token, $user->email);

    expect($token)->toBeString()
        ->and($clientToken)->toBeString()
        ->and(app('db')->table('verification_codes')->count())->toBe(1)
        ->and(TwoFactorAuth::getClientSessionTokenFromTwoFaSession($token, $user->email, $clientToken))->toBe($clientToken)
        ->and($redis->deleted)->toBe([]);
});

test('two factor auth refuses client token issuance when user settings disable two factor auth', function () {
    [$user] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => false, 'method' => 'email']);

    expect(fn () => TwoFactorAuth::getClientSessionTokenFromTwoFaSession('unused-token', $user->email))
        ->toThrow(Exception::class, '2FA Authentication is not enabled.')
        ->and(TwoFactorAuth::createTwoFaSessionIfEnabled($user->email))->toBeNull();
});

test('two factor auth rejects expired client tokens and invalid session keys for client token issuance', function () {
    [$user, , $redis] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token              = TwoFactorAuth::start($user->email, 10);
    $expiredCode        = two_factor_auth_verification_code($user, Carbon::now()->subMinute());
    $expiredClientToken = TwoFactorAuth::createClientSessionToken($expiredCode);

    expect(fn () => TwoFactorAuth::getClientSessionTokenFromTwoFaSession($token, $user->email, $expiredClientToken))
        ->toThrow(Exception::class, '2FA Verification code is invalid or has expired.')
        ->and($redis->deleted)->toBe([$redis->sets[0]['key']]);

    $invalidSessionToken = base64_encode('too-short-for-an-initialization-vector');

    expect(fn () => TwoFactorAuth::getClientSessionTokenFromTwoFaSession($invalidSessionToken, $user->email))
        ->toThrow(Exception::class, '2FA Authentication session is invalid');
});

test('two factor auth rejects truncated and non compressed encrypted session payloads', function () {
    $decrypt = new ReflectionMethod(TwoFactorAuth::class, 'decryptSessionKey');
    $decrypt->setAccessible(true);

    $key       = '11111111-1111-4111-8111-111111111111';
    $ivLength  = openssl_cipher_iv_length('aes-256-cbc');
    $iv        = str_repeat('a', $ivLength);
    $encrypted = openssl_encrypt('plain-not-compressed', 'aes-256-cbc', $key, 0, $iv);

    expect($decrypt->invoke(null, base64_encode('short'), $key))->toBeNull();

    set_error_handler(fn () => true);

    try {
        expect($decrypt->invoke(null, base64_encode($iv . $encrypted), $key))->toBeNull();
    } finally {
        restore_error_handler();
    }
});

test('two factor auth validation forgets sessions backed by expired verification codes', function () {
    [$user, , $redis] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token            = TwoFactorAuth::start($user->email, 10);
    $verificationCode = two_factor_auth_verification_code($user, Carbon::now()->subMinutes(5));
    $clientToken      = TwoFactorAuth::createClientSessionToken($verificationCode);

    expect(TwoFactorAuth::validateSessionToken($token, $user->email, $clientToken))->toBeFalse()
        ->and($redis->deleted)->toBe([$redis->sets[0]['key']]);
});

test('two factor auth rejects invalid client tokens and missing identities with stable errors', function () {
    [$user, , $redis] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token = TwoFactorAuth::start($user->email, 10);

    expect(fn () => TwoFactorAuth::getClientSessionTokenFromTwoFaSession($token, 'missing@example.com'))->toThrow(Exception::class, 'No user found for the identity provided.')
        ->and(fn () => TwoFactorAuth::getClientSessionTokenFromTwoFaSession($token, $user->email, base64_encode('expires|missing-code|nonce')))->toThrow(Exception::class, '2FA Verification code is invalid or has expired.')
        ->and(fn () => TwoFactorAuth::forgetTwoFaSession($token, 'missing@example.com'))->toThrow(Exception::class, 'No user found for the identity provided.')
        ->and($redis->deleted)->toContain($redis->sets[0]['key']);
});

test('two factor auth rejects missing session cache entries and non user verification subjects', function () {
    [$user, $company, $redis] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token         = TwoFactorAuth::start($user->email, 10);
    $redis->values = [];

    expect(TwoFactorAuth::validateSessionToken($token, $user->email))->toBeFalse();

    app('db')->table('verification_codes')->insert([
        'uuid'         => '44444444-4444-4444-8444-444444444444',
        'subject_uuid' => $company->uuid,
        'subject_type' => Company::class,
        'code'         => '654321',
        'for'          => '2fa',
        'expires_at'   => Carbon::now()->addMinutes(5)->toDateTimeString(),
        'meta'         => json_encode([]),
        'status'       => 'active',
    ]);

    $companyCode        = VerificationCode::where('uuid', '44444444-4444-4444-8444-444444444444')->firstOrFail();
    $companyClientToken = TwoFactorAuth::createClientSessionToken($companyCode);

    expect(fn () => TwoFactorAuth::verifyCode('654321', $token, $companyClientToken))
        ->toThrow(Exception::class, 'User not found for verification code.');
});

test('two factor auth verifies matching codes creates access tokens and forgets sessions', function () {
    [$user, , $redis] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token            = TwoFactorAuth::start($user->email, 10);
    $verificationCode = two_factor_auth_verification_code($user, Carbon::now()->addMinutes(5));
    $clientToken      = TwoFactorAuth::createClientSessionToken($verificationCode);
    $accessToken      = TwoFactorAuth::verifyCode('123456', $token, $clientToken);

    expect($accessToken)->toContain('|')
        ->and(app('db')->table('personal_access_tokens')->where('tokenable_id', $user->uuid)->count())->toBe(1)
        ->and($redis->deleted)->toBe([$redis->sets[0]['key']]);
});

test('two factor auth verify code rejects invalid and mismatched codes', function () {
    [$user] = two_factor_auth_fixtures();
    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token            = TwoFactorAuth::start($user->email, 10);
    $verificationCode = two_factor_auth_verification_code($user, Carbon::now()->addMinutes(5));
    $clientToken      = TwoFactorAuth::createClientSessionToken($verificationCode);

    expect(fn () => TwoFactorAuth::verifyCode('000000', $token, $clientToken))->toThrow(Exception::class, 'Verification code does not match.')
        ->and(fn () => TwoFactorAuth::verifyCode('123456', 'invalid-token', $clientToken))->toThrow(Exception::class, 'Verification code is invalid.');

    app('db')->table('verification_codes')->where('uuid', $verificationCode->uuid)->delete();

    expect(fn () => TwoFactorAuth::verifyCode('123456', $token, $clientToken))->toThrow(Exception::class, 'Verification code is invalid.');
});

test('two factor auth resends codes only for valid users and sessions', function () {
    [$user] = two_factor_auth_fixtures();

    TwoFactorAuth::saveTwoFaSettingsForUser($user, ['enabled' => true, 'method' => 'email']);

    $token       = TwoFactorAuth::start($user->email, 10);
    $clientToken = TwoFactorAuth::resendCode($user->email, $token);

    expect($clientToken)->toBeString()
        ->and(app('db')->table('verification_codes')->count())->toBe(1)
        ->and(fn () => TwoFactorAuth::resendCode('missing@example.com', $token))->toThrow(Exception::class, 'No user found using the provided identity')
        ->and(fn () => TwoFactorAuth::resendCode($user->email, 'invalid-token'))->toThrow(Exception::class, '2FA session is invalid.');
});
