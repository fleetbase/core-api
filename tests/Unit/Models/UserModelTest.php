<?php

use Fleetbase\Exceptions\InvalidVerificationCodeException;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\File;
use Fleetbase\Models\Group;
use Fleetbase\Models\Invite;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Support\Utils;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class UserModelSaveSpy extends User
{
    public int $saves = 0;

    public function save(array $options = []): bool
    {
        $this->saves++;
        $this->syncOriginal();

        return true;
    }

    public function loadCompanyUser(): self
    {
        return $this;
    }

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->forceFill($attributes);
        $this->syncOriginal();

        return true;
    }
}

class UserModelSyncTarget extends Model
{
    protected $fillable = ['email', 'phone'];

    public array $quietUpdates = [];

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;
        $this->forceFill($attributes);
        $this->syncOriginal();

        return true;
    }
}

class UserModelCompanyUserSpy extends CompanyUser
{
    public array $assignedRoles = [];

    public function assignSingleRole($role): CompanyUser
    {
        $this->assignedRoles[] = $role;

        return $this;
    }
}

class UserModelAuthorizationPivotFake
{
    public function __construct(private Collection $roles, private Collection $policies, private Collection $permissions)
    {
    }

    public function roles(): object
    {
        return new class($this->roles) {
            public function __construct(private Collection $roles)
            {
            }

            public function first(): ?Role
            {
                return $this->roles->first();
            }

            public function get(): Collection
            {
                return $this->roles;
            }
        };
    }

    public function policies(): object
    {
        return new class($this->policies) {
            public function __construct(private Collection $policies)
            {
            }

            public function get(): Collection
            {
                return $this->policies;
            }
        };
    }

    public function permissions(): object
    {
        return new class($this->permissions) {
            public function __construct(private Collection $permissions)
            {
            }

            public function get(): Collection
            {
                return $this->permissions;
            }
        };
    }
}

class UserModelAuthorizationRelation
{
    public array $calls = [];

    public function hasRole(string $role): string
    {
        $this->calls[] = ['hasRole', $role];

        return 'role:' . $role;
    }

    public function hasPermission(string $permission): string
    {
        $this->calls[] = ['hasPermission', $permission];

        return 'permission:' . $permission;
    }
}

class UserModelAuthorizationProxyUser extends User
{
    public array $loaded                                     = [];
    public int $loadCompanyUserCalls                         = 0;
    public ?UserModelAuthorizationRelation $fallbackRelation = null;

