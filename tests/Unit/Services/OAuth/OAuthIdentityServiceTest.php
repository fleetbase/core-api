<?php

use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Events\OAuthIdentityLinked;
use Fleetbase\Events\OAuthIdentityUnlinked;
use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\User;
use Fleetbase\Services\OAuth\OAuthIdentityService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

// Shared event shim. core-api has no illuminate/foundation, so the global event()
// helper does not exist under test; PHP resolves an unqualified call to the current
// namespace first, so this intercepts the services' calls. It must be identical in
// every test file that needs it — the first file Pest loads wins, and a non-recording
// variant loading first would silently blind another file's event assertions.
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

class OAuthIdentityServiceHashFake
{
    public function make(string $value, array $options = []): string
    {
        return 'hashed:' . $value;
    }

    public function check(string $value, ?string $hashed = null): bool
    {
        return $hashed === 'hashed:' . $value;
    }

    public function info(string $hashed): array
    {
        return ['algo' => 'fake'];
    }
}

class OAuthIdentityServiceCacheFake
{
    private array $values = [];

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

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        return $value;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class OAuthIdentityServiceResponseCacheFake
{
    public function clear(): void
    {
    }
}

function oauth_identity_service_schema(): void
{
    EloquentModel::clearBootedModels();
    oauth_test_reset_events();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('hash', new OAuthIdentityServiceHashFake());
    $container->instance('cache', new OAuthIdentityServiceCacheFake());
    $container->instance('responsecache', new OAuthIdentityServiceResponseCacheFake());
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('cache');
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

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();

    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('name')->nullable();
        $table->string('username')->nullable();
        $table->string('slug')->nullable();
        $table->string('password')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('google_user_id')->nullable()->unique();
        $table->string('apple_user_id')->nullable()->unique();
        $table->string('facebook_user_id')->nullable()->unique();
        $table->timestamps();
        $table->softDeletes();
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
}

/**
 * Insert a user row directly and hydrate it.
 *
 * Deliberately not User::create(): the model's LogsActivity/HasSlug/Searchable boot hooks
 * pull in half the framework on save, and none of that is what these tests are exercising.
 * The service never calls save() on a user — it mirrors the legacy column with a targeted
 * query-builder update — so a hydrated row is a faithful stand-in.
 *
 * @param array<string, mixed> $attributes
 */
function oauth_identity_service_user(string $uuid, array $attributes = []): User
{
    app('db')->connection('mysql')->table('users')->insert(array_merge([
        'uuid'       => $uuid,
        'email'      => $uuid . '@example.com',
        'name'       => 'User ' . $uuid,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    return User::query()->findOrFail($uuid);
}

function oauth_identity_service_profile(array $overrides = []): OAuthUserProfile
{
    return new OAuthUserProfile(
        $overrides['provider'] ?? 'google',
        $overrides['providerUserId'] ?? '1091',
        $overrides['email'] ?? 'ada@example.com',
        $overrides['emailVerified'] ?? true,
        $overrides['name'] ?? 'Ada Lovelace',
        $overrides['avatar'] ?? null,
        $overrides['meta'] ?? ['locale' => 'en'],
    );
}

it('links a provider identity to a user and fires the linked event', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');

    $identity = $service->link($user, oauth_identity_service_profile());

    expect($identity->provider)->toBe('google')
        ->and($identity->provider_user_id)->toBe('1091')
        ->and($identity->user_uuid)->toBe('user-1')
        ->and($identity->provider_email)->toBe('ada@example.com')
        ->and($identity->email_verified)->toBeTrue()
        ->and($identity->meta)->toBe(['locale' => 'en'])
        ->and($identity->last_login_at)->not->toBeNull();

    $events = oauth_test_events();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OAuthIdentityLinked::class)
        ->and($events[0]->user->uuid)->toBe('user-1');
});

it('mirrors the subject id onto the legacy provider column', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');

    $service->link($user, oauth_identity_service_profile());

    expect(User::query()->find('user-1')->google_user_id)->toBe('1091')
        ->and($user->google_user_id)->toBe('1091')
        // The caller's model must not be left dirty by the mirror write.
        ->and($user->isDirty())->toBeFalse();
});

it('skips the legacy mirror when another account already claims the value', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();

    oauth_identity_service_user('user-1', ['google_user_id' => '1091']);
    $second = oauth_identity_service_user('user-2');

    // users.google_user_id is individually unique. A collision there must never be allowed
    // to fail the link — the oauth_identities row is what actually matters.
    $identity = $service->link($second, oauth_identity_service_profile(['providerUserId' => '1091']));

    expect($identity->user_uuid)->toBe('user-2')
        ->and(User::query()->find('user-2')->google_user_id)->toBeNull()
        ->and(OAuthIdentity::query()->count())->toBe(1);
});

it('is idempotent when the same user links the same subject again', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');

    $first  = $service->link($user, oauth_identity_service_profile());
    $second = $service->link($user, oauth_identity_service_profile());

    expect($second->uuid)->toBe($first->uuid)
        ->and(OAuthIdentity::query()->count())->toBe(1)
        // The linked event fires on first link only, not on every sign-in.
        ->and(oauth_test_events())->toHaveCount(1);
});

