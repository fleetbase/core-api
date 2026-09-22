<?php

use Fleetbase\Auth\OAuth\Exceptions\OAuthStateException;
use Fleetbase\Models\OAuthState;
use Fleetbase\Services\OAuth\OAuthStateService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

/**
 * A stand-in for Laravel's Encrypter.
 *
 * core-api requires illuminate/contracts but not illuminate/encryption — the concrete
 * encrypter is supplied by laravel/framework in the host application. This fake implements
 * the contract the service actually depends on, and is keyed so that decrypting with a
 * different instance fails the way a rotated APP_KEY does in production.
 */
class OAuthStateServiceEncrypterFake implements Encrypter
{
    public function __construct(private string $key)
    {
    }

    public function encrypt($value, $serialize = true)
    {
        return base64_encode(hash('sha256', $this->key) . '|' . ($serialize ? serialize($value) : (string) $value));
    }

    public function decrypt($payload, $unserialize = true)
    {
        $decoded = base64_decode((string) $payload, true);

        if ($decoded === false || !str_contains($decoded, '|')) {
            throw new DecryptException('The payload is invalid.');
        }

        [$keyHash, $value] = explode('|', $decoded, 2);

        if (!hash_equals(hash('sha256', $this->key), $keyHash)) {
            throw new DecryptException('The MAC is invalid.');
        }

        return $unserialize ? unserialize($value) : $value;
    }

    public function getKey()
    {
        return $this->key;
    }
}

function oauth_state_service_database(array $config = []): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container(array_merge([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
        'app.key'                    => 'base64:' . base64_encode(str_repeat('k', 32)),
        'oauth.strict_ip_binding'    => false,
    ], $config));

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');
    // bind_test_container() rebinds a fresh 'log' collector on every call, but the Facade
    // caches the first instance it resolved — without this, Log::error() from the service
    // lands in a previous test's collector and assertions here see an empty array.
    Facade::clearResolvedInstance('log');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
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

    return $capsule;
}

function oauth_state_service(): OAuthStateService
{
    return new OAuthStateService(new OAuthStateServiceEncrypterFake(str_repeat('k', 32)));
}

it('persists only the sha256 of an issued token', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_HANDOFF, ['profile' => ['provider' => 'google']], 120);

    $row = OAuthState::query()->first();

    expect($token)->toBeString()->toHaveLength(64)
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->token_hash)->not->toBe($token);

    // The raw token must not be recoverable from any column of the row.
    foreach ($row->getAttributes() as $column => $value) {
        expect((string) $value)->not->toContain($token);
    }
});

it('prefixes registration intents so they are identifiable in logs', function () {
    oauth_state_service_database();

    $token = oauth_state_service()->issue(OAuthState::PURPOSE_REGISTRATION_INTENT, [], 900);

    expect($token)->toStartWith('rti_')
        ->and(OAuthState::query()->first()->token_hash)->toBe(hash('sha256', $token));
});

it('round trips an encrypted payload and stores it as ciphertext', function () {
    oauth_state_service_database();
    $service = oauth_state_service();
    $payload = ['code_verifier' => 'v3rifi3r', 'return_to' => '/dashboard'];

    $token  = $service->issue(OAuthState::PURPOSE_AUTHORIZATION, $payload, 600);
    $stored = OAuthState::query()->first()->payload;

    expect($stored)->toBeString()
        ->and($stored)->not->toContain('v3rifi3r')
        ->and($service->consume(OAuthState::PURPOSE_AUTHORIZATION, $token)['payload'])->toBe($payload);
});

it('consumes a token exactly once', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_HANDOFF, ['a' => 1], 120);

    $first = $service->consume(OAuthState::PURPOSE_HANDOFF, $token);
    expect($first['payload'])->toBe(['a' => 1])
        ->and($first['state']->consumed_at)->not->toBeNull();

    expect(fn () => $service->consume(OAuthState::PURPOSE_HANDOFF, $token))
        ->toThrow(OAuthStateException::class, 'invalid_or_expired');
});

it('refuses a token presented for a different purpose', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_HANDOFF, [], 120);

    expect(fn () => $service->consume(OAuthState::PURPOSE_REGISTRATION_INTENT, $token))
        ->toThrow(OAuthStateException::class, 'invalid_or_expired');

    // …and remains unconsumed, so the legitimate purpose still works.
    expect($service->consume(OAuthState::PURPOSE_HANDOFF, $token)['payload'])->toBe([]);
});

it('refuses an expired token', function () {
    oauth_state_service_database();
    Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'UTC'));
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_HANDOFF, [], 120);

    Carbon::setTestNow(Carbon::parse('2026-09-18 10:02:01', 'UTC'));

    expect(fn () => $service->consume(OAuthState::PURPOSE_HANDOFF, $token))
        ->toThrow(OAuthStateException::class, 'invalid_or_expired');

    Carbon::setTestNow();
});

