<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Http\Requests\Internal\ResetPasswordRequest;
use Fleetbase\Http\Requests\Internal\UserForgotPasswordRequest;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class AuthControllerVerificationCodeCacheFake
{
    public array $values   = [];
    public array $addCalls = [];

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

    public function add(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->addCalls[] = [$key, $value, $ttl];

        if (array_key_exists($key, $this->values)) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function forget(string $key): bool
    {
        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }
}

class AuthControllerVerificationCodeHashFake
{
    public function make(mixed $value, array $options = []): string
    {
        return password_hash((string) $value, PASSWORD_BCRYPT);
    }

    public function check(mixed $value, string $hashedValue, array $options = []): bool
    {
        return password_verify((string) $value, $hashedValue);
    }
}

class AuthControllerVerificationCodeResponseCacheFake
{
    public function clear(array $tags = []): bool
    {
        return true;
    }
}

class AuthControllerVerificationCodeNotificationDispatcherFake
{
    public array $sent = [];

    public function send($notifiables, $notification): void
    {
        foreach (is_iterable($notifiables) ? $notifiables : [$notifiables] as $notifiable) {
            $this->sent[] = [$notifiable, $notification];
        }
    }

    public function sendNow($notifiables, $notification, ?array $channels = null): void
    {
        $this->send($notifiables, $notification);
    }
}

function auth_controller_verification_code_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'             => 'mysql',
        'database.connections.mysql'   => $connection,
        'database.connections.testing' => $connection,
        'fleetbase.connection.db'      => 'mysql',
        'activitylog.enabled'          => false,
    ]);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));

    $cache = new AuthControllerVerificationCodeCacheFake();
    $container->instance('cache', $cache);
    $container->instance('hash', new AuthControllerVerificationCodeHashFake());
    $container->instance('responsecache', new AuthControllerVerificationCodeResponseCacheFake());
    $container->instance(Illuminate\Contracts\Notifications\Dispatcher::class, new AuthControllerVerificationCodeNotificationDispatcherFake());
    Cache::swap($cache);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->addConnection($connection, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('responsecache');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable()->index();
        $table->string('password')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('email_verified_at')->nullable();
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

    return $capsule;
}

function auth_controller_verification_code_insert_user(Capsule $capsule, array $attributes): void
{
    $capsule->getConnection('mysql')->table('users')->insert(array_merge([
        'uuid'              => 'user-reset',
        'public_id'         => 'user_reset',
        'company_uuid'      => 'company-1',
        'name'              => 'Reset User',
        'email'             => 'reset@example.test',
        'password'          => password_hash('old-password', PASSWORD_BCRYPT),
        'type'              => 'admin',
        'status'            => 'active',
        'email_verified_at' => null,
        'deleted_at'        => null,
        'created_at'        => '2026-07-18 11:00:00',
        'updated_at'        => '2026-07-18 11:00:00',
    ], $attributes));
}

function auth_controller_verification_code_insert(Capsule $capsule, array $attributes): void
{
    $capsule->getConnection('mysql')->table('verification_codes')->insert(array_merge([
        'uuid'         => 'verification-code-1',
        'subject_uuid' => null,
        'subject_type' => null,
        'code'         => '123456',
        'for'          => 'password_reset',
        'expires_at'   => Carbon::now()->addDay()->toDateTimeString(),
        'meta'         => json_encode([]),
        'status'       => 'active',
        'deleted_at'   => null,
        'created_at'   => '2026-07-18 11:00:00',
        'updated_at'   => '2026-07-18 11:00:00',
    ], $attributes));
}

function auth_controller_verification_code_request(array $input = []): Request
{
    return Request::create('/int/v1/auth/verification-code', 'POST', $input);
}

function auth_controller_reset_password_request(array $input = []): ResetPasswordRequest
{
    return ResetPasswordRequest::create('/int/v1/auth/reset-password', 'POST', $input);
}

