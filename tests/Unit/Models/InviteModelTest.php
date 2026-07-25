<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class InviteModelTaggedCacheFake
{
    public function tags(array|string $tags): self
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

    public function increment(string $key, int $value = 1): int
    {
        return $value;
    }
}

class InviteModelResponseCacheFake
{
    public function clear(): void
    {
    }
}

function invite_model_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'sandbox',
    ]);
    $container->instance('responsecache', new InviteModelResponseCacheFake());
    $cache = new InviteModelTaggedCacheFake();
    $container->instance('cache', $cache);
    Cache::swap($cache);

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
    $schema->create('invites', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('created_by_uuid')->nullable();
        $table->string('subject_uuid')->nullable()->index();
        $table->string('subject_type')->nullable();
        $table->string('uri')->nullable();
        $table->string('code')->nullable();
        $table->string('protocol')->nullable();
        $table->text('recipients')->nullable();
        $table->string('reason')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    return $capsule;
}

it('generates invite identifiers codes recipients and pinned production connection on create', function () {
    invite_model_database();

    $invite = Invite::query()->create([
        'company_uuid' => 'company-1',
        'subject_uuid' => 'company-1',
        'subject_type' => Company::class,
        'protocol'     => 'email',
        'reason'       => 'join_company',
        'recipients'   => ['ada@example.com', 'grace@example.com'],
        'meta'         => ['role' => 'dispatcher'],
    ]);

    expect($invite->uuid)->toBeString()
        ->and($invite->public_id)->toStartWith('invite_')
        ->and($invite->uri)->toBeString()->toHaveLength(12)
        ->and($invite->code)->toMatch('/^[A-Z0-9]{7}$/')
        ->and($invite->recipients)->toBe(['ada@example.com', 'grace@example.com'])
        ->and($invite->meta)->toBe(['role' => 'dispatcher'])
        ->and($invite->getConnectionName())->toBe('mysql');
});

it('defaults invite expiration to one hour and parses explicit expiration values', function () {
    bind_test_container();
    Carbon::setTestNow(Carbon::parse('2026-06-05 14:00:00', 'UTC'));

    $defaultExpiry             = new Invite();
    $defaultExpiry->expires_at = null;

    $explicitExpiry             = new Invite();
    $explicitExpiry->expires_at = '2026-06-06 09:30:00';

    expect($defaultExpiry->getAttributes()['expires_at']->toDateTimeString())->toBe('2026-06-05 15:00:00')
        ->and($explicitExpiry->getAttributes()['expires_at']->toDateTimeString())->toBe('2026-06-06 09:30:00');

    Carbon::setTestNow();
});

it('exposes invite ownership and subject relationships', function () {
    bind_test_container();

    $invite = new Invite();

    expect($invite->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($invite->createdBy()->getForeignKeyName())->toBe('created_by_uuid')
        ->and($invite->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($invite->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($invite->subject()->getMorphType())->toBe('subject_type')
        ->and($invite->subject()->getForeignKeyName())->toBe('subject_uuid');
});

it('detects duplicate company invites by company subject protocol reason and recipient', function () {
    invite_model_database();

    Invite::query()->create([
        'company_uuid' => 'company-1',
        'subject_uuid' => 'company-1',
        'subject_type' => Company::class,
        'protocol'     => 'email',
        'reason'       => 'join_company',
        'recipients'   => ['ada@example.com', 'grace@example.com'],
    ]);

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1'], true);

    $invitedUser = new User();
    $invitedUser->setRawAttributes(['email' => 'ada@example.com'], true);

    $notInvitedUser = new User();
    $notInvitedUser->setRawAttributes(['email' => 'linus@example.com'], true);

    expect(Invite::isAlreadySentToJoinCompany($invitedUser, $company))->toBeTrue()
        ->and(Invite::isAlreadySentToJoinCompany($notInvitedUser, $company))->toBeFalse()
        ->and(Invite::isAlreadySent($company, 'ada@example.com', 'reset_password'))->toBeFalse()
        ->and(Invite::isAlreadySent($company, 'ada@example.com', 'join_company', 'sms'))->toBeFalse();
});
