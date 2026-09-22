<?php

use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\OAuthState;
use Fleetbase\Models\User;
use Fleetbase\Rules\ValidOAuthRegistrationIntent;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Fleetbase\Services\OAuth\OAuthIdentityService;
use Fleetbase\Services\OAuth\OAuthStateService;
use Fleetbase\Support\OAuth;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

// Shared event shim — see the note in OAuthIdentityServiceTest. Must stay identical.
if (!function_exists('oauth_test_record_event')) {
    function oauth_test_record_event(object $event): void
    {
        $GLOBALS['oauth_test_events'][] = $event;
    }

    /**
     * @return array<int, object>
     */
    function oauth_test_events(): array
    {
        return $GLOBALS['oauth_test_events'] ?? [];
    }

    function oauth_test_reset_events(): void
    {
        $GLOBALS['oauth_test_events'] = [];
    }
}

if (!function_exists('Fleetbase\\Services\\OAuth\\event')) {
    eval('namespace Fleetbase\\Services\\OAuth; function event($event = null) { if (is_object($event)) { \\oauth_test_record_event($event); } return $event; }');
}

class RegistrationIntentEncrypterFake implements Encrypter
{
    public function encrypt($value, $serialize = true)
    {
        return 'enc:' . base64_encode($serialize ? serialize($value) : (string) $value);
    }

    public function decrypt($payload, $unserialize = true)
    {
        $value = base64_decode(substr((string) $payload, 4), true);

        return $unserialize ? unserialize((string) $value) : (string) $value;
    }

    public function getKey()
    {
        return 'test-key';
    }
}

class RegistrationIntentCacheFake
{
    private array $values = [];

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->values[$key] ??= $callback();
    }

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        return $this->values[$key] ??= $callback();
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

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        return $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;
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
        return '';
    }
}

class RegistrationIntentResponseCacheFake
{
    public function clear(): void
    {
    }
}

function registration_intent_database(): Capsule
{
    EloquentModel::clearBootedModels();
    oauth_test_reset_events();

    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'activitylog.enabled'        => false,
        'app.key'                    => 'base64:' . base64_encode(str_repeat('a', 32)),
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
        'oauth.enabled'              => true,
        'oauth.allow_registration'   => true,
        'oauth.ttl'                  => ['registration_intent' => 900],
        'oauth.providers'            => [],
    ]);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));
    $container->instance('cache', new RegistrationIntentCacheFake());
    $container->instance('responsecache', new RegistrationIntentResponseCacheFake());
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');
    Facade::clearResolvedInstance('log');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = app('db')->connection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('email')->nullable();
        $table->string('name')->nullable();
        $table->string('google_user_id')->nullable()->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('oauth_identities', function ($table) {
        $table->string('uuid')->primary();
        $table->string('user_uuid');
        $table->string('provider', 40);
        $table->string('provider_user_id', 191);
        $table->string('provider_email')->nullable();
        $table->boolean('email_verified')->default(false);
        $table->text('meta')->nullable();
        $table->timestamp('last_login_at')->nullable();
        $table->timestamps();
        $table->unique(['provider', 'provider_user_id']);
    });
    $schema->create('oauth_states', function ($table) {
        $table->string('uuid')->primary();
        $table->string('purpose', 24);
        $table->string('token_hash', 64)->unique();
        $table->string('provider', 40)->nullable();
        $table->string('intent', 16)->nullable();
        $table->string('user_uuid')->nullable();
        $table->text('payload')->nullable();
        $table->string('ip_hash', 64)->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('consumed_at')->nullable();
        $table->timestamps();
    });

    $encrypter = new RegistrationIntentEncrypterFake();
    app()->instance(OAuthStateService::class, new OAuthStateService($encrypter));
    app()->instance(OAuthIdentityService::class, new OAuthIdentityService());
    app()->instance(OAuthConfigRepository::class, new OAuthConfigRepository($encrypter));

    return $capsule;
}

function registration_intent_user(string $uuid = 'user-1', array $attributes = []): User
{
    app('db')->connection('mysql')->table('users')->insert(array_merge([
        'uuid'       => $uuid,
        'email'      => 'ada@example.com',
        'name'       => 'Ada',
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ], $attributes));

    return User::query()->findOrFail($uuid);
}