function auth_controller_forgot_password_request(array $input = [], string $ip = '203.0.113.5'): UserForgotPasswordRequest
{
    return UserForgotPasswordRequest::create('/int/v1/auth/forgot-password', 'POST', $input, [], [], [
        'REMOTE_ADDR' => $ip,
    ]);
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('validate verification code returns false for unknown ids and echoes the requested id', function () {
    auth_controller_verification_code_database();

    $response = (new AuthController())->validateVerificationCode(auth_controller_verification_code_request([
        'id'  => 'missing-code',
        'for' => 'password_reset',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'is_valid' => false,
            'id'       => 'missing-code',
        ]);
});

test('validate verification code requires active status when a purpose is supplied', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert($capsule, [
        'uuid'   => 'inactive-code',
        'for'    => 'password_reset',
        'status' => 'used',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'   => 'active-code',
        'for'    => 'password_reset',
        'status' => 'active',
    ]);

    $inactive = (new AuthController())->validateVerificationCode(auth_controller_verification_code_request([
        'id'  => 'inactive-code',
        'for' => 'password_reset',
    ]));
    $active = (new AuthController())->validateVerificationCode(auth_controller_verification_code_request([
        'id'  => 'active-code',
        'for' => 'password_reset',
    ]));

    expect($inactive->getData(true))->toBe([
        'is_valid' => false,
        'id'       => 'inactive-code',
    ])
        ->and($active->getData(true))->toBe([
            'is_valid' => true,
            'id'       => 'active-code',
        ]);
});

test('validate verification code only checks existence when no purpose is supplied', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert($capsule, [
        'uuid'   => 'used-code',
        'for'    => 'password_reset',
        'status' => 'used',
    ]);

    $response = (new AuthController())->validateVerificationCode(auth_controller_verification_code_request([
        'id' => 'used-code',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'is_valid' => true,
            'id'       => 'used-code',
        ]);
});

test('create password reset is enumeration safe and dedupes email and ip attempts', function () {
    $capsule = auth_controller_verification_code_database();

    $missing = (new AuthController())->createPasswordReset(auth_controller_forgot_password_request([
        'email' => 'missing@example.test',
    ], '203.0.113.6'));

    expect($missing->getStatusCode())->toBe(200)
        ->and($missing->getData(true))->toBe(['status' => 'ok'])
        ->and(VerificationCode::count())->toBe(0);

    auth_controller_verification_code_insert_user($capsule, [
        'uuid'  => 'user-reset',
        'email' => 'reset@example.test',
    ]);

    $cache                                                               = app('cache');
    $cache->values['password-reset:email:' . sha1('reset@example.test')] = true;
    $throttled                                                           = (new AuthController())->createPasswordReset(auth_controller_forgot_password_request([
        'email' => 'reset@example.test',
    ]));

    expect($throttled->getStatusCode())->toBe(200)
        ->and($throttled->getData(true))->toBe(['status' => 'ok'])
        ->and(VerificationCode::count())->toBe(0);

    $cache->values = [];

    $first = (new AuthController())->createPasswordReset(auth_controller_forgot_password_request([
        'email' => ' RESET@example.test ',
    ]));

    $codes        = VerificationCode::withTrashed()->where('subject_uuid', 'user-reset')->get();
    $notification = app(Illuminate\Contracts\Notifications\Dispatcher::class);

    expect($first->getStatusCode())->toBe(200)
        ->and($first->getData(true))->toBe(['status' => 'ok'])
        ->and($codes)->toHaveCount(1)
        ->and($codes->first()->for)->toBe('password_reset')
        ->and($codes->first()->status)->toBe('active')
        ->and($codes->first()->meta)->toBe(['email' => 'reset@example.test'])
        ->and($notification->sent)->toHaveCount(1)
        ->and($notification->sent[0][0]->uuid)->toBe('user-reset')
        ->and($notification->sent[0][1])->toBeInstanceOf(Fleetbase\Notifications\UserForgotPassword::class);
});

test('create password reset deletes stale active codes before issuing a fresh reset code', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert_user($capsule, [
        'uuid'  => 'user-reset',
        'email' => 'reset@example.test',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'         => 'old-reset-code',
        'subject_uuid' => 'user-reset',
        'subject_type' => User::class,
        'for'          => 'password_reset',
        'status'       => 'active',
    ]);

    $response = (new AuthController())->createPasswordReset(auth_controller_forgot_password_request([
        'email' => 'reset@example.test',
    ]));

    $oldCode = VerificationCode::withTrashed()->where('uuid', 'old-reset-code')->first();
    $newCode = VerificationCode::where('subject_uuid', 'user-reset')->where('uuid', '!=', 'old-reset-code')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'ok'])
        ->and($oldCode->deleted_at)->not->toBeNull()
        ->and($newCode)->not->toBeNull()
        ->and($newCode->for)->toBe('password_reset')
        ->and($newCode->meta)->toBe(['email' => 'reset@example.test']);
});

test('reset password rejects missing or mismatched verification code records', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert($capsule, [
        'uuid'   => 'reset-code',
        'code'   => '123456',
        'for'    => 'password_reset',
        'status' => 'active',
    ]);

    $missing = (new AuthController())->resetPassword(auth_controller_reset_password_request([
        'link'     => 'missing-code',
        'code'     => '123456',
        'password' => 'new-password',
    ]));
    $wrongCode = (new AuthController())->resetPassword(auth_controller_reset_password_request([
        'link'     => 'reset-code',
        'code'     => '000000',
        'password' => 'new-password',
    ]));

    expect($missing->getStatusCode())->toBe(400)
        ->and($missing->getData(true))->toBe([
            'errors' => ['Invalid password reset request!'],
        ])
        ->and($wrongCode->getStatusCode())->toBe(400)
        ->and($wrongCode->getData(true))->toBe([
            'errors' => ['Invalid password reset request!'],
        ])
        ->and(VerificationCode::query()->where('uuid', 'reset-code')->exists())->toBeTrue();
});

