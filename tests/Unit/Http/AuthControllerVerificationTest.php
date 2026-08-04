<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthControllerVerificationRedisFake
{
    public array $deleted = [];

    public array $ttls = [];

    public array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function del(string $key): int
    {
        $this->deleted[] = $key;
        unset($this->values[$key]);

        return 1;
    }

    public function set(string $key, mixed $value, mixed ...$options): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function setex(string $key, int $ttl, mixed $value): bool
    {
        $this->ttls[$key]   = $ttl;
        $this->values[$key] = $value;

        return true;
    }
}

class AuthControllerVerificationTwilioFake
{
    public array $messages = [];

    public ?Throwable $exception = null;

    public function message(string $to, string $message): void
    {
        if ($this->exception) {
            throw $this->exception;
        }

        $this->messages[] = compact('to', 'message');
    }
}

class AuthControllerVerificationMailerFake
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

class AuthControllerVerificationCacheFake
{
    public array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
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

    public function increment(string $key, mixed $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + (int) $value;

        return $this->values[$key];
    }
}

class AuthControllerVerificationAuthFake
{
    public array $loggedIn = [];

    public function login(User $user): void
    {
        $this->loggedIn[] = $user->uuid;
    }
}

class AuthControllerVerificationResponseCacheFake
{
    public function clear(): void
    {
    }
}

function auth_controller_verification_fixtures(array $config = []): AuthControllerVerificationRedisFake
{
    $container = bind_test_container(array_merge([
        'app.env'                        => 'testing',
        'fleetbase.sms_auth_bypass_code' => null,
    ], $config));

    $redis = new AuthControllerVerificationRedisFake();
    $container->instance('redis', $redis);
    Facade::clearResolvedInstance('redis');
    session()->flush();

    return $redis;
}

function auth_controller_verification_database(): array
{
    EloquentModel::clearBootedModels();
    Carbon::setTestNow(Carbon::parse('2026-07-18 14:00:00', 'UTC'));

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.env'                      => 'testing',
        'database.default'             => 'mysql',
        'database.connections.mysql'   => $connection,
        'database.connections.testing' => $connection,
        'fleetbase.connection.db'      => 'mysql',
        'activitylog.enabled'          => false,
    ]);

    $redis  = new AuthControllerVerificationRedisFake();
    $mailer = new AuthControllerVerificationMailerFake();
    $cache  = new AuthControllerVerificationCacheFake();
    $twilio = new AuthControllerVerificationTwilioFake();
    $container->instance('redis', $redis);
    $container->instance('cache', $cache);
    $container->instance('twilio', $twilio);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));
    $container->instance('responsecache', new AuthControllerVerificationResponseCacheFake());
    $container->instance('mail.manager', $mailer);
    $container->instance('mailer', $mailer);
    Cache::swap($cache);
    Facade::clearResolvedInstance('redis');
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('twilio');
    Facade::clearResolvedInstance('mail.manager');
    Mail::swap($mailer);

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

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable()->index();
        $table->string('phone')->nullable();
        $table->string('password')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('owner_uuid')->nullable();
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
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->index();
        $table->string('user_uuid')->index();
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

    return [$capsule, $redis, $mailer, $twilio];
}