function registration_intent_profile(array $overrides = []): OAuthUserProfile
{
    return new OAuthUserProfile(
        $overrides['provider'] ?? 'google',
        $overrides['providerUserId'] ?? 'subject-1',
        $overrides['email'] ?? 'ada@example.com',
        $overrides['emailVerified'] ?? true,
        $overrides['name'] ?? 'Ada Lovelace'
    );
}

it('issues an intent that stores only its hash', function () {
    registration_intent_database();

    $token = OAuth::issueRegistrationIntent(registration_intent_profile());
    $row   = OAuthState::query()->first();

    expect($token)->toStartWith('rti_')
        ->and($row->purpose)->toBe(OAuthState::PURPOSE_REGISTRATION_INTENT)
        ->and($row->provider)->toBe('google')
        ->and($row->intent)->toBe('signup')
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->payload)->not->toContain('subject-1');
});

it('inspects an intent without consuming it', function () {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile());

    // Validation rules call this on every request; burning the intent here would
    // destroy the sign-in whenever any other field failed validation.
    expect(OAuth::inspectRegistrationIntent($token))->toBe([
        'provider'         => 'google',
        'provider_user_id' => 'subject-1',
        'email'            => 'ada@example.com',
        'email_verified'   => true,
        'name'             => 'Ada Lovelace',
    ])
        ->and(OAuth::isValidRegistrationIntent($token))->toBeTrue()
        ->and(OAuth::isValidRegistrationIntent($token))->toBeTrue()
        ->and(OAuthState::query()->first()->consumed_at)->toBeNull();
});

it('reports an absent expired or consumed intent as invalid', function () {
    registration_intent_database();
    Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'UTC'));

    $consumed = OAuth::issueRegistrationIntent(registration_intent_profile());
    OAuth::redeemRegistrationIntent($consumed, registration_intent_user('user-1'));

    $expiring = OAuth::issueRegistrationIntent(registration_intent_profile(['providerUserId' => 'subject-2']));

    expect(OAuth::isValidRegistrationIntent(null))->toBeFalse()
        ->and(OAuth::isValidRegistrationIntent(''))->toBeFalse()
        ->and(OAuth::isValidRegistrationIntent('rti_' . str_repeat('z', 64)))->toBeFalse()
        ->and(OAuth::isValidRegistrationIntent($consumed))->toBeFalse()
        ->and(OAuth::isValidRegistrationIntent($expiring))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-09-18 10:20:00', 'UTC'));
    expect(OAuth::isValidRegistrationIntent($expiring))->toBeFalse();

    Carbon::setTestNow();
});

it('redeems an intent into a linked identity', function () {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile());
    $user  = registration_intent_user();

    $identity = OAuth::redeemRegistrationIntent($token, $user);

    expect($identity)->toBeInstanceOf(OAuthIdentity::class)
        ->and($identity->user_uuid)->toBe('user-1')
        ->and($identity->provider)->toBe('google')
        ->and($identity->provider_user_id)->toBe('subject-1')
        // The legacy per-provider column is kept in sync for anything still reading it.
        ->and(User::query()->find('user-1')->google_user_id)->toBe('subject-1')
        // The redeemed row records who it resolved to, for the audit trail.
        ->and(OAuthState::query()->first()->user_uuid)->toBe('user-1');
});

it('marks the account verified when the provider vouched for that exact address', function () {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile());
    $user  = registration_intent_user();

    OAuth::redeemRegistrationIntent($token, $user);

    // This is what lets an OAuth signup skip the emailed verification code.
    expect(User::query()->find('user-1')->email_verified_at)->not->toBeNull();
});

it('does not mark the account verified when the address does not match or was not vouched for', function (array $profile, array $user) {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile($profile));

    OAuth::redeemRegistrationIntent($token, registration_intent_user('user-1', $user));

    expect(User::query()->find('user-1')->email_verified_at)->toBeNull();
})->with([
    // Typed a different address during signup than the provider returned.
    'address mismatch'  => [['email' => 'ada@example.com'], ['email' => 'different@example.com']],
    // The provider did not assert the address as verified.
    'unverified email'  => [['emailVerified' => false], ['email' => 'ada@example.com']],
    'no provider email' => [['email' => null, 'emailVerified' => false], ['email' => 'ada@example.com']],
]);

