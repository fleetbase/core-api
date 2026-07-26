<?php

use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Http\Controllers\Internal\v1\OnboardController;
use Fleetbase\Http\Requests\OnboardRequest;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str as SupportStr;

if (!function_exists('Fleetbase\\Http\\Controllers\\Internal\\v1\\event')) {
    eval('namespace Fleetbase\\Http\\Controllers\\Internal\\v1; function event($event = null) { return $event; }');
}

class OnboardControllerTaggedCacheFake
{
    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function store(?string $name = null): self
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

    public function forget(string $key): bool
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

class OnboardControllerHashFake
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

function onboard_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();

    if (!SupportStr::hasMacro('humanize')) {
        $strExpansion = new StrExpansion();
        SupportStr::macro('humanize', $strExpansion->humanize());
    }

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.key'                                      => 'base64:' . base64_encode(str_repeat('a', 32)),
        'api.cache.enabled'                            => false,
        'activitylog.enabled'                          => false,
        'auth.defaults.guard'                          => 'sanctum',
        'auth.guards.sanctum.driver'                   => 'sanctum',
        'auth.guards.sanctum.provider'                 => 'users',
        'cache.default'                                => 'array',
        'cache.stores.array.driver'                    => 'array',
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'fleetbase.connection.db'                      => 'mysql',
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.cache.key'                         => 'spatie.permission.cache',
        'permission.cache.store'                       => 'default',
        'permission.column_names.model_morph_key'      => 'model_uuid',
        'permission.column_names.permission_pivot_key' => 'permission_id',
        'permission.column_names.role_pivot_key'       => 'role_id',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.table_names.roles'                 => 'roles',
        'sanctum.expiration'                           => null,
    ]);

    $cache        = new OnboardControllerTaggedCacheFake();
    $cacheManager = new Illuminate\Cache\CacheManager($container);
    $container->instance('cache', $cache);
    $container->instance('cache.store', $cache);
    $container->instance(Illuminate\Cache\CacheManager::class, $cacheManager);
    $container->instance('hash', new OnboardControllerHashFake());
    $container->instance('responsecache', new OnboardControllerResponseCacheFake());
    Cache::swap($cache);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

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
        $table->string('slug')->nullable();
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
        $table->string('password')->nullable();
        $table->string('phone')->nullable();
        $table->string('slug')->nullable();
        $table->string('type')->nullable();
        $table->string('timezone')->nullable();
        $table->text('meta')->nullable();
        $table->string('country')->nullable();
        $table->string('ip_address')->nullable();
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
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name');
        $table->string('service')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id')->nullable();
        $table->string('role_id')->nullable();
    });
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->nullable()->index();
        $table->text('value')->nullable();
        $table->timestamps();
    });

    $capsule->getConnection('mysql')->table('roles')->insert([
        'id'         => 'role-admin',
        'name'       => 'Administrator',
        'guard_name' => 'sanctum',
        'service'    => 'iam',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);

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

function onboard_create_account_request(array $input = []): OnboardRequest
{
    $request = OnboardRequest::create('/int/v1/onboard/create-account', 'POST', array_merge([
        'name'              => 'Grace Hopper',
        'email'             => 'grace@example.test',
        'phone'             => '+15555550124',
        'timezone'          => 'UTC',
        'password'          => 'correct horse battery staple',
        'organization_name' => 'Compiler Logistics',
    ], $input));
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

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

test('onboard controller creates the first account as an administrator and completes setup', function () {
    $capsule = onboard_controller_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 11:00:00', 'UTC'));

    $response = onboard_controller()->createAccount(onboard_create_account_request());
    $payload  = $response->getData(true);
    $user     = User::where('email', 'grace@example.test')->first();
    $company  = Company::where('name', 'Compiler Logistics')->first();
    $pivot    = $capsule->getConnection('mysql')->table('company_users')->where('user_uuid', $user->uuid)->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['status'])->toBe('success')
        ->and(base64_decode($payload['session']))->toBe($user->uuid)
        ->and($payload['token'])->toContain('|')
        ->and($payload['skipVerification'])->toBeTrue()
        ->and($user->type)->toBe('admin')
        ->and($user->status)->toBe('active')
        ->and($user->timezone)->toBe('UTC')
        ->and($user->last_login->toDateTimeString())->toBe('2026-07-18 11:00:00')
        ->and($user->company_uuid)->toBe($company->uuid)
        ->and($company->owner_uuid)->toBe($user->uuid)
        ->and($company->onboarding_completed_by_uuid)->toBe($user->uuid)
        ->and($pivot->company_uuid)->toBe($company->uuid)
        ->and($pivot->status)->toBe('active')
        ->and($capsule->getConnection('mysql')->table('model_has_roles')->where('model_uuid', $pivot->uuid)->where('role_id', 'role-admin')->exists())->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
});

test('onboard controller creates later accounts without returning an admin token', function () {
    $capsule = onboard_controller_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00', 'UTC'));
    onboard_controller_seed_user($capsule);

    $response = onboard_controller()->createAccount(onboard_create_account_request([
        'name'              => 'Katherine Johnson',
        'email'             => 'katherine@example.test',
        'organization_name' => 'Orbital Logistics',
    ]));
    $payload = $response->getData(true);
    $user    = User::where('email', 'katherine@example.test')->first();
    $company = Company::where('name', 'Orbital Logistics')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['status'])->toBe('success')
        ->and(base64_decode($payload['session']))->toBe($user->uuid)
        ->and($payload['token'])->toBeNull()
        ->and($payload['skipVerification'])->toBeFalse()
        ->and($user->type)->toBe('user')
        ->and($user->last_login)->toBeNull()
        ->and($company->owner_uuid)->toBe($user->uuid)
        ->and($company->onboarding_completed_by_uuid)->toBe($user->uuid)
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
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

test('onboard controller resends email and sms verification codes for matching sessions', function () {
    $capsule = onboard_controller_database();
    onboard_controller_seed_user($capsule, [
        'email' => null,
        'phone' => null,
    ]);

    $emailResponse = onboard_controller()->sendVerificationEmail(onboard_request([
        'session' => base64_encode('11111111-1111-4111-8111-111111111111'),
        'email'   => null,
    ]));
    $smsResponse = onboard_controller()->sendVerificationSms(onboard_request([
        'session' => base64_encode('11111111-1111-4111-8111-111111111111'),
        'phone'   => null,
    ]));

    $codes = $capsule->getConnection('mysql')
        ->table('verification_codes')
        ->orderBy('for')
        ->get()
        ->map(fn ($code) => [
            'subject_uuid' => $code->subject_uuid,
            'for'          => $code->for,
            'status'       => $code->status,
        ])
        ->all();

    expect($emailResponse->getStatusCode())->toBe(200)
        ->and($emailResponse->getData(true))->toBe(['status' => 'ok'])
        ->and($smsResponse->getStatusCode())->toBe(200)
        ->and($smsResponse->getData(true))->toBe(['status' => 'ok'])
        ->and($codes)->toBe([
            [
                'subject_uuid' => '11111111-1111-4111-8111-111111111111',
                'for'          => 'email_verification',
                'status'       => 'active',
            ],
            [
                'subject_uuid' => '11111111-1111-4111-8111-111111111111',
                'for'          => 'phone_verification',
                'status'       => 'pending',
            ],
        ]);
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
    $capsule->getConnection('mysql')
        ->table('verification_codes')
        ->where('uuid', 'verification-code-1')
        ->update(['expires_at' => '2099-07-18 12:00:00']);
    $capsule->getConnection('mysql')->table('users')->delete();
    $missingUser = onboard_controller()->verifyEmail(onboard_request([
        'session' => base64_encode('11111111-1111-4111-8111-111111111111'),
        'code'    => '123456',
    ]));

    expect($missingSession->getStatusCode())->toBe(400)
        ->and($missingSession->getData(true))->toBe(['errors' => ['No session to verify email for.']])
        ->and($invalidCode->getStatusCode())->toBe(400)
        ->and($invalidCode->getData(true))->toBe(['errors' => ['Invalid verification code.']])
        ->and($missingUser->getStatusCode())->toBe(400)
        ->and($missingUser->getData(true))->toBe(['errors' => ['No user found using this email.']]);
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