it('refuses an unknown or empty token without disclosing which', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    expect(fn () => $service->consume(OAuthState::PURPOSE_HANDOFF, ''))
        ->toThrow(OAuthStateException::class, 'invalid_or_expired')
        ->and(fn () => $service->consume(OAuthState::PURPOSE_HANDOFF, str_repeat('z', 64)))
        ->toThrow(OAuthStateException::class, 'invalid_or_expired');
});

it('rejects an unknown purpose outright', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    expect(fn () => $service->issue('not_a_purpose', [], 60))
        ->toThrow(OAuthStateException::class, 'unknown_purpose')
        ->and(fn () => $service->consume('not_a_purpose', str_repeat('z', 64)))
        ->toThrow(OAuthStateException::class, 'unknown_purpose');
});

it('inspects a token without consuming it', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_REGISTRATION_INTENT, ['email' => 'ada@example.com'], 900);

    // Validation rules call inspect() on every request; burning the intent there would
    // destroy the sign-in session whenever any other field failed validation.
    expect($service->inspect(OAuthState::PURPOSE_REGISTRATION_INTENT, $token))->toBe(['email' => 'ada@example.com'])
        ->and($service->inspect(OAuthState::PURPOSE_REGISTRATION_INTENT, $token))->toBe(['email' => 'ada@example.com'])
        ->and(OAuthState::query()->first()->consumed_at)->toBeNull()
        ->and($service->consume(OAuthState::PURPOSE_REGISTRATION_INTENT, $token)['payload'])->toBe(['email' => 'ada@example.com']);
});

it('inspect returns null for absent consumed and expired tokens', function () {
    oauth_state_service_database();
    Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'UTC'));
    $service = oauth_state_service();

    $consumed = $service->issue(OAuthState::PURPOSE_HANDOFF, [], 120);
    $service->consume(OAuthState::PURPOSE_HANDOFF, $consumed);

    $expiring = $service->issue(OAuthState::PURPOSE_HANDOFF, [], 120);

    expect($service->inspect(OAuthState::PURPOSE_HANDOFF, null))->toBeNull()
        ->and($service->inspect(OAuthState::PURPOSE_HANDOFF, ''))->toBeNull()
        ->and($service->inspect('not_a_purpose', $expiring))->toBeNull()
        ->and($service->inspect(OAuthState::PURPOSE_HANDOFF, $consumed))->toBeNull()
        ->and($service->inspect(OAuthState::PURPOSE_HANDOFF, $expiring))->toBe([]);

    Carbon::setTestNow(Carbon::parse('2026-09-18 10:05:00', 'UTC'));
    expect($service->inspect(OAuthState::PURPOSE_HANDOFF, $expiring))->toBeNull();

    Carbon::setTestNow();
});

it('stores an hmac of the issuing ip rather than the address', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    $service->issue(OAuthState::PURPOSE_AUTHORIZATION, [], 600, 'google', 'login', null, '203.0.113.7');
    $row = OAuthState::query()->first();

    expect($row->ip_hash)->toBeString()->toHaveLength(64)
        ->and($row->ip_hash)->not->toContain('203.0.113.7')
        ->and($row->ip_hash)->toBe(hash_hmac('sha256', '203.0.113.7', (string) config('app.key')));
});

it('allows an ip change by default and warns instead of failing', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_HANDOFF, ['ok' => true], 120, null, null, null, '203.0.113.7');

    // A phone moving from wifi to cellular mid-flow legitimately changes address; failing
    // those users closed would cost more than this binding is worth.
    expect($service->consume(OAuthState::PURPOSE_HANDOFF, $token, '198.51.100.4')['payload'])->toBe(['ok' => true]);

    $warnings = array_filter(app('log')->entries, fn ($entry) => $entry[0] === 'warning');
    expect($warnings)->not->toBeEmpty();

    // The warning must carry no address and no token.
    foreach ($warnings as $entry) {
        expect(json_encode($entry))->not->toContain('198.51.100.4')
            ->and(json_encode($entry))->not->toContain($token);
    }
});

it('fails an ip change when strict binding is enabled', function () {
    oauth_state_service_database(['oauth.strict_ip_binding' => true]);
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_HANDOFF, [], 120, null, null, null, '203.0.113.7');

    expect(fn () => $service->consume(OAuthState::PURPOSE_HANDOFF, $token, '198.51.100.4'))
        ->toThrow(OAuthStateException::class, 'invalid_or_expired');
});

