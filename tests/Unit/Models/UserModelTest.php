<?php

use Fleetbase\Exceptions\InvalidVerificationCodeException;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
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

    $connection = [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ];

    config([
        'database.default' => 'mysql',
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

it('exposes identity type status timezone and company-derived attributes', function () {
    user_model_container();

    $company = new Company();
    $company->setRawAttributes([
        'uuid' => 'company-1',
        'owner_uuid' => 'user-1',
        'name' => 'Acme Logistics',
        'onboarding_completed_at' => '2026-07-17 10:00:00',
    ], true);

    $user = new UserModelSaveSpy();
    $user->setRawAttributes([
        'uuid' => 'user-1',
        'email' => 'ada@example.com',
        'phone' => '+15555550100',
        'username' => 'ada',
        'type' => 'admin',
        'timezone' => null,
        'status' => null,
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
        'uuid' => 'user-1',
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

    $user = new UserModelSaveSpy();
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
        public int $saves = 0;

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

    $user = new UserModelSaveSpy();
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

    $user = new UserModelSaveSpy(['email' => 'ada@example.com']);
    $target = new UserModelSyncTarget();

    expect($user->syncProperty('email', $target))->toBeTrue()
        ->and($target->email)->toBe('ada@example.com')
        ->and($target->quietUpdates)->toBe([['email' => 'ada@example.com']]);

    $emptyUser = new UserModelSaveSpy();
    $source = new UserModelSyncTarget(['phone' => '+15555550100']);

    expect($emptyUser->syncProperty('phone', $source))->toBeTrue()
        ->and($emptyUser->phone)->toBe('+15555550100')
        ->and($emptyUser->syncProperty('phone', $source))->toBeFalse()
        ->and($emptyUser->syncProperty('password', $source))->toBeFalse();
});
