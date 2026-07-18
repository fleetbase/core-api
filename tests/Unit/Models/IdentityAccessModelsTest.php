<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\Directive;
use Fleetbase\Models\Group;
use Fleetbase\Models\GroupUser;
use Fleetbase\Models\LoginAttempt;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class IdentityAccessCacheFake
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
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class IdentityAccessOrderFixture extends EloquentModel
{
    protected $connection = 'mysql';
    protected $table      = 'identity_access_orders';
    protected $guarded    = [];
    public $timestamps    = false;
}

class IdentityAccessSessionFake
{
    public function get(string $key, mixed $default = null): mixed
    {
        return session($key, $default);
    }
}

class IdentityAccessAuthFake
{
    public function user(): object
    {
        return (object) [
            'uuid' => 'user-1',
        ];
    }
}

function identity_access_models_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'            => false,
        'auth.defaults.guard'          => 'sanctum',
        'auth.guards.sanctum.driver'   => 'session',
        'auth.guards.sanctum.provider' => 'users',
        'auth.providers.users.driver'  => 'eloquent',
        'auth.providers.users.model'   => User::class,
        'database.default'             => 'mysql',
        'database.connections.mysql'   => $connection,
        'fleetbase.connection.db'      => 'mysql',
        'permission.defaults.guard'    => 'sanctum',
    ]);
    $container->instance('cache', new IdentityAccessCacheFake());
    $container->instance('session', new IdentityAccessSessionFake());
    $container->instance('auth', new IdentityAccessAuthFake());
    $container->instance('responsecache', new class {
        public function clear(): bool
        {
            return true;
        }
    });
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('session');
    Facade::clearResolvedInstance('auth');
    Facade::clearResolvedInstance('responsecache');
    session()->flush();

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
    $schema->create('group_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('group_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('login_attempts', function ($table) {
        $table->string('uuid')->primary();
        $table->string('session_uuid')->nullable();
        $table->string('identity')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

it('routes group notifications to loaded users and preserves group relation metadata', function () {
    identity_access_models_database();

    $userA = new User();
    $userA->setRawAttributes(['uuid' => 'user-1', 'email' => 'ada@example.com'], true);
    $userB = new User();
    $userB->setRawAttributes(['uuid' => 'user-2', 'email' => 'grace@example.com'], true);

    $group = new Group([
        '_key'         => 'console',
        'public_id'    => 'group_dispatch',
        'company_uuid' => 'company-1',
        'name'         => 'Dispatchers',
        'description'  => 'Dispatch desk operators',
    ]);
    $group->setRelation('users', collect([$userA, $userB]));

    expect($group->routeNotificationForMail()->all())->toBe(['ada@example.com', 'grace@example.com'])
        ->and($group->containsMultipleNotifiables)->toBe('users')
        ->and((fn () => $this->with)->call($group))->toBe(['users', 'permissions', 'policies'])
        ->and($group->getSlugOptions()->generateSlugFrom)->toBe(['name'])
        ->and($group->getSlugOptions()->slugField)->toBe('slug')
        ->and($group->users()->getFirstKeyName())->toBe('group_uuid')
        ->and($group->users()->getForeignKeyName())->toBe('uuid')
        ->and($group->users()->getLocalKeyName())->toBe('uuid')
        ->and($group->users()->getSecondLocalKeyName())->toBe('user_uuid');
});

it('keeps group user membership relationships and credential tracking boundaries stable', function () {
    identity_access_models_database();
    session([
        'api_key' => 'flb_live_membership',
        'company' => 'company-from-session',
    ]);

    $membership = GroupUser::query()->create([
        'company_uuid' => 'company-explicit',
        'user_uuid'    => 'user-1',
        'group_uuid'   => 'group-1',
    ]);

    expect($membership->uuid)->toBeString()
        ->and($membership->_key)->toBeNull()
        ->and($membership->company_uuid)->toBe('company-explicit')
        ->and($membership->user()->getForeignKeyName())->toBe('user_uuid')
        ->and($membership->user()->getOwnerKeyName())->toBe('uuid')
        ->and($membership->group()->getForeignKeyName())->toBe('group_uuid')
        ->and($membership->group()->getRelated())->toBeInstanceOf(Group::class)
        ->and($membership->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($membership->company()->getRelated())->toBeInstanceOf(Company::class);
});

it('tracks login attempts while hiding sensitive identity and password fields', function () {
    identity_access_models_database();

    if (!class_exists(Fleetbase\Models\Session::class, false)) {
        eval('namespace Fleetbase\Models; class Session extends Model { protected $table = "sessions"; }');
    }

    $attempt = LoginAttempt::track([
        'session_uuid' => 'session-1',
        'identity'     => 'ada@example.com',
        'password'     => 'plaintext-secret',
    ]);

    expect($attempt)->toBeInstanceOf(LoginAttempt::class)
        ->and($attempt->uuid)->toBeString()
        ->and($attempt->identity)->toBe('ada@example.com')
        ->and($attempt->password)->toBe('plaintext-secret')
        ->and($attempt->session()->getForeignKeyName())->toBe('session_uuid')
        ->and($attempt->session()->getOwnerKeyName())->toBe('uuid')
        ->and($attempt->toArray())->not->toHaveKeys(['identity', 'password', 'session_uuid'])
        ->and((fn () => $this->hidden)->call($attempt))->toBe(['password', 'identity', 'session_uuid'])
        ->and($attempt->getSearchableColumns())->toBe([])
        ->and((fn () => $this->searchColumns)->call($attempt))->toBe(['identity']);
});

it('casts applies encodes and relates authorization directives', function () {
    identity_access_models_database();

    session(['company' => 'company-1']);

    $directive = new Directive([
        'uuid'            => 'directive-1',
        'company_uuid'    => 'company-1',
        'permission_uuid' => 'permission-1',
        'subject_type'    => Policy::class,
        'subject_uuid'    => 'policy-1',
        'key'             => Directive::createKey(['where', 'company_uuid', '=', 'session.company']),
        'rules'           => ['where', 'company_uuid', '=', 'session.company'],
    ]);

    $query = IdentityAccessOrderFixture::query();
    $directive->apply($query);

    expect($directive->rules)->toBe(['where', 'company_uuid', '=', 'session.company'])
        ->and($query->toSql())->toBe('select * from "identity_access_orders" where "company_uuid" = ?')
        ->and($query->getBindings())->toBe(['company-1'])
        ->and(Directive::decodeKey($directive->key))->toBe(['where', 'company_uuid', '=', 'session.company'])
        ->and($directive->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($directive->company()->getOwnerKeyName())->toBe('uuid')
        ->and($directive->permission()->getForeignKeyName())->toBe('permission_uuid')
        ->and($directive->permission()->getOwnerKeyName())->toBe('id')
        ->and($directive->permission()->getRelated())->toBeInstanceOf(Permission::class)
        ->and($directive->subject()->getMorphType())->toBe('subject_type')
        ->and($directive->subject()->getForeignKeyName())->toBe('subject_uuid');
});