it('treats an undecryptable payload as invalid and logs no ciphertext', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_HANDOFF, ['secret' => 'value'], 120);

    // Simulates a rotated APP_KEY: the row survives, the payload no longer decrypts.
    $rotated = new OAuthStateService(new OAuthStateServiceEncrypterFake(str_repeat('j', 32)));

    expect(fn () => $rotated->consume(OAuthState::PURPOSE_HANDOFF, $token))
        ->toThrow(OAuthStateException::class, 'invalid_or_expired');

    $errors = array_filter(app('log')->entries, fn ($entry) => $entry[0] === 'error');
    expect($errors)->not->toBeEmpty();

    foreach ($errors as $entry) {
        expect(json_encode($entry))->not->toContain('value');
    }
});

it('records the resolving user on a redeemed row', function () {
    oauth_state_service_database();
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_REGISTRATION_INTENT, [], 900);
    $state = $service->consume(OAuthState::PURPOSE_REGISTRATION_INTENT, $token)['state'];

    $service->attachUser($state, 'user-uuid-1');

    expect(OAuthState::query()->first()->user_uuid)->toBe('user-uuid-1');
});

it('exposes the known purposes and hashes tokens for callers', function () {
    bind_test_container();
    $service = oauth_state_service();

    expect(OAuthStateService::purposes())->toBe(['authorization', 'handoff', 'registration_intent'])
        ->and($service->hash('abc'))->toBe(hash('sha256', 'abc'));
});

it('prunes only rows a full day past their expiry', function () {
    oauth_state_service_database();
    Carbon::setTestNow('2026-09-22 12:00:00');

    $service = oauth_state_service();
    $stale   = $service->issue(OAuthState::PURPOSE_HANDOFF, [], 120);
    $recent  = $service->issue(OAuthState::PURPOSE_HANDOFF, [], 120);

    // Expired over a day ago, and expired just now: only the first is prunable. An
    // expired row is kept for a day of grace, so support can still see it.
    OAuthState::query()->where('token_hash', $service->hash($stale))->update(['expires_at' => '2026-09-21 11:00:00']);
    OAuthState::query()->where('token_hash', $service->hash($recent))->update(['expires_at' => '2026-09-22 11:00:00']);

    $prunable = (new OAuthState())->prunable()->pluck('token_hash')->all();

    Carbon::setTestNow();

    expect($prunable)->toBe([$service->hash($stale)]);
});

it('belongs to the user it resolved to', function () {
    oauth_state_service_database();

    $relation = (new OAuthState())->user();

    expect($relation)->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($relation->getForeignKeyName())->toBe('user_uuid')
        ->and($relation->getOwnerKeyName())->toBe('uuid')
        ->and($relation->getRelated())->toBeInstanceOf(Fleetbase\Models\User::class);
});

it('refuses a token whose row disappears between redeeming and reading it', function () {
    $capsule = oauth_state_service_database();
    $service = oauth_state_service();

    $token = $service->issue(OAuthState::PURPOSE_HANDOFF, ['profile' => ['provider' => 'google']], 120);

    // The prune job deleting the row in the gap between the conditional UPDATE and the
    // read that follows it.
    $capsule->getConnection('mysql')->statement(
        'CREATE TRIGGER oauth_states_pruned AFTER UPDATE OF consumed_at ON oauth_states BEGIN DELETE FROM oauth_states WHERE uuid = NEW.uuid; END'
    );

    expect(fn () => $service->consume(OAuthState::PURPOSE_HANDOFF, $token))
        ->toThrow(OAuthStateException::class, 'invalid_or_expired')
        ->and(OAuthState::query()->count())->toBe(0);
});

it('inspects an undecryptable payload as absent rather than failing', function () {
    oauth_state_service_database();

    $token   = oauth_state_service()->issue(OAuthState::PURPOSE_REGISTRATION_INTENT, ['profile' => ['provider' => 'google']], 900);
    $rotated = new OAuthStateService(new OAuthStateServiceEncrypterFake(str_repeat('j', 32)));

    expect($rotated->inspect(OAuthState::PURPOSE_REGISTRATION_INTENT, $token))->toBeNull()
        // Inspecting never redeems, even when it cannot read the payload.
        ->and(OAuthState::query()->whereNull('consumed_at')->count())->toBe(1);
});

it('reads a payload that does not decrypt to a string as empty', function () {
    oauth_state_service_database();

    $token = oauth_state_service()->issue(OAuthState::PURPOSE_HANDOFF, ['profile' => ['provider' => 'google']], 120);

    // decrypt(..., false) is typed mixed; anything but a JSON string was not written by
    // this service and is not coerced into one.
    $service = new OAuthStateService(new class implements Encrypter {
        public function encrypt($value, $serialize = true)
        {
            return 'unused';
        }

        public function decrypt($payload, $unserialize = true)
        {
            return ['not' => 'a string'];
        }

        public function getKey()
        {
            return 'key';
        }
    });

    expect($service->consume(OAuthState::PURPOSE_HANDOFF, $token)['payload'])->toBe([]);
});