it('refuses to move a provider identity to a different account', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();

    $owner    = oauth_identity_service_user('user-1');
    $attacker = oauth_identity_service_user('user-2');

    $service->link($owner, oauth_identity_service_profile());

    // Silently re-pointing an identity between accounts is an account-takeover primitive.
    expect(fn () => $service->link($attacker, oauth_identity_service_profile()))
        ->toThrow(OAuthException::class, 'identity_already_linked');

    expect(OAuthIdentity::query()->first()->user_uuid)->toBe('user-1');
});

it('resolves a user from a provider subject', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');
    $service->link($user, oauth_identity_service_profile());

    expect($service->findUserByProfile(oauth_identity_service_profile())->uuid)->toBe('user-1')
        ->and($service->findBySubject('google', '1091'))->toBeInstanceOf(OAuthIdentity::class)
        ->and($service->findBySubject('google', 'nope'))->toBeNull()
        ->and($service->findUserByProfile(oauth_identity_service_profile(['providerUserId' => 'nope'])))->toBeNull();
});

it('does not resolve a soft deleted user', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');
    $service->link($user, oauth_identity_service_profile());

    User::query()->where('uuid', 'user-1')->update(['deleted_at' => now()]);

    // A trashed account resolves to null, so the caller reports the same generic failure it
    // would for an unknown identity. Distinguishing them would be an enumeration oracle.
    expect($service->findUserByProfile(oauth_identity_service_profile()))->toBeNull()
        ->and($service->findBySubject('google', '1091'))->toBeInstanceOf(OAuthIdentity::class);
});

it('unlinks an identity clears the legacy column and fires the unlinked event', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');
    $service->link($user, oauth_identity_service_profile());

    expect($service->unlink($user, 'google'))->toBeTrue()
        ->and(OAuthIdentity::query()->count())->toBe(0)
        ->and(User::query()->find('user-1')->google_user_id)->toBeNull();

    $events = oauth_test_events();
    expect(end($events))->toBeInstanceOf(OAuthIdentityUnlinked::class)
        ->and(end($events)->provider)->toBe('google');
});

it('reports nothing removed when the provider was never linked', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');

    expect($service->unlink($user, 'github'))->toBeFalse()
        ->and(oauth_test_events())->toBeEmpty();
});

it('allows relinking a provider after an unlink', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');

    $service->link($user, oauth_identity_service_profile());
    $service->unlink($user, 'google');
    $relinked = $service->link($user, oauth_identity_service_profile());

    expect($relinked->provider_user_id)->toBe('1091')
        ->and(OAuthIdentity::query()->count())->toBe(1);
});

it('identifies a users last remaining sign in method', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();

    $passwordless = oauth_identity_service_user('user-1');
    $service->link($passwordless, oauth_identity_service_profile());

    expect($service->isLastCredential($passwordless, 'google'))->toBeTrue();

    $service->link($passwordless, oauth_identity_service_profile(['provider' => 'github', 'providerUserId' => '42']));
    expect($service->isLastCredential($passwordless, 'google'))->toBeFalse();

    $withPassword = oauth_identity_service_user('user-2', ['password' => 'hashed:secret']);
    $service->link($withPassword, oauth_identity_service_profile(['providerUserId' => '2002']));
    expect($service->isLastCredential($withPassword, 'google'))->toBeFalse();
});

it('lists a users identities ordered by provider', function () {
    oauth_identity_service_schema();
    $service = new OAuthIdentityService();
    $user    = oauth_identity_service_user('user-1');
    $other   = oauth_identity_service_user('user-2');

    $service->link($user, oauth_identity_service_profile(['provider' => 'microsoft', 'providerUserId' => 'm1']));
    $service->link($user, oauth_identity_service_profile(['provider' => 'github', 'providerUserId' => 'g1']));
    $service->link($other, oauth_identity_service_profile(['provider' => 'apple', 'providerUserId' => 'a1']));

    expect($service->forUser($user)->pluck('provider')->all())->toBe(['github', 'microsoft']);
});

it('refreshes provider detail on sign in without changing the fleetbase user', function () {
    oauth_identity_service_schema();
    $service  = new OAuthIdentityService();
    $user     = oauth_identity_service_user('user-1');
    $identity = $service->link($user, oauth_identity_service_profile());

    // A provider-side email change must not lock anyone out: authentication keys on the
    // subject id, and the address is refreshed as informational detail only.
    $service->touchLogin($identity, oauth_identity_service_profile([
        'email'         => 'ada.new@example.com',
        'emailVerified' => false,
        'meta'          => ['locale' => 'fr'],
    ]));

    $fresh = OAuthIdentity::query()->first();

    expect($fresh->provider_email)->toBe('ada.new@example.com')
        ->and($fresh->email_verified)->toBeFalse()
        ->and($fresh->meta)->toBe(['locale' => 'fr'])
        ->and($fresh->user_uuid)->toBe('user-1')
        ->and($fresh->last_login_at)->not->toBeNull();
});

it('touches the login timestamp without a profile', function () {
    oauth_identity_service_schema();
    $service  = new OAuthIdentityService();
    $user     = oauth_identity_service_user('user-1');
    $identity = $service->link($user, oauth_identity_service_profile());

    $service->touchLogin($identity);

    expect(OAuthIdentity::query()->first()->provider_email)->toBe('ada@example.com');
});