it('matches the verified address case insensitively', function () {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile(['email' => 'Ada@Example.com']));

    OAuth::redeemRegistrationIntent($token, registration_intent_user('user-1', ['email' => 'ada@example.com']));

    expect(User::query()->find('user-1')->email_verified_at)->not->toBeNull();
});

it('leaves an already verified account alone', function () {
    registration_intent_database();
    Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'UTC'));

    $token = OAuth::issueRegistrationIntent(registration_intent_profile());
    $user  = registration_intent_user('user-1', ['email_verified_at' => '2024-01-01 00:00:00']);

    OAuth::redeemRegistrationIntent($token, $user);

    expect(User::query()->find('user-1')->email_verified_at->toDateTimeString())->toBe('2024-01-01 00:00:00');

    Carbon::setTestNow();
});

it('fires the linked event once', function () {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile());

    OAuth::redeemRegistrationIntent($token, registration_intent_user());

    $events = oauth_test_events();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(Fleetbase\Events\OAuthIdentityLinked::class)
        // Marked as a signup so the "provider linked" security email is not sent.
        ->and($events[0]->method)->toBe(Fleetbase\Events\OAuthIdentityLinked::METHOD_SIGNUP);
});

it('is null safe so a caller never needs a guard', function () {
    registration_intent_database();
    $user = registration_intent_user();

    expect(OAuth::redeemRegistrationIntent(null, $user))->toBeNull()
        ->and(OAuth::redeemRegistrationIntent('', $user))->toBeNull()
        ->and(OAuth::redeemRegistrationIntent('rti_' . str_repeat('z', 64), $user))->toBeNull()
        ->and(OAuthIdentity::query()->count())->toBe(0);
});

it('cannot be redeemed twice', function () {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile());

    OAuth::redeemRegistrationIntent($token, registration_intent_user('user-1'));
    $second = OAuth::redeemRegistrationIntent($token, registration_intent_user('user-2', ['email' => 'b@example.com']));

    expect($second)->toBeNull()
        ->and(OAuthIdentity::query()->count())->toBe(1)
        ->and(OAuthIdentity::query()->first()->user_uuid)->toBe('user-1');
});

it('does not fail a signup when the identity was claimed in the meantime', function () {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile());

    // Someone else linked this provider subject between the intent being issued and
    // redeemed. The account has already been created by this point, so the signup
    // must stand — it simply has no linked provider yet.
    OAuthIdentity::query()->create([
        'user_uuid'        => 'someone-else',
        'provider'         => 'google',
        'provider_user_id' => 'subject-1',
    ]);

    $result = OAuth::redeemRegistrationIntent($token, registration_intent_user('user-1'));

    expect($result)->toBeNull()
        ->and(OAuthIdentity::query()->where('provider_user_id', 'subject-1')->first()->user_uuid)->toBe('someone-else')
        ->and(array_filter(app('log')->entries, fn ($entry) => $entry[0] === 'warning'))->not->toBeEmpty();
});

it('exposes the installation level switches', function () {
    registration_intent_database();

    expect(OAuth::isEnabled())->toBeTrue()
        ->and(OAuth::allowsRegistration())->toBeTrue();
});

it('validates an intent through the validation rule without consuming it', function () {
    registration_intent_database();
    $token = OAuth::issueRegistrationIntent(registration_intent_profile());
    $rule  = new ValidOAuthRegistrationIntent();

    expect($rule->passes('oauth_intent', $token))->toBeTrue()
        ->and($rule->passes('oauth_intent', $token))->toBeTrue()
        ->and($rule->passes('oauth_intent', 'rti_nope'))->toBeFalse()
        ->and($rule->passes('oauth_intent', null))->toBeFalse()
        ->and($rule->passes('oauth_intent', ['an', 'array']))->toBeFalse()
        ->and($rule->message())->toBeString()
        ->and(OAuthState::query()->first()->consumed_at)->toBeNull();
});
