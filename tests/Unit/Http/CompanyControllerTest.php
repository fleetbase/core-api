<?php

use Fleetbase\Http\Controllers\Internal\v1\CompanyController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\PendingActivityLog;

class CompanyControllerCacheFake
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

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->values[$key] ??= $callback();
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }
}

class CompanyControllerPermissionRegistrarFake
{
    public string $pivotRole       = 'role_id';
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';
}

class CompanyControllerRouteStub
{
    public array $action = [
        'namespace' => 'Fleetbase\\Http\\Controllers\\Internal\\v1',
    ];

    public function __construct(private string $uri = 'int/v1/admin/companies')
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class CompanyControllerActivityFake
{
    public array $entries  = [];
    private array $current = [];

    public function performedOn(EloquentModel $subject): self
    {
        $this->current['subject'] = $subject;

        return $this;
    }

    public function causedBy(EloquentModel|int|string|null $user): self
    {
        $this->current['user'] = $user;

        return $this;
    }

    public function event(string $event): self
    {
        $this->current['event'] = $event;

        return $this;
    }

    public function withProperties(mixed $properties): self
    {
        $this->current['properties'] = $properties;

        return $this;
    }

    public function log(string $message): ActivityContract
    {
        $this->current['message'] = $message;
        $this->entries[]          = $this->current;
        $this->current            = [];

        return new CompanyControllerActivityRecordFake();
    }
}

class CompanyControllerActivityRecordFake extends EloquentModel implements ActivityContract
{
    public bool $saved = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }

    public function subject(): MorphTo
    {
        throw new RuntimeException('Subject relation is not used by this test fake.');
    }

    public function causer(): MorphTo
    {
        throw new RuntimeException('Causer relation is not used by this test fake.');
    }

    public function getExtraProperty(string $propertyName, mixed $defaultValue): mixed
    {
        return $defaultValue;
    }

    public function changes(): Collection
    {
        return collect();
    }

    public function scopeInLog(Builder $query, ...$logNames): Builder
    {
        return $query;
    }

    public function scopeCausedBy(Builder $query, EloquentModel $causer): Builder
    {
        return $query;
    }

    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query;
    }

    public function scopeForSubject(Builder $query, EloquentModel $subject): Builder
    {
        return $query;
    }
}

class CompanyControllerActivityLoggerFake extends ActivityLogger
{
    public function __construct(private CompanyControllerActivityFake $activityFake)
    {
    }

    public function performedOn(EloquentModel $model): static
    {
        $this->activityFake->performedOn($model);

        return $this;
    }

    public function causedBy(EloquentModel|int|string|null $modelOrId): static
    {
        $this->activityFake->causedBy($modelOrId);

        return $this;
    }

    public function event(string $event): static
    {
        $this->activityFake->event($event);

        return $this;
    }

    public function withProperties(mixed $properties): static
    {
        $this->activityFake->withProperties($properties);

        return $this;
    }

    public function log(string $description): ?ActivityContract
    {
        return $this->activityFake->log($description);
    }
}

class CompanyControllerPendingActivityLogFake extends PendingActivityLog
{
    public function __construct(private CompanyControllerActivityLoggerFake $activityLogger)
    {
    }

    public function useLog(?string $logName): self
    {
        return $this;
    }

    public function logger(): ActivityLogger
    {
        return $this->activityLogger;
    }
}