test('reset password changes the subject password and consumes the verification code', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert_user($capsule, [
        'uuid'     => 'user-reset',
        'email'    => 'reset@example.test',
        'password' => password_hash('old-password', PASSWORD_BCRYPT),
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'         => 'reset-code',
        'subject_uuid' => 'user-reset',
        'subject_type' => User::class,
        'code'         => '123456',
        'for'          => 'password_reset',
        'status'       => 'active',
    ]);

    $response = (new AuthController())->resetPassword(auth_controller_reset_password_request([
        'link'     => 'reset-code',
        'code'     => '123456',
        'password' => 'new-password',
    ]));

    $user = User::find('user-reset');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'ok'])
        ->and(password_verify('new-password', $user->password))->toBeTrue()
        ->and(password_verify('old-password', $user->password))->toBeFalse()
        ->and(VerificationCode::withTrashed()->where('uuid', 'reset-code')->first()->deleted_at)->not->toBeNull();
});

test('confirm email change rejects invalid links before mutating users', function () {
    auth_controller_verification_code_database();

    $response = (new AuthController())->confirmEmailChange(auth_controller_verification_code_request([
        'link' => 'missing-email-change',
        'code' => '123456',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['Invalid email change request!'],
        ]);
});

test('confirm email change rejects missing metadata and stale current email contracts', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert_user($capsule, [
        'uuid'  => 'user-email-change',
        'email' => 'current@example.test',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'         => 'missing-meta-code',
        'subject_uuid' => 'user-email-change',
        'subject_type' => User::class,
        'code'         => '111111',
        'for'          => 'email_change',
        'meta'         => json_encode(['old_email' => 'current@example.test']),
        'status'       => 'active',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'         => 'stale-email-code',
        'subject_uuid' => 'user-email-change',
        'subject_type' => User::class,
        'code'         => '222222',
        'for'          => 'email_change',
        'meta'         => json_encode([
            'old_email' => 'previous@example.test',
            'new_email' => 'new@example.test',
        ]),
        'status' => 'active',
    ]);

    $missingMeta = (new AuthController())->confirmEmailChange(auth_controller_verification_code_request([
        'link' => 'missing-meta-code',
        'code' => '111111',
    ]));
    $staleCurrentEmail = (new AuthController())->confirmEmailChange(auth_controller_verification_code_request([
        'link' => 'stale-email-code',
        'code' => '222222',
    ]));

    expect($missingMeta->getStatusCode())->toBe(400)
        ->and($missingMeta->getData(true))->toBe([
            'errors' => ['Invalid email change request!'],
        ])
        ->and($staleCurrentEmail->getStatusCode())->toBe(400)
        ->and($staleCurrentEmail->getData(true))->toBe([
            'errors' => ['This email change request is no longer valid.'],
        ])
        ->and(User::find('user-email-change')->email)->toBe('current@example.test')
        ->and(VerificationCode::whereIn('uuid', ['missing-meta-code', 'stale-email-code'])->count())->toBe(2);
});

test('confirm email change updates verified email and clears active verification codes', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert_user($capsule, [
        'uuid'  => 'user-email-change',
        'email' => 'old@example.test',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'         => 'email-change-code',
        'subject_uuid' => 'user-email-change',
        'subject_type' => User::class,
        'code'         => '654321',
        'for'          => 'email_change',
        'meta'         => json_encode([
            'old_email' => 'old@example.test',
            'new_email' => 'new@example.test',
        ]),
        'status' => 'active',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'         => 'password-reset-code',
        'subject_uuid' => 'user-email-change',
        'subject_type' => User::class,
        'for'          => 'password_reset',
        'status'       => 'active',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'         => 'email-verification-code',
        'subject_uuid' => 'user-email-change',
        'subject_type' => User::class,
        'for'          => 'email_verification',
        'status'       => 'active',
    ]);

    $response = (new AuthController())->confirmEmailChange(auth_controller_verification_code_request([
        'link' => 'email-change-code',
        'code' => '654321',
    ]));

    $user = User::find('user-email-change');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('ok')
        ->and($response->getData(true)['verified_at'])->not->toBeNull()
        ->and($user->email)->toBe('new@example.test')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(VerificationCode::withTrashed()->where('subject_uuid', 'user-email-change')->whereIn('for', ['password_reset', 'email_verification', 'email_change'])->whereNull('deleted_at')->exists())->toBeFalse();
});

test('confirm email change rejects duplicate target emails and preserves the pending code', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert_user($capsule, [
        'uuid'  => 'user-email-change',
        'email' => 'old@example.test',
    ]);
    auth_controller_verification_code_insert_user($capsule, [
        'uuid'  => 'existing-user',
        'email' => 'new@example.test',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'         => 'email-change-code',
        'subject_uuid' => 'user-email-change',
        'subject_type' => User::class,
        'code'         => '654321',
        'for'          => 'email_change',
        'meta'         => json_encode([
            'old_email' => 'old@example.test',
            'new_email' => 'new@example.test',
        ]),
        'status' => 'active',
    ]);

    $response = (new AuthController())->confirmEmailChange(auth_controller_verification_code_request([
        'link' => 'email-change-code',
        'code' => '654321',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['An account with this email address already exists.'],
        ])
        ->and(User::find('user-email-change')->email)->toBe('old@example.test')
        ->and(VerificationCode::where('uuid', 'email-change-code')->exists())->toBeTrue();
});