    public function loadMissing($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function loadCompanyUser(): self
    {
        $this->loadCompanyUserCalls++;
        $this->fallbackRelation = new UserModelAuthorizationRelation();
        $this->setRelation('companyUser', $this->fallbackRelation);

        return $this;
    }
}

class UserModelAvatarFile extends File
{
    public function getUrlAttribute()
    {
        return 'https://cdn.example.test/avatar.png';
    }
}

class UserModelHashFake
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

class UserModelCacheFake
{
    public array $values = [];

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

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function forget(string $key): bool
    {
        return $this->delete($key);
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

class UserModelResponseCacheFake
{
    public function clear(): void
    {
    }
}

function user_model_container(): void
{
    $container = bind_test_container();
    $container->instance('hash', new UserModelHashFake());
    $container->instance('cache', new UserModelCacheFake());
    $container->instance('responsecache', new UserModelResponseCacheFake());
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('log');

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    config([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');
}

function user_model_schema(): void
{
    user_model_container();

    $schema = app('db')->connection('mysql')->getSchemaBuilder();

    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('timezone')->nullable();
        $table->string('country')->nullable();
        $table->string('ip_address')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    $schema->create('verification_codes', function ($table) {
        $table->string('uuid')->primary();
        $table->string('subject_uuid');
        $table->string('subject_type')->nullable();
        $table->string('code');
        $table->string('for');
        $table->string('status');
        $table->timestamp('expires_at')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

it('exposes identity type status timezone and company-derived attributes', function () {
    user_model_container();

    $company = new Company();
    $company->setRawAttributes([
        'uuid'                    => 'company-1',
        'owner_uuid'              => 'user-1',
        'name'                    => 'Acme Logistics',
        'onboarding_completed_at' => '2026-07-17 10:00:00',
    ], true);

    $user = new UserModelSaveSpy();
    $user->setRawAttributes([
        'uuid'     => 'user-1',
        'email'    => 'ada@example.com',
        'phone'    => '+15555550100',
        'username' => 'ada',
        'type'     => 'admin',
        'timezone' => null,
        'status'   => null,
    ], true);
    $user->setRelation('company', $company);

    expect($user->getIdentity())->toBe('ada@example.com')
        ->and((new User(['phone' => '+15555550101', 'username' => 'fallback']))->getIdentity())->toBe('+15555550101')
        ->and((new User(['username' => 'fallback']))->getIdentity())->toBe('fallback')
        ->and($user->isAdmin())->toBeTrue()
        ->and($user->isNotAdmin())->toBeFalse()
        ->and($user->isType('admin'))->toBeTrue()
        ->and($user->isType(['user', 'admin']))->toBeTrue()
        ->and($user->isNotType('user'))->toBeTrue()
        ->and($user->isCompanyOwner($company))->toBeTrue()
        ->and($user->company_name)->toBe('Acme Logistics')
        ->and($user->company_onboarding_completed)->toBeTrue()
        ->and($user->getTimezone())->toBe('Asia/Singapore');

    $user->status = null;

    expect($user->status)->toBe('active')
        ->and($user->setType('user'))->toBe($user)
        ->and($user->type)->toBe('user')
        ->and($user->getType())->toBe('user')
        ->and($user->saves)->toBe(1);
});

it('routes notification tokens by channel and exposes broadcast and sms identities', function () {
    user_model_container();

    $user = new User();
    $user->setRawAttributes([
        'uuid'  => 'user-1',
        'phone' => '+15555550100',
    ], true);
    $user->setRelation('devices', new Collection([
        (object) ['platform' => 'android', 'token' => 'android-token-1'],
        (object) ['platform' => 'ios', 'token' => 'ios-token-1'],
        (object) ['platform' => 'android', 'token' => 'android-token-2'],
    ]));

    expect(array_values($user->routeNotificationForFcm()))->toBe(['android-token-1', 'android-token-2'])
        ->and(array_values($user->routeNotificationForApn()))->toBe(['ios-token-1'])
        ->and($user->routeNotificationForTwilio())->toBe('+15555550100')
        ->and($user->receivesBroadcastNotificationsOn())->toBe('user.user-1');
});

it('exposes user relationship contracts and fallback accessors without querying response state', function () {
    user_model_container();

    $user = new User();
    $user->setRawAttributes([
        'uuid'         => 'user-1',
        'company_uuid' => 'company-1',
    ], true);

    $avatar = new UserModelAvatarFile();
    $avatar->setRawAttributes([
        'uuid' => 'file-1',
    ], true);

    $user->setRelation('avatar', $avatar);
    $user->setRelation('driver', (object) ['uuid' => 'driver-1']);

    expect($user->devices()->getRelated())->toBeInstanceOf(UserDevice::class)
        ->and($user->groups()->getRelated())->toBeInstanceOf(Group::class)
        ->and(array_column($user->companyUser()->getQuery()->getQuery()->wheres, 'value'))->toContain('company-1')
        ->and($user->anyCompanyUser()->getRelated())->toBeInstanceOf(CompanyUser::class)
        ->and($user->avatar_url)->toBe('https://cdn.example.test/avatar.png')
        ->and((new User())->avatar_url)->toBe('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png')
        ->and($user->driver_uuid)->toBe('driver-1');
});

it('hashes and verifies passwords while save-backed password helpers preserve fluent behavior', function () {
    user_model_container();

    $user           = new UserModelSaveSpy();
    $user->password = 'old-secret';

    expect($user->password)->not->toBe('old-secret')
        ->and($user->checkPassword('old-secret'))->toBeTrue()
        ->and($user->checkPassword('wrong-secret'))->toBeFalse()
        ->and($user->changePassword('new-secret'))->toBe($user)
        ->and($user->checkPassword('new-secret'))->toBeTrue()
        ->and($user->saves)->toBe(1);
});

it('updates last login and manual verification timestamps without requiring persistence', function () {
    user_model_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:34:56', 'UTC'));

    $user = new UserModelSaveSpy();

    expect($user->updateLastLogin())->toBe($user)
        ->and($user->getRawOriginal('last_login'))->toBe('2026-07-17 12:34:56')
        ->and($user->manualVerify())->toBe($user)
        ->and(Carbon::parse($user->getRawOriginal('email_verified_at'))->toDateTimeString())->toBe('2026-07-17 12:34:56')
        ->and($user->saves)->toBe(2);

    Carbon::setTestNow();
});

it('wraps locale lookup failures with a stable user-facing exception', function () {
    user_model_container();

    $user = new User(['uuid' => 'user-locale-failure']);

    expect(fn () => $user->getLocale())
        ->toThrow(Exception::class, 'Unable to retrieve user locale setting at this time.');
});

it('exposes date verified user type and presence accessors through stable helpers', function () {
    user_model_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00', 'UTC'));

    $cache = new UserModelCacheFake();
    app()->instance('cache', $cache);
    Cache::clearResolvedInstance('cache');

    $emailVerified = new UserModelSaveSpy();
    $emailVerified->setRawAttributes([
        'uuid'              => 'user-email-verified',
        'email_verified_at' => Carbon::parse('2026-07-17 12:00:00', 'UTC'),
    ], true);

    $phoneVerified = new UserModelSaveSpy();
    $phoneVerified->setRawAttributes([
        'uuid'              => 'user-phone-verified',
        'phone_verified_at' => Carbon::parse('2026-07-17 13:00:00', 'UTC'),
    ], true);

    $present = new UserModelSaveSpy();
    $present->setRawAttributes(['uuid' => 'user-present'], true);
    $cache->put($present->getPresenceCacheKey(), Carbon::parse('2026-07-17 14:59:00', 'UTC'));

    expect($emailVerified->getDateVerified()?->toDateTimeString())->toBe('2026-07-17 12:00:00')
        ->and($phoneVerified->getDateVerified()?->toDateTimeString())->toBe('2026-07-17 13:00:00')
        ->and($emailVerified->setUserType('admin'))->toBe($emailVerified)
        ->and($emailVerified->type)->toBe('admin')
        ->and($emailVerified->saves)->toBe(1)
        ->and($present->last_seen_at?->toDateTimeString())->toBe('2026-07-17 14:59:00')
        ->and($present->is_online)->toBeTrue();

    Carbon::setTestNow();
});

it('activates and deactivates the user and loaded company-user session state together', function () {
    user_model_container();

    $companyUser = new class {
        public string $status = 'active';
        public int $saves     = 0;

        public function save(): bool
        {
            $this->saves++;

            return true;
        }
    };

    $user = new UserModelSaveSpy();
    $user->setRelation('companyUser', $companyUser);

    expect($user->deactivate())->toBe($user)
        ->and($user->status)->toBe('inactive')
        ->and($companyUser->status)->toBe('inactive')
        ->and($companyUser->saves)->toBe(1)
        ->and($user->activate())->toBe($user)
        ->and($user->status)->toBe('active')
        ->and($companyUser->status)->toBe('active')
        ->and($companyUser->saves)->toBe(2)
        ->and($user->saves)->toBe(2)
        ->and($user->session_status)->toBe('active')
        ->and($user->findSessionStatus())->toBe('active')
        ->and($user->getAttribute('session_status'))->toBe('active');
});

it('verifies users from verification code instances and rejects unsupported verification types', function () {
    user_model_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00', 'UTC'));

    $user      = new UserModelSaveSpy();
    $emailCode = new VerificationCode();
    $emailCode->setRawAttributes(['for' => 'email_verification'], true);
    $phoneCode = new VerificationCode();
    $phoneCode->setRawAttributes(['for' => 'phone_verification'], true);
    $invalidCode = new VerificationCode();
    $invalidCode->setRawAttributes(['for' => 'device_pairing'], true);

    expect($user->verify($emailCode))->toBe($user)
        ->and(Carbon::parse($user->getRawOriginal('email_verified_at'))->toDateTimeString())->toBe('2026-07-17 15:00:00')
        ->and($user->verify($phoneCode))->toBe($user)
        ->and(Carbon::parse($user->getRawOriginal('phone_verified_at'))->toDateTimeString())->toBe('2026-07-17 15:00:00')
        ->and($user->saves)->toBe(2)
        ->and(fn () => $user->verify($invalidCode))->toThrow(InvalidVerificationCodeException::class, 'Invalid verification type.');

    Carbon::setTestNow();
});

it('reports verification and searchability boundaries for account state checks', function () {
    user_model_container();

    $admin = new User();
    $admin->setRawAttributes(['type' => 'admin'], true);
    $emailVerified = new User();
    $emailVerified->setRawAttributes(['email_verified_at' => Carbon::parse('2026-07-17 10:00:00', 'UTC')], true);
    $phoneVerified = new User();
    $phoneVerified->setRawAttributes(['phone_verified_at' => Carbon::parse('2026-07-17 10:00:00', 'UTC')], true);
    $unverified = new User();
    $unverified->setRawAttributes(['type' => 'user'], true);

    expect($admin->isVerified())->toBeTrue()
        ->and($emailVerified->isVerified())->toBeTrue()
        ->and($phoneVerified->isVerified())->toBeTrue()
        ->and($unverified->isVerified())->toBeFalse()
        ->and($unverified->isNotVerified())->toBeTrue()
        ->and(User::isSearchable())->toBeTrue()
        ->and($unverified->searchable())->toBeTrue();
});

it('syncs fillable identity properties in either direction only when the target is missing', function () {
    user_model_container();

    $user   = new UserModelSaveSpy(['email' => 'ada@example.com']);
    $target = new UserModelSyncTarget();

    expect($user->syncProperty('email', $target))->toBeTrue()
        ->and($target->email)->toBe('ada@example.com')
        ->and($target->quietUpdates)->toBe([['email' => 'ada@example.com']]);

    $emptyUser = new UserModelSaveSpy();
    $source    = new UserModelSyncTarget(['phone' => '+15555550100']);

    expect($emptyUser->syncProperty('phone', $source))->toBeTrue()
        ->and($emptyUser->phone)->toBe('+15555550100')
        ->and($emptyUser->syncProperty('phone', $source))->toBeFalse()
        ->and($emptyUser->syncProperty('password', $source))->toBeFalse();
});

it('assigns companies from uuid or public id and ignores invalid identifiers', function () {
    user_model_schema();

    app('db')->connection('mysql')->table('companies')->insert([
        'uuid'       => '11111111-1111-4111-8111-111111111111',
        'public_id'  => 'company_ABC1234',
        'name'       => 'Acme Logistics',
        'owner_uuid' => 'user-1',
    ]);
    app('db')->connection('mysql')->table('company_users')->insert([
        'uuid'         => 'company-user-1',
        'company_uuid' => '11111111-1111-4111-8111-111111111111',
        'user_uuid'    => 'user-1',
        'status'       => 'active',
    ]);

    $user = new UserModelSaveSpy();
    $user->setRawAttributes([
        'uuid' => 'user-1',
        'type' => 'admin',
    ], true);

    expect($user->assignCompanyFromId('not-a-valid-company-id'))->toBe($user)
        ->and($user->company_uuid)->toBeNull()
        ->and($user->assignCompanyFromId('missing_company_1'))->toBe($user)
        ->and($user->company_uuid)->toBeNull()
        ->and($user->assignCompanyFromId('company_ABC1234'))->toBe($user)
        ->and($user->company_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($user->saves)->toBe(1);
});

it('resolves company user pivots from loaded relations explicit companies and empty session state', function () {
    user_model_schema();

    app('db')->connection('mysql')->table('companies')->insert([
        'uuid'       => '33333333-3333-4333-8333-333333333333',
        'public_id'  => 'company_public_3',
        'name'       => 'Initech',
        'owner_uuid' => 'user-3',
    ]);
    app('db')->connection('mysql')->table('company_users')->insert([
        'uuid'         => 'company-user-3',
        'company_uuid' => '33333333-3333-4333-8333-333333333333',
        'user_uuid'    => 'user-3',
        'status'       => 'active',
    ]);

    $preloadedPivot = new CompanyUser();
    $preloadedPivot->setRawAttributes(['uuid' => 'company-user-preloaded'], true);
    $preloaded = new User();
    $preloaded->setRelation('companyUser', $preloadedPivot);
    $preloaded->setRelation('companyUsers', collect());

    $unscoped = new User();
    $unscoped->setRawAttributes(['uuid' => 'user-without-company'], true);

    $user = new User();
    $user->setRawAttributes([
        'uuid' => 'user-3',
    ], true);
    $company        = Company::where('uuid', '33333333-3333-4333-8333-333333333333')->first();
    $missingCompany = new Company();
    $missingCompany->setRawAttributes(['uuid' => 'missing-company'], true);

    expect($preloaded->getCompanyUser())->toBe($preloadedPivot)
        ->and($unscoped->getCompanyUser())->toBeNull()
        ->and($user->getCompanyUser($company))->toBeInstanceOf(CompanyUser::class)
        ->and($user->companyUser->uuid)->toBe('company-user-3')
        ->and((new User(['uuid' => 'user-3']))->getCompanyUser($missingCompany))->toBeNull();
});

it('falls back to database lookups for company and verification code helpers', function () {
    user_model_schema();

    app('db')->connection('mysql')->table('companies')->insert([
        'uuid'       => '22222222-2222-4222-8222-222222222222',
        'public_id'  => 'company_public_2',
        'name'       => 'Globex',
        'owner_uuid' => 'user-2',
    ]);
    app('db')->connection('mysql')->table('verification_codes')->insert([
        'uuid'         => 'verify-1',
        'subject_uuid' => 'user-2',
        'subject_type' => User::class,
        'code'         => '123456',
        'for'          => 'email_verification',
        'status'       => 'active',
        'expires_at'   => Carbon::now('UTC')->addDay()->toDateTimeString(),
    ]);

    $user = new UserModelSaveSpy();
    $user->setRawAttributes([
        'uuid'         => 'user-2',
        'company_uuid' => '22222222-2222-4222-8222-222222222222',
    ], true);
    $missingCompanyUser = new UserModelSaveSpy();
    $missingCompanyUser->setRawAttributes([
        'uuid'         => 'user-missing-company',
        'company_uuid' => '33333333-3333-4333-8333-333333333333',
    ], true);

    $company = $user->getCompany();
    $code    = $user->getVerificationCodeOrFail('123456', ['email_verification']);

    expect($company)->toBeInstanceOf(Company::class)
        ->and($company->uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and($code)->toBeInstanceOf(VerificationCode::class)
        ->and($code->uuid)->toBe('verify-1')
        ->and($user->verify('123456'))->toBe($user)
        ->and($user->email_verified_at)->toBeInstanceOf(Carbon::class)
        ->and(fn () => $missingCompanyUser->getCompany())->toThrow(TypeError::class);
});

it('returns early from company invitations when required company or recipient context is missing', function () {
    user_model_schema();

    app('db')->connection('mysql')->getSchemaBuilder()->create('invites', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('public_id')->nullable();
        $table->string('uri')->nullable();
        $table->string('code')->nullable();
        $table->string('protocol')->nullable();
        $table->text('recipients')->nullable();
        $table->string('reason')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1'], true);

    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-1'], true);

    $alreadyInvited = new User();
    $alreadyInvited->setRawAttributes([
        'uuid'  => 'user-already-invited',
        'email' => 'already@example.test',
    ], true);
    Invite::create([
        'uuid'            => 'invite-already-sent',
        'company_uuid'    => 'company-1',
        'created_by_uuid' => 'user-already-invited',
        'subject_uuid'    => 'company-1',
        'subject_type'    => Utils::getMutationType($company),
        'protocol'        => 'email',
        'recipients'      => ['already@example.test'],
        'reason'          => 'join_company',
    ]);

    $unscopedUser = new User();
    $unscopedUser->setRawAttributes([
        'uuid'         => 'user-without-company',
        'company_uuid' => null,
        'email'        => 'missing-company@example.test',
    ], true);

    expect($user->sendInviteFromCompany($company))->toBeFalse()
        ->and($alreadyInvited->sendInviteFromCompany($company))->toBeFalse()
        ->and($unscopedUser->sendInviteFromCompany())->toBeFalse();
});

it('returns empty authorization collections when no company-user pivot can be resolved', function () {
    user_model_schema();

    $user = new User();
    $user->setRawAttributes([
        'uuid'         => 'user-without-pivot',
        'company_uuid' => null,
    ], true);

    expect($user->role)->toBeNull()
        ->and($user->roles)->toBeInstanceOf(Collection::class)
        ->and($user->roles)->toBeEmpty()
        ->and($user->policies)->toBeInstanceOf(Collection::class)
        ->and($user->policies)->toBeEmpty()
        ->and($user->permissions)->toBeInstanceOf(Collection::class)
        ->and($user->permissions)->toBeEmpty();
});

it('returns authorization roles policies and permissions from the resolved company-user pivot', function () {
    user_model_container();
    config([
        'auth.defaults.guard' => 'web',
        'auth.guards.web'     => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
    ]);

    $role = new Role();
    $role->setRawAttributes(['name' => 'Dispatcher'], true);

    $policy = new Policy();
    $policy->setRawAttributes(['name' => 'Orders Read'], true);

    $permission = new Permission();
    $permission->setRawAttributes(['name' => 'orders.read'], true);

    $user = new UserModelSaveSpy();
    $user->setRelation('companyUser', new UserModelAuthorizationPivotFake(
        collect([$role]),
        collect([$policy]),
        collect([$permission]),
    ));
    $userWithoutRoles = new UserModelSaveSpy();
    $userWithoutRoles->setRelation('companyUser', new UserModelAuthorizationPivotFake(
        collect(),
        collect(),
        collect(),
    ));

    expect($user->role)->toBe($role)
        ->and($user->roles)->toEqual(collect([$role]))
        ->and($user->policies)->toEqual(collect([$policy]))
        ->and($user->permissions)->toEqual(collect([$permission]))
        ->and($user->getRole())->toBe($role)
        ->and($user->getRoleName())->toBe('Dispatcher')
        ->and($userWithoutRoles->getRoleName())->toBeNull();
});

it('enriches new and existing users from request timezone data without calling missing helpers', function () {
    user_model_container();

    $request = Request::create('/signup', 'POST', [
        'timezone' => 'Asia/Ulaanbaatar',
    ]);

    $newUser = User::newUserWithRequestInfo($request, [
        'email' => 'request@example.com',
    ]);

    $existing = new UserModelSaveSpy();

    expect($newUser)->toBeInstanceOf(User::class)
        ->and($newUser->email)->toBe('request@example.com')
        ->and($newUser->timezone)->toBe('Asia/Ulaanbaatar')
        ->and($existing->setUserInfoFromRequest($request, true))->toBe($existing)
        ->and($existing->timezone)->toBe('Asia/Ulaanbaatar')
        ->and($existing->saves)->toBe(1);
});

it('enriches user request info from public ip lookup metadata when request timezone is absent', function () {
    user_model_container();

    config(['fleetbase.services.ipinfo.api_key' => null]);
    Illuminate\Support\Facades\Http::fake([
        'https://json.geoiplookup.io/8.8.8.8' => Illuminate\Support\Facades\Http::response([
            'ip'             => '8.8.8.8',
            'country_code'   => 'US',
            'country_name'   => 'United States',
            'continent_name' => 'North America',
            'calling_code'   => '1',
            'currency'       => ['code' => 'USD'],
            'languages'      => ['en'],
            'latitude'       => 37.751,
            'longitude'      => -97.822,
            'time_zone'      => ['name' => 'America/Chicago'],
        ]),
    ]);

    $request = Request::create('/signup', 'POST', [], [], [], ['REMOTE_ADDR' => '8.8.8.8']);

    expect(User::applyUserInfoFromRequest($request))->toMatchArray([
        'country'    => 'US',
        'ip_address' => '8.8.8.8',
        'timezone'   => 'America/Chicago',
        'meta'       => [
            'areacode'   => '1',
            'currency'   => 'USD',
            'language'   => 'en',
            'country'    => 'United States',
            'contintent' => 'North America',
            'latitude'   => 37.751,
            'longitude'  => -97.822,
        ],
    ]);
});

it('keeps request derived attributes when the public ip lookup fails', function () {
    user_model_container();

    config(['fleetbase.services.ipinfo.api_key' => null]);
    // Http::fake() accumulates stubs across tests in the same process and the first match wins, so
    // this uses an address no other test stubs rather than trying to override an existing stub.
    Illuminate\Support\Facades\Http::fake([
        'https://json.geoiplookup.io/9.9.9.9' => Illuminate\Support\Facades\Http::response(['message' => 'rate limited'], 429),
    ]);

    $request = Request::create('/signup', 'POST', ['timezone' => 'Asia/Ulaanbaatar'], [], [], ['REMOTE_ADDR' => '9.9.9.9']);

    $attributes = User::applyUserInfoFromRequest($request);

    expect($attributes)->toBe(['timezone' => 'Asia/Ulaanbaatar']);
});

it('assigns a single company role only when the company-user relation exists', function () {
    user_model_container();

    $companyUser = new UserModelCompanyUserSpy();
    $user        = new UserModelSaveSpy();
    $user->setRelation('companyUser', $companyUser);

    expect($user->assignSingleRole('Dispatcher'))->toBe($user)
        ->and($companyUser->assignedRoles)->toBe(['Dispatcher'])
        ->and(fn () => (new UserModelSaveSpy())->assignSingleRole('Dispatcher'))
        ->toThrow(Exception::class, 'Company User relationship not found!');
});

it('proxies authorization helpers through configured and fallback relationships', function () {
    $user       = new UserModelAuthorizationProxyUser();
    $membership = new UserModelAuthorizationRelation();

    $user->setRelation('membership', $membership);
    $user->setAuthorizationRelationship('membership');

    expect($user->hasPermission('orders.read'))->toBe('permission:orders.read')
        ->and($user->loaded)->toBe(['membership'])
        ->and($membership->calls)->toBe([
            ['hasPermission', 'orders.read'],
        ]);

    $fallback = new UserModelAuthorizationProxyUser();

    expect($fallback->hasRole('administrator'))->toBe('role:administrator')
        ->and($fallback->loaded)->toBe(['companyUser'])
        ->and($fallback->loadCompanyUserCalls)->toBe(1)
        ->and($fallback->fallbackRelation?->calls)->toBe([
            ['hasRole', 'administrator'],
        ]);
});
