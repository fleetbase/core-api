<?php

use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\OAuthState;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function oauth_identity_model_database(): Capsule
{
    EloquentModel::clearBootedModels();

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

    return $capsule;
}

it('uses an explicit table name', function () {
    bind_test_container();

    // Eloquent's convention would derive `o_auth_identities`, because Str::snake() treats the
    // capital A in "OAuth" as a word boundary. This assertion is the guard for that trap.
    expect((new OAuthIdentity())->getTable())->toBe('oauth_identities')
        ->and((new OAuthState())->getTable())->toBe('oauth_states');
});

it('is keyed by a string uuid that is generated on create', function () {
    oauth_identity_model_database();

    $identity = OAuthIdentity::query()->create([
        'user_uuid'        => 'user-1',
        'provider'         => 'google',
        'provider_user_id' => '1091',
    ]);

    $model = new OAuthIdentity();

    expect($model->getKeyName())->toBe('uuid')
        ->and($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeFalse()
        ->and($identity->uuid)->toBeString()->not->toBeEmpty();
});

it('honours an explicitly supplied uuid', function () {
    oauth_identity_model_database();

    $identity = OAuthIdentity::query()->create([
        'uuid'             => 'fixed-uuid',
        'user_uuid'        => 'user-1',
        'provider'         => 'google',
        'provider_user_id' => '1091',
    ]);

    expect($identity->uuid)->toBe('fixed-uuid');
});

it('hides the provider subject id from serialization', function () {
    oauth_identity_model_database();

    $identity = OAuthIdentity::query()->create([
        'user_uuid'        => 'user-1',
        'provider'         => 'google',
        'provider_user_id' => 'subject-that-must-not-leak',
        'provider_email'   => 'ada@example.com',
    ]);

    // provider_user_id is a stable cross-application identifier for the person at that
    // provider. No client needs it, and it must never appear in an API response.
    expect($identity->toArray())->not->toHaveKey('provider_user_id')
        ->and($identity->toArray())->not->toHaveKey('id')
        ->and(json_encode($identity))->not->toContain('subject-that-must-not-leak')
        ->and($identity->toArray())->toHaveKey('provider_email');
});

it('casts meta verification flag and login timestamp', function () {
    oauth_identity_model_database();

    $identity = OAuthIdentity::query()->create([
        'user_uuid'        => 'user-1',
        'provider'         => 'google',
        'provider_user_id' => '1091',
        'email_verified'   => 1,
        'meta'             => ['locale' => 'en', 'private_relay' => false],
        'last_login_at'    => '2024-01-15 08:30:00',
    ]);

    $fresh = OAuthIdentity::query()->first();

    expect($fresh->email_verified)->toBeTrue()
        ->and($fresh->meta)->toBe(['locale' => 'en', 'private_relay' => false])
        ->and($fresh->last_login_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->last_login_at->toDateTimeString())->toBe('2024-01-15 08:30:00');
});

it('defaults email_verified to false so an unknown state is never treated as verified', function () {
    oauth_identity_model_database();

    OAuthIdentity::query()->create([
        'user_uuid'        => 'user-1',
        'provider'         => 'github',
        'provider_user_id' => '42',
    ]);

    expect(OAuthIdentity::query()->first()->email_verified)->toBeFalse();
});

it('enforces one fleetbase account per provider subject', function () {
    oauth_identity_model_database();

    OAuthIdentity::query()->create([
        'user_uuid'        => 'user-1',
        'provider'         => 'google',
        'provider_user_id' => '1091',
    ]);

    // The unique index is a security control: it is what guarantees a provider subject can
    // never be claimed by two Fleetbase accounts.
    expect(fn () => OAuthIdentity::query()->create([
        'user_uuid'        => 'user-2',
        'provider'         => 'google',
        'provider_user_id' => '1091',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('allows the same subject id across different providers', function () {
    oauth_identity_model_database();

    OAuthIdentity::query()->create(['user_uuid' => 'user-1', 'provider' => 'google', 'provider_user_id' => '1091']);
    OAuthIdentity::query()->create(['user_uuid' => 'user-1', 'provider' => 'github', 'provider_user_id' => '1091']);

    expect(OAuthIdentity::query()->count())->toBe(2);
});

it('does not soft delete', function () {
    oauth_identity_model_database();

    OAuthIdentity::query()->create(['user_uuid' => 'user-1', 'provider' => 'google', 'provider_user_id' => '1091']);
    OAuthIdentity::query()->first()->delete();

    // A soft-deleted row would keep occupying the unique index and permanently block
    // re-linking that provider account — exactly what a user does after an accidental unlink.
    expect(OAuthIdentity::query()->count())->toBe(0);

    OAuthIdentity::query()->create(['user_uuid' => 'user-1', 'provider' => 'google', 'provider_user_id' => '1091']);
    expect(OAuthIdentity::query()->count())->toBe(1);
});

it('belongs to a user', function () {
    bind_test_container();

    $relation = (new OAuthIdentity())->user();

    expect($relation->getRelated())->toBeInstanceOf(User::class)
        ->and($relation->getForeignKeyName())->toBe('user_uuid')
        ->and($relation->getOwnerKeyName())->toBe('uuid');
});

it('exposes the user oauth identities relation', function () {
    bind_test_container();

    $relation = (new User())->oauthIdentities();

    expect($relation->getRelated())->toBeInstanceOf(OAuthIdentity::class)
        ->and($relation->getForeignKeyName())->toBe('user_uuid')
        ->and($relation->getLocalKeyName())->toBe('uuid');
});