function auth_controller_verification_insert_user(Capsule $capsule, array $attributes = []): void
{
    $capsule->getConnection('mysql')->table('companies')->insert([
        'uuid'       => $attributes['company_uuid'] ?? 'company-1',
        'public_id'  => 'company_public_1',
        'name'       => 'Verification Company',
        'owner_uuid' => $attributes['uuid'] ?? 'verification-user',
        'deleted_at' => null,
        'created_at' => '2026-07-18 14:00:00',
        'updated_at' => '2026-07-18 14:00:00',
    ]);
    $capsule->getConnection('mysql')->table('users')->insert(array_merge([
        'uuid'              => 'verification-user',
        'public_id'         => 'user_verification',
        'company_uuid'      => 'company-1',
        'name'              => 'Verification User',
        'email'             => 'verify@example.test',
        'phone'             => '+15555550123',
        'password'          => null,
        'type'              => 'user',
        'status'            => 'pending',
        'email_verified_at' => null,
        'last_login'        => null,
        'deleted_at'        => null,
        'created_at'        => '2026-07-18 14:00:00',
        'updated_at'        => '2026-07-18 14:00:00',
    ], $attributes));
    $capsule->getConnection('mysql')->table('company_users')->insert([
        'uuid'         => 'verification-company-user',
        'company_uuid' => $attributes['company_uuid'] ?? 'company-1',
        'user_uuid'    => $attributes['uuid'] ?? 'verification-user',
        'status'       => 'pending',
        'deleted_at'   => null,
        'created_at'   => '2026-07-18 14:00:00',
        'updated_at'   => '2026-07-18 14:00:00',
    ]);
}

function auth_controller_verification_request(array $input = []): Request
{
    return Request::create('/int/v1/auth/verification', 'POST', $input);
}

function auth_controller_verification_phone_key(string $phone): string
{
    return Str::slug($phone . '_verify_code', '_');
}

