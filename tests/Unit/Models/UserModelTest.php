<?php

use Fleetbase\Exceptions\InvalidVerificationCodeException;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

function user_model_container(): void
{
    $container = bind_test_container();
    $container->instance('hash', new UserModelHashFake());
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
        'expires_at'   => Carbon::parse('2026-07-20 00:00:00', 'UTC')->toDateTimeString(),
    ]);

    $user = new UserModelSaveSpy();
    $user->setRawAttributes([
        'uuid'         => 'user-2',
        'company_uuid' => '22222222-2222-4222-8222-222222222222',
    ], true);

    $company = $user->getCompany();
    $code    = $user->getVerificationCodeOrFail('123456', ['email_verification']);

    expect($company)->toBeInstanceOf(Company::class)
        ->and($company->uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and($code)->toBeInstanceOf(VerificationCode::class)
        ->and($code->uuid)->toBe('verify-1')
        ->and($user->verify('123456'))->toBe($user)
        ->and($user->email_verified_at)->toBeInstanceOf(Carbon::class);
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