function company_controller_fixtures(): Capsule
{
    EloquentModel::clearBootedModels();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $container = bind_test_container([
        'app.env'                                      => 'testing',
        'app.url'                                      => 'http://fleetbase.test',
        'auth.defaults.guard'                          => 'sanctum',
        'database.default'                             => 'mysql',
        'fleetbase.connection.db'                      => 'mysql',
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.column_names.model_morph_key'      => 'model_uuid',
        'database.connections.mysql'                   => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
    ]);

    if (!Request::hasMacro('or')) {
        Request::macro('or', function (array $params = [], mixed $default = null): mixed {
            foreach ($params as $param) {
                if ($this->has($param)) {
                    return $this->input($param);
                }
            }

            return $default;
        });
    }

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            if (is_string($value) && str_contains($value, ',')) {
                return explode(',', $value);
            }

            return (array) $value;
        });
    }

    if (!Request::hasMacro('searchQuery')) {
        Request::macro('searchQuery', function (): mixed {
            $searchQueryParam = $this->or(['query', 'searchQuery', 'nestedQuery']);

            return is_string($searchQueryParam) ? urldecode(strtolower($searchQueryParam)) : $searchQueryParam;
        });
    }

    if (!EloquentBuilder::hasGlobalMacro('applySortFromRequest')) {
        EloquentBuilder::macro('applySortFromRequest', function (Request $request): EloquentBuilder {
            $sort = $request->input('sort');

            if ($sort === 'oldest') {
                return $this->oldest();
            }

            return $this->latest();
        });
    }

    $container->instance('cache', new CompanyControllerCacheFake());
    $container->instance(Spatie\Permission\PermissionRegistrar::class, new CompanyControllerPermissionRegistrarFake());
    Facade::clearResolvedInstance('cache');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'owner-1',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection(config('database.connections.mysql'), 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('slug')->nullable();
        $table->string('status')->nullable();
        $table->string('timezone')->nullable();
        $table->string('country')->nullable();
        $table->string('currency')->nullable();
        $table->timestamp('onboarding_completed_at')->nullable();
        $table->string('onboarding_completed_by_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->index();
        $table->string('user_uuid')->index();
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->nullable()->index();
        $table->text('value')->nullable();
    });

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('companies')->insert([
        [
            'uuid'       => 'company-1',
            'public_id'  => 'company_public_1',
            'name'       => 'Acme Logistics',
            'owner_uuid' => 'owner-1',
            'slug'       => 'acme-logistics',
            'status'     => null,
            'timezone'   => 'UTC',
            'country'    => 'US',
            'currency'   => 'USD',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'uuid'       => 'company-2',
            'public_id'  => 'company_public_2',
            'name'       => 'Beta Freight',
            'owner_uuid' => 'owner-2',
            'slug'       => 'beta-freight',
            'status'     => 'inactive',
            'timezone'   => 'UTC',
            'country'    => 'SG',
            'currency'   => 'SGD',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'uuid'       => 'company-3',
            'public_id'  => 'company_public_3',
            'name'       => 'Gamma Warehousing',
            'owner_uuid' => 'member-1',
            'slug'       => 'gamma-warehousing',
            'status'     => null,
            'timezone'   => 'UTC',
            'country'    => 'GB',
            'currency'   => 'GBP',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $capsule->getConnection('mysql')->table('users')->insert([
        [
            'uuid'         => 'owner-1',
            'public_id'    => 'user_owner_1',
            'company_uuid' => 'company-1',
            'email'        => 'owner@example.test',
            'name'         => 'Owner One',
            'type'         => 'user',
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => 'member-1',
            'public_id'    => 'user_member_1',
            'company_uuid' => 'company-1',
            'email'        => 'member@example.test',
            'name'         => 'Member One',
            'type'         => 'user',
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => 'foreign-1',
            'public_id'    => 'user_foreign_1',
            'company_uuid' => 'company-2',
            'email'        => 'foreign@example.test',
            'name'         => 'Foreign User',
            'type'         => 'user',
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => 'admin-1',
            'public_id'    => 'user_admin_1',
            'company_uuid' => null,
            'email'        => 'admin@example.test',
            'name'         => 'Admin User',
            'type'         => 'admin',
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ]);

    $capsule->getConnection('mysql')->table('company_users')->insert([
        ['uuid' => 'pivot-owner-1', 'company_uuid' => 'company-1', 'user_uuid' => 'owner-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'pivot-member-1', 'company_uuid' => 'company-1', 'user_uuid' => 'member-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'pivot-member-3', 'company_uuid' => 'company-3', 'user_uuid' => 'member-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'pivot-foreign-1', 'company_uuid' => 'company-2', 'user_uuid' => 'foreign-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function company_controller(): CompanyController
{
    return new CompanyController();
}

function company_controller_request(string $method = 'GET', array $input = [], ?User $user = null): Request
{
    $request = Request::create('/int/v1/companies', $method, $input);
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => new CompanyControllerRouteStub('int/v1/companies'));

    app()->instance('request', $request);

    return $request;
}

function company_controller_admin_request(string $method = 'GET', array $input = [], ?User $user = null): AdminRequest
{
    $request = AdminRequest::create('/int/v1/admin/companies', $method, $input);
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => new CompanyControllerRouteStub());

    app()->instance('request', $request);

    return $request;
}

function company_controller_user(string $uuid): User
{
    return User::where('uuid', $uuid)->firstOrFail();
}

function company_controller_bind_activity(): CompanyControllerActivityFake
{
    $activity = new CompanyControllerActivityFake();
    app()->instance(PendingActivityLog::class, new CompanyControllerPendingActivityLogFake(new CompanyControllerActivityLoggerFake($activity)));

    return $activity;
}

afterEach(function () {
    session()->flush();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('company controller resolves only the active session organization for generic find update and delete', function () {
    $capsule = company_controller_fixtures();

    $found = company_controller()->findRecord(company_controller_request(), 'company_public_1');

    expect($found['company']->resource->uuid)->toBe('company-1');

    $foreign = company_controller()->findRecord(company_controller_request(), 'company_public_2');
    expect($foreign->getStatusCode())->toBe(404)
        ->and($foreign->getData(true))->toBe(['errors' => ['Organization not found.']]);

    $updated = company_controller()->updateRecord(company_controller_request('PUT', [
        'name'   => 'Acme Updated',
        'slug'   => 'attempted-slug-change',
        'status' => 'suspended',
    ]), 'company_public_1');

    $record = $capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->first();

    expect($updated['company']->resource->name)->toBe('Acme Updated')
        ->and($record->name)->toBe('Acme Updated')
        ->and($record->status)->toBe('suspended')
        ->and($record->slug)->toBe('acme-logistics');

    $deleted = company_controller()->deleteRecord('company_public_1', company_controller_request('DELETE'));
    expect($deleted->getStatusCode())->toBe(403)
        ->and($deleted->getData(true))->toBe(['errors' => ['Generic organization deletion is not supported.']]);
});

test('company controller user listing respects session company scope unless the requester is admin', function () {
    company_controller_fixtures();

    $normalUsers = company_controller()->users('company_public_1', company_controller_request('GET'));
    $normalIds   = $normalUsers->collection->map(fn ($resource) => $resource->resource->uuid)->values()->all();
    sort($normalIds);

    expect($normalIds)->toBe(['member-1', 'owner-1']);

    $blocked = company_controller()->users('company_public_2', company_controller_request('GET'));
    expect($blocked->getStatusCode())->toBe(404)
        ->and($blocked->getData(true))->toBe(['error' => 'Organization not found.']);

    $adminUsers = company_controller()->users('company_public_2', company_controller_request('GET', [], company_controller_user('admin-1')));
    $adminIds   = $adminUsers->collection->map(fn ($resource) => $resource->resource->uuid)->values()->all();
    sort($adminIds);

    expect($adminIds)->toBe(['foreign-1']);
});

test('company controller admin status updates validate status persist active state and log activity', function () {
    $capsule  = company_controller_fixtures();
    $activity = company_controller_bind_activity();
    $admin    = company_controller_user('admin-1');

    $invalid = company_controller()->setAdminStatus('company_public_2', company_controller_admin_request('POST', [
        'status' => 'archived',
    ], $admin));

    expect($invalid->getStatusCode())->toBe(422)
        ->and($invalid->getData(true))->toBe(['error' => 'Invalid organization status.']);

    $suspended = company_controller()->setAdminStatus('company_public_2', company_controller_admin_request('POST', [
        'status' => 'suspended',
    ], $admin));

    $payload = $suspended->getData(true);
    expect($suspended->getStatusCode())->toBe(200)
        ->and($payload['company']['uuid'])->toBe('company-2')
        ->and($payload['company']['status'])->toBe('suspended')
        ->and($capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-2')->value('status'))->toBe('suspended');

    $active = company_controller()->setAdminStatus('company_public_2', company_controller_admin_request('POST', [
        'status' => 'active',
    ], $admin));

    expect($active->getStatusCode())->toBe(200)
        ->and($active->getData(true)['company']['status'])->toBeNull()
        ->and($capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-2')->value('status'))->toBeNull()
        ->and($activity->entries)->toHaveCount(2)
        ->and($activity->entries[0]['message'])->toBe('Organization status changed')
        ->and($activity->entries[0]['event'])->toBe('updated')
        ->and($activity->entries[0]['properties']['old'])->toBe(['status' => 'inactive'])
        ->and($activity->entries[0]['properties']['attributes'])->toBe(['status' => 'suspended'])
        ->and($activity->entries[1]['properties']['old'])->toBe(['status' => 'suspended'])
        ->and($activity->entries[1]['properties']['attributes'])->toBe(['status' => 'active']);
});

test('company controller admin onboarding toggles completion metadata and handles missing organizations', function () {
    $capsule  = company_controller_fixtures();
    $activity = company_controller_bind_activity();
    $admin    = company_controller_user('admin-1');

    $missing = company_controller()->setAdminOnboarding('missing-company', company_controller_admin_request('POST', [
        'completed' => true,
    ], $admin));

    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Organization not found.']);

    $completed = company_controller()->setAdminOnboarding('company_public_2', company_controller_admin_request('POST', [
        'completed' => true,
    ], $admin));

    $completedRecord = $capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-2')->first();
    expect($completed->getStatusCode())->toBe(200)
        ->and($completed->getData(true)['company']['uuid'])->toBe('company-2')
        ->and($completedRecord->onboarding_completed_at)->not->toBeNull()
        ->and($completedRecord->onboarding_completed_by_uuid)->toBe('admin-1');

    $incomplete = company_controller()->setAdminOnboarding('company_public_2', company_controller_admin_request('POST', [
        'completed' => false,
    ], $admin));

    $incompleteRecord = $capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-2')->first();
    expect($incomplete->getStatusCode())->toBe(200)
        ->and($incompleteRecord->onboarding_completed_at)->toBeNull()
        ->and($incompleteRecord->onboarding_completed_by_uuid)->toBeNull()
        ->and($activity->entries)->toHaveCount(2)
        ->and($activity->entries[0]['message'])->toBe('Organization onboarding marked complete')
        ->and($activity->entries[0]['properties']['old'])->toBe(['onboarding_completed_at' => null])
        ->and($activity->entries[1]['message'])->toBe('Organization onboarding marked incomplete')
        ->and($activity->entries[1]['properties']['attributes'])->toBe(['onboarding_completed_at' => null]);
});

test('company controller transfer ownership rejects invalid session ownership and company mismatches', function () {
    company_controller_fixtures();

    session()->flush();
    $noSession = company_controller()->transferOwnership(company_controller_request('POST', [
        'company'  => 'company-1',
        'newOwner' => 'member-1',
    ], company_controller_user('owner-1')));

    expect($noSession->getStatusCode())->toBe(400)
        ->and($noSession->getData(true))->toBe(['errors' => ['No organization found to transfer ownership for.']]);

    session(['company' => 'company-1', 'user' => 'member-1']);
    $notOwner = company_controller()->transferOwnership(company_controller_request('POST', [
        'company'  => 'company-1',
        'newOwner' => 'owner-1',
    ], company_controller_user('member-1')));

    expect($notOwner->getStatusCode())->toBe(403)
        ->and($notOwner->getData(true))->toBe(['errors' => ['Only the organization owner can transfer ownership.']]);

    session(['company' => 'company-1', 'user' => 'owner-1']);
    $wrongCompany = company_controller()->transferOwnership(company_controller_request('POST', [
        'company'  => 'company-2',
        'newOwner' => 'member-1',
    ], company_controller_user('owner-1')));

    expect($wrongCompany->getStatusCode())->toBe(400)
        ->and($wrongCompany->getData(true))->toBe(['errors' => ['No organization found to transfer ownership for.']]);
});

test('company controller blocks owners from leaving and moves non owners to their next organization', function () {
    $capsule = company_controller_fixtures();

    $ownerLeave = company_controller()->leaveOrganization(company_controller_request('POST', [
        'company' => 'company-1',
    ], company_controller_user('owner-1')));

    expect($ownerLeave->getStatusCode())->toBe(403)
        ->and($ownerLeave->getData(true))->toBe(['errors' => ['Transfer ownership before leaving the organization.']]);

    session(['company' => 'company-1', 'user' => 'member-1']);
    $memberLeave = company_controller()->leaveOrganization(company_controller_request('POST', [
        'company' => 'company-1',
    ], company_controller_user('member-1')));

    expect($memberLeave->getStatusCode())->toBe(200)
        ->and($memberLeave->getData(true))->toBe(['status' => 'ok'])
        ->and($capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-1')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'member-1')->value('company_uuid'))->toBe('company-3');
});
