<?php

use Fleetbase\Events\AccountCreated;
use Fleetbase\Listeners\HandleAccountCreated;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;

class HandleAccountCreatedMailFake
{
    /**
     * @var array<int, mixed>
     */
    public array $sent = [];

    public function to(mixed $recipient): self
    {
        return $this;
    }

    public function send(mixed $mailable): void
    {
        $this->sent[] = $mailable;
    }
}

class HandleAccountCreatedCacheFake
{
    private array $values = [];

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
}

class HandleAccountCreatedResponseCacheFake
{
    public function clear(): void
    {
    }
}

function handle_account_created_database(): HandleAccountCreatedMailFake
{
    EloquentModel::clearBootedModels();

    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'activitylog.enabled'        => false,
        'app.timezone'               => 'UTC',
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));
    $container->instance('cache', new HandleAccountCreatedCacheFake());
    $container->instance('responsecache', new HandleAccountCreatedResponseCacheFake());

    $mail = new HandleAccountCreatedMailFake();
    $container->instance('mailer', $mail);
    Mail::swap($mail);

    foreach (['cache', 'responsecache', 'log'] as $facade) {
        Facade::clearResolvedInstance($facade);
    }

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
    $schema->create('verification_codes', function ($table) {
        $table->string('uuid')->primary();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('code')->nullable();
        $table->string('for')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        // VerificationCode soft-deletes and applies an expiry global scope, so both
        // columns have to exist for a plain count() to run.
        $table->timestamp('deleted_at')->nullable();
    });

    return $mail;
}

function handle_account_created_user(array $attributes = []): User
{
    $user = new User();
    $user->forceFill(array_merge([
        'uuid'              => 'user-1',
        'email'             => 'ada@example.com',
        'name'              => 'Ada',
        'type'              => 'user',
        'email_verified_at' => null,
    ], $attributes));

    return $user;
}

function handle_account_created_fire(User $user): void
{
    (new HandleAccountCreated())->handle(new AccountCreated($user, new Company()));
}

it('sends a verification code for an ordinary password signup', function () {
    $mail = handle_account_created_database();

    handle_account_created_fire(handle_account_created_user());

    // A password signup is never verified at this point, so the added guard is a
    // no-op and existing behaviour is unchanged.
    expect(VerificationCode::query()->count())->toBe(1)
        ->and(VerificationCode::query()->first()->for)->toBe('email_verification')
        ->and($mail->sent)->toHaveCount(1);
});

it('sends no verification code when the provider already verified the address', function () {
    $mail = handle_account_created_database();

    // This is what lets an OAuth signup skip the emailed code: Support\OAuth promotes
    // email_verified_at before AccountCreated fires, and this guard reads it. Without
    // it the user would get a code they have no reason to enter.
    handle_account_created_fire(handle_account_created_user(['email_verified_at' => '2024-01-15 10:00:00']));

    expect(VerificationCode::query()->count())->toBe(0)
        ->and($mail->sent)->toBeEmpty();
});

it('sends no verification code to an admin', function () {
    $mail = handle_account_created_database();

    // The first user of an install skips verification entirely.
    handle_account_created_fire(handle_account_created_user(['type' => 'admin']));

    expect(VerificationCode::query()->count())->toBe(0)
        ->and($mail->sent)->toBeEmpty();
});

it('treats a phone verified account as verified too', function () {
    $mail = handle_account_created_database();

    handle_account_created_fire(handle_account_created_user(['phone_verified_at' => '2024-01-15 10:00:00']));

    expect(VerificationCode::query()->count())->toBe(0)
        ->and($mail->sent)->toBeEmpty();
});

it('ignores an event carrying no user', function () {
    handle_account_created_database();

    $event       = new AccountCreated(handle_account_created_user(), new Company());
    $event->user = null;

    handle_account_created_fire_null($event);

    expect(VerificationCode::query()->count())->toBe(0);
});

function handle_account_created_fire_null(AccountCreated $event): void
{
    (new HandleAccountCreated())->handle($event);
}