afterEach(function () {
    session()->flush();
    Carbon::setTestNow();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('validate verification session reports false when token is missing or mismatched', function () {
    $redis                          = auth_controller_verification_fixtures();
    $redis->values['session-token'] = json_encode([
        'email'     => 'owner@example.test',
        'user_uuid' => '11111111-1111-4111-8111-111111111111',
    ]);

    $missingToken = (new AuthController())->validateVerificationSession(auth_controller_verification_request([
        'email' => 'owner@example.test',
    ]));
    $mismatchedEmail = (new AuthController())->validateVerificationSession(auth_controller_verification_request([
        'email' => 'other@example.test',
        'token' => 'session-token',
    ]));

    expect($missingToken->getStatusCode())->toBe(200)
        ->and($missingToken->getData(true))->toBe(['valid' => false])
        ->and($mismatchedEmail->getStatusCode())->toBe(200)
        ->and($mismatchedEmail->getData(true))->toBe(['valid' => false]);
});

test('verification email endpoints reject invalid sessions with stable error contracts', function () {
    auth_controller_verification_fixtures();

    $sendResponse = (new AuthController())->sendVerificationEmail(auth_controller_verification_request([
        'email' => 'owner@example.test',
        'token' => 'missing-token',
    ]));
    $verifyResponse = (new AuthController())->verifyEmail(auth_controller_verification_request([
        'email' => 'owner@example.test',
        'token' => 'missing-token',
        'code'  => '123456',
    ]));

    expect($sendResponse->getStatusCode())->toBe(400)
        ->and($sendResponse->getData(true))->toBe([
            'errors' => ['Invalid verification session.'],
        ])
        ->and($verifyResponse->getStatusCode())->toBe(400)
        ->and($verifyResponse->getData(true))->toBe([
            'errors' => ['Invalid verification session.'],
        ]);
});

test('verification sessions reject malformed redis payloads and missing user records', function () {
    [, $redis]                             = auth_controller_verification_database();
    $redis->values['malformed-session']    = '{not-json';
    $redis->values['missing-user-session'] = json_encode([
        'email'     => 'missing@example.test',
        'user_uuid' => 'missing-user',
    ]);

    $malformed = (new AuthController())->validateVerificationSession(auth_controller_verification_request([
        'email' => 'verify@example.test',
        'token' => 'malformed-session',
    ]));
    $missingUser = (new AuthController())->sendVerificationEmail(auth_controller_verification_request([
        'email' => 'missing@example.test',
        'token' => 'missing-user-session',
    ]));

    expect($malformed->getStatusCode())->toBe(200)
        ->and($malformed->getData(true))->toBe(['valid' => false])
        ->and($missingUser->getStatusCode())->toBe(400)
        ->and($missingUser->getData(true))->toBe([
            'errors' => ['Invalid verification session.'],
        ]);
});

test('verification session creation rejects unknown email addresses without writing redis state', function () {
    [, $redis] = auth_controller_verification_database();

    $response = (new AuthController())->createVerificationSession(auth_controller_verification_request([
        'email' => 'missing@example.test',
        'send'  => true,
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['No user found with provided email address.'],
        ])
        ->and($redis->values)->toBe([]);
});

test('verification session creation stores a redis session and optionally sends email verification', function () {
    [$capsule, $redis, $mailer] = auth_controller_verification_database();
    auth_controller_verification_insert_user($capsule);

    $response = (new AuthController())->createVerificationSession(auth_controller_verification_request([
        'email' => 'VERIFY@example.test',
        'send'  => true,
    ]));
    $payload = $response->getData(true);

    $sessionPayload = json_decode($redis->values[$payload['token']], true);
    $code           = VerificationCode::where('subject_uuid', 'verification-user')->where('for', 'email_verification')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['session'])->toBe(base64_encode('verification-user'))
        ->and($payload['token'])->toBeString()->toHaveLength(40)
        ->and($sessionPayload)->toBe([
            'email'     => 'verify@example.test',
            'user_uuid' => 'verification-user',
        ])
        ->and($code)->not->toBeNull()
        ->and($code->status)->toBe('active')
        ->and($code->meta)->toBe(['email' => 'verify@example.test'])
        ->and($mailer->recipients)->toHaveCount(1)
        ->and($mailer->sent)->toHaveCount(1);
});

test('verification email resend accepts valid sessions and persists a new verification code', function () {
    [$capsule, $redis, $mailer] = auth_controller_verification_database();
    auth_controller_verification_insert_user($capsule);
    $redis->values['session-token'] = json_encode([
        'email'     => 'verify@example.test',
        'user_uuid' => 'verification-user',
    ]);

    $valid = (new AuthController())->validateVerificationSession(auth_controller_verification_request([
        'email' => 'verify@example.test',
        'token' => 'session-token',
    ]));
    $resent = (new AuthController())->sendVerificationEmail(auth_controller_verification_request([
        'email' => 'verify@example.test',
        'token' => 'session-token',
    ]));

    $codes = VerificationCode::where('subject_uuid', 'verification-user')->where('for', 'email_verification')->get();

    expect($valid->getData(true))->toBe(['valid' => true])
        ->and($resent->getStatusCode())->toBe(200)
        ->and($resent->getData(true))->toBe(['status' => 'success'])
        ->and($codes)->toHaveCount(1)
        ->and($codes->first()->status)->toBe('active')
        ->and($codes->first()->meta)->toBe(['email' => 'verify@example.test'])
        ->and($mailer->recipients)->toHaveCount(1)
        ->and($mailer->sent)->toHaveCount(1);
});

test('verify email rejects already verified users and invalid active code attempts', function () {
    [$capsule, $redis] = auth_controller_verification_database();
    auth_controller_verification_insert_user($capsule, [
        'email_verified_at' => '2026-07-17 14:00:00',
        'status'            => 'active',
    ]);
    $redis->values['verified-session'] = json_encode([
        'email'     => 'verify@example.test',
        'user_uuid' => 'verification-user',
    ]);

    $alreadyVerified = (new AuthController())->verifyEmail(auth_controller_verification_request([
        'email' => 'verify@example.test',
        'token' => 'verified-session',
        'code'  => '123456',
    ]));

    $capsule = auth_controller_verification_database()[0];
    auth_controller_verification_insert_user($capsule);
    app('redis')->values['pending-session'] = json_encode([
        'email'     => 'verify@example.test',
        'user_uuid' => 'verification-user',
    ]);

    $invalidCode = (new AuthController())->verifyEmail(auth_controller_verification_request([
        'email' => 'verify@example.test',
        'token' => 'pending-session',
        'code'  => '000000',
    ]));

    expect($alreadyVerified->getStatusCode())->toBe(400)
        ->and($alreadyVerified->getData(true))->toBe([
            'errors' => ['User is already verified.'],
        ])
        ->and($invalidCode->getStatusCode())->toBe(400)
        ->and($invalidCode->getData(true))->toBe([
            'errors' => ['Invalid verification code.'],
        ]);
});

test('verify email consumes the session and returns token only when authentication is requested', function (bool $authenticate) {
    [$capsule, $redis] = auth_controller_verification_database();
    auth_controller_verification_insert_user($capsule);
    $redis->values['session-token'] = json_encode([
        'email'     => 'verify@example.test',
        'user_uuid' => 'verification-user',
    ]);
    $verificationCode = VerificationCode::generateEmailVerificationFor(User::find('verification-user'), 'email_verification', [
        'meta' => ['email' => 'verify@example.test'],
    ]);

    $response = (new AuthController())->verifyEmail(auth_controller_verification_request([
        'email'        => 'verify@example.test',
        'token'        => 'session-token',
        'code'         => $verificationCode->code,
        'authenticate' => $authenticate,
    ]));
    $payload = $response->getData(true);
    $user    = User::find('verification-user');

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['verified_at'])->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe('active')
        ->and($redis->deleted)->toBe(['session-token'])
        ->and(VerificationCode::withTrashed()->where('uuid', $verificationCode->uuid)->whereNotNull('deleted_at')->exists())->toBeTrue();

    if ($authenticate) {
        expect($payload['token'])->toContain('|')
            ->and($user->last_login)->not->toBeNull();
    } else {
        expect($payload['token'])->toBeNull()
            ->and($user->last_login)->toBeNull();
    }
})->with([false, true]);

test('verify sms code returns success and deletes the consumed verification key', function () {
    $redis               = auth_controller_verification_fixtures();
    $key                 = auth_controller_verification_phone_key('+15555550123');
    $redis->values[$key] = '765432';

    $response = (new AuthController())->verifySmsCode(auth_controller_verification_request([
        'phone' => '+15555550123',
        'code'  => '765432',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'OK',
            'message' => 'Code verified',
        ])
        ->and($redis->deleted)->toBe([$key])
        ->and($redis->values)->not->toHaveKey($key);
});

test('verify sms code rejects mismatched codes without deleting the stored code', function () {
    $redis               = auth_controller_verification_fixtures();
    $key                 = auth_controller_verification_phone_key('+15555550123');
    $redis->values[$key] = '765432';

    $response = (new AuthController())->verifySmsCode(auth_controller_verification_request([
        'phone' => '+15555550123',
        'code'  => '000000',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['Invalid verification code'],
        ])
        ->and($redis->deleted)->toBe([])
        ->and($redis->values[$key])->toBe('765432');
});

test('send verification sms requires a matching user and honors the driver filter', function () {
    [$capsule] = auth_controller_verification_database();
    auth_controller_verification_insert_user($capsule, [
        'type' => 'user',
    ]);

    $missingUser = (new AuthController())->sendVerificationSms(auth_controller_verification_request([
        'phone'       => '555550123',
        'countryCode' => '1',
    ]));
    $wrongType = (new AuthController())->sendVerificationSms(auth_controller_verification_request([
        'phone'       => '5555550123',
        'countryCode' => '1',
        'driver'      => 'driver',
    ]));

    expect($missingUser->getStatusCode())->toBe(400)
        ->and($missingUser->getData(true))->toBe([
            'errors' => ['No user with this phone # found.'],
        ])
        ->and($wrongType->getStatusCode())->toBe(400)
        ->and($wrongType->getData(true))->toBe([
            'errors' => ['No user with this phone # found.'],
        ]);
});

test('send verification sms stores a ttl backed code and reports twilio failures', function () {
    [$capsule, $redis, , $twilio] = auth_controller_verification_database();
    auth_controller_verification_insert_user($capsule, [
        'type' => 'driver',
    ]);

    $success = (new AuthController())->sendVerificationSms(auth_controller_verification_request([
        'phone'       => '5555550123',
        'countryCode' => '1',
        'driver'      => 'driver',
    ]));
    $key = auth_controller_verification_phone_key('+15555550123');

    $twilio->exception = new RuntimeException('twilio unavailable');
    $failure           = (new AuthController())->sendVerificationSms(auth_controller_verification_request([
        'phone'       => '+15555550123',
        'countryCode' => '1',
    ]));

    expect($success->getStatusCode())->toBe(200)
        ->and($success->getData(true))->toBe(['status' => 'OK'])
        ->and($twilio->messages)->toHaveCount(1)
        ->and($twilio->messages[0]['to'])->toBe('+15555550123')
        ->and($twilio->messages[0]['message'])->toStartWith('Your Fleetbase authentication code is ')
        ->and($redis->ttls[$key])->toBe(600)
        ->and($redis->values[$key])->toBeInt()
        ->and($failure->getStatusCode())->toBe(400)
        ->and($failure->getData(true))->toBe(['error' => 'twilio unavailable']);
});

test('authenticate sms code rejects invalid otp before querying users or deleting redis state', function () {
    $redis = auth_controller_verification_fixtures([
        'fleetbase.sms_auth_bypass_code' => '246810',
    ]);
    $key                 = auth_controller_verification_phone_key('+19765550123');
    $redis->values[$key] = '135790';

    $response = (new AuthController())->authenticateSmsCode(auth_controller_verification_request([
        'phone'       => '9765550123',
        'countryCode' => '1',
        'code'        => '000000',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['Invalid verification code'],
        ])
        ->and($redis->deleted)->toBe([])
        ->and($redis->values[$key])->toBe('135790');
});

test('authenticate sms code logs in matching users and consumes one time codes', function () {
    [$capsule, $redis] = auth_controller_verification_database();
    auth_controller_verification_insert_user($capsule, [
        'status' => 'active',
    ]);
    $auth = new AuthControllerVerificationAuthFake();
    Illuminate\Support\Facades\Auth::swap($auth);

    $key                 = auth_controller_verification_phone_key('+15555550123');
    $redis->values[$key] = '135790';

    $response = (new AuthController())->authenticateSmsCode(auth_controller_verification_request([
        'phone'       => '5555550123',
        'countryCode' => '1',
        'code'        => '135790',
    ]));
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['token'])->toContain('|')
        ->and($payload['user']['uuid'])->toBe('verification-user')
        ->and($auth->loggedIn)->toBe(['verification-user'])
        ->and($redis->deleted)->toBe([$key])
        ->and($redis->values)->not->toHaveKey($key);
});

test('authenticate sms code reports token creation failures after consuming a valid otp', function () {
    [$capsule, $redis] = auth_controller_verification_database();
    auth_controller_verification_insert_user($capsule, [
        'status' => 'active',
    ]);
    Illuminate\Support\Facades\Auth::swap(new AuthControllerVerificationAuthFake());

    $key                 = auth_controller_verification_phone_key('+15555550123');
    $redis->values[$key] = '135790';
    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('personal_access_tokens');

    $response = (new AuthController())->authenticateSmsCode(auth_controller_verification_request([
        'phone'       => '5555550123',
        'countryCode' => '1',
        'code'        => '135790',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['errors'][0])->toContain('personal_access_tokens')
        ->and($redis->deleted)->toBe([$key])
        ->and($redis->values)->not->toHaveKey($key);
});

test('authenticate sms code reports authentication failure when a valid otp no longer matches a user', function () {
    [, $redis]           = auth_controller_verification_database();
    $key                 = auth_controller_verification_phone_key('+19995550123');
    $redis->values[$key] = '135790';

    $response = (new AuthController())->authenticateSmsCode(auth_controller_verification_request([
        'phone'       => '9995550123',
        'countryCode' => '1',
        'code'        => '135790',
    ]));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true))->toBe('Authentication failed')
        ->and($redis->deleted)->toBe([$key]);
});
