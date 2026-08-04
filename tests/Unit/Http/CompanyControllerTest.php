<?php

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Exports\CompanyExport;
use Fleetbase\Http\Controllers\Internal\v1\CompanyController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\Company;
use Fleetbase\Models\Extension;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Maatwebsite\Excel\Facades\Excel;
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

    public function getRoleClass(): string
    {
        return Fleetbase\Models\Role::class;
    }

    public function getPermissionClass(): string
    {
        return Fleetbase\Models\Permission::class;
    }
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

class CompanyControllerPaginatorFake
{
    private Collection $collection;
    private int $total;

    public function __construct(Collection $items, private int $perPage, private int $page = 1, private string $path = '/')
    {
        $this->collection = $items->forPage($page, $perPage)->values();
        $this->total      = $items->count();
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function setCollection(Collection $collection): void
    {
        $this->collection = $collection->values();
    }

    public function currentPage(): int
    {
        return $this->page;
    }

    public function firstItem(): ?int
    {
        return $this->total === 0 ? null : (($this->page - 1) * $this->perPage) + 1;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function lastItem(): ?int
    {
        if ($this->total === 0) {
            return null;
        }

        return min($this->page * $this->perPage, $this->total);
    }

    public function total(): int
    {
        return $this->total;
    }
}

class CompanyControllerInvalidUpdateModel extends Company
{
    public function getApiPayloadFromRequest($request, array $only = [], array $except = []): array
    {
        return ['unexpected_field' => 'not allowed'];
    }

    public function fillSessionAttributes(?array $target = [], array $except = [], array $only = []): array
    {
        return $target ?? [];
    }

    public function isColumn($column): bool
    {
        return false;
    }

    public function isInvalidUpdateParam(string $key): bool
    {
        return true;
    }
}

class CompanyControllerThrowingUpdateModel extends Company
{
    public function __construct(private ?Throwable $throwable = null)
    {
        parent::__construct();
    }

    public function getApiPayloadFromRequest($request, array $only = [], array $except = []): array
    {
        throw $this->throwable ?? new RuntimeException('Company update failed.');
    }
}

class CompanyControllerExcelFake
{
    public ?object $export   = null;
    public ?string $filename = null;

    public function download(object $export, string $filename): Response
    {
        $this->export   = $export;
        $this->filename = $filename;

        return new Response('company export');
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

    if (!function_exists('Fleetbase\\Http\\Controllers\\Internal\\v1\\event')) {
        eval('namespace Fleetbase\\Http\\Controllers\\Internal\\v1; function event($event = null) { return $event; }');
    }

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

    EloquentBuilder::macro('fastPaginate', function (int $perPage = 15) {
        return new CompanyControllerPaginatorFake($this->get(), $perPage, 1, '/int/v1/companies');
    });

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
    $schema->create('invites', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('created_by_uuid')->nullable();
        $table->string('subject_uuid')->nullable()->index();
        $table->string('subject_type')->nullable();
        $table->string('uri')->nullable()->index();
        $table->string('code')->nullable();
        $table->string('protocol')->nullable();
        $table->text('recipients')->nullable();
        $table->string('reason')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('extensions', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('extension_id')->nullable();
        $table->string('author_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('type_uuid')->nullable();
        $table->string('icon_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('display_name')->nullable();
        $table->string('key')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->string('namespace')->nullable();
        $table->string('internal_route')->nullable();
        $table->string('fa_icon')->nullable();
        $table->string('version')->nullable();
        $table->string('website_url')->nullable();
        $table->string('privacy_policy_url')->nullable();
        $table->string('tos_url')->nullable();
        $table->string('contact_email')->nullable();
        $table->text('domains')->nullable();
        $table->boolean('core_service')->default(false);
        $table->text('meta')->nullable();
        $table->string('meta_type')->nullable();
        $table->text('config')->nullable();
        $table->string('secret')->nullable();
        $table->string('client_token')->nullable();
        $table->string('status')->nullable();
        $table->string('slug')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('extension_installs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('extension_id')->nullable()->index();
        $table->string('extension_uuid')->nullable()->index();
        $table->string('company_uuid')->index();
        $table->text('meta')->nullable();
        $table->text('config')->nullable();
        $table->text('overwrite')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->string('description')->nullable();
        $table->timestamps();
    });
    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->string('service')->nullable();
        $table->text('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
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
    $capsule->getConnection('mysql')->table('roles')->insert([
        ['id' => 'Administrator', 'company_uuid' => null, 'name' => 'Administrator', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
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

test('company controller returns stable errors for hidden organization updates deletes and invalid update params', function () {
    company_controller_fixtures();

    $missingUpdate = company_controller()->updateRecord(company_controller_request('PUT', [
        'name' => 'Blocked',
    ]), 'company_public_2');
    $missingDelete            = company_controller()->deleteRecord('company_public_2', company_controller_request('DELETE'));
    $invalidController        = company_controller();
    $invalidController->model = new CompanyControllerInvalidUpdateModel();
    $invalidUpdate            = $invalidController->updateRecord(company_controller_request('PUT', [
        'unexpected_field' => 'not allowed',
    ]), 'company_public_1');

    expect($missingUpdate->getStatusCode())->toBe(404)
        ->and($missingUpdate->getData(true))->toBe(['errors' => ['Organization not found.']])
        ->and($missingDelete->getStatusCode())->toBe(404)
        ->and($missingDelete->getData(true))->toBe(['errors' => ['Organization not found.']])
        ->and($invalidUpdate->getStatusCode())->toBe(400)
        ->and($invalidUpdate->getData(true))->toBe(['errors' => ['Invalid param "unexpected_field" in update request!']]);
});

test('company controller update formats database and validation failures as stable errors', function () {
    company_controller_fixtures();

    $queryController        = company_controller();
    $queryController->model = new CompanyControllerThrowingUpdateModel(new QueryException('mysql', 'update companies', [], new RuntimeException('database rejected company update')));
    $queryResponse          = $queryController->updateRecord(company_controller_request('PUT', [
        'name' => 'Blocked',
    ]), 'company_public_1');

    $validationController        = company_controller();
    $validationController->model = new CompanyControllerThrowingUpdateModel(new FleetbaseRequestValidationException(['name' => ['The organization name is invalid.']]));
    $validationResponse          = $validationController->updateRecord(company_controller_request('PUT', [
        'name' => '',
    ]), 'company_public_1');

    expect($queryResponse->getStatusCode())->toBe(400)
        ->and($queryResponse->getData(true)['errors'][0])->toContain('database rejected company update')
        ->and($validationResponse->getStatusCode())->toBe(400)
        ->and($validationResponse->getData(true))->toBe(['errors' => ['name' => ['The organization name is invalid.']]]);
});

test('company controller generic visibility checks require a session organization', function () {
    company_controller_fixtures();
    session()->flush();

    $find  = company_controller()->findRecord(company_controller_request(), 'company_public_1');
    $users = company_controller()->users('company_public_1', company_controller_request('GET'));

    expect($find->getStatusCode())->toBe(404)
        ->and($find->getData(true))->toBe(['errors' => ['Organization not found.']])
        ->and($users->getStatusCode())->toBe(404)
        ->and($users->getData(true))->toBe(['error' => 'Organization not found.']);
});

test('company controller public lookup resolves organizations by public id and join invite uri', function () {
    company_controller_fixtures();

    Invite::unguarded(function () {
        Invite::create([
            'uuid'         => 'invite-join-1',
            'public_id'    => 'invite_public_1',
            'company_uuid' => 'company-1',
            'subject_uuid' => 'company-1',
            'subject_type' => Company::class,
            'uri'          => 'join-acme',
            'code'         => 'ACME123',
            'protocol'     => 'email',
            'recipients'   => ['new@example.test'],
            'reason'       => 'join_company',
            'meta'         => [],
        ]);
    });

    $public = company_controller()->findCompany(' company_public_1 ');
    $invite = company_controller()->findCompany('join-acme');

    expect($public->resource->uuid)->toBe('company-1')
        ->and($invite->resource->uuid)->toBe('company-1');
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

test('company controller user listing filters excludes sorts and paginates response metadata', function () {
    company_controller_fixtures();

    $searched = company_controller()->users('company_public_1', company_controller_request('GET', [
        'query'   => 'OWNER',
        'exclude' => ['member-1'],
        'sort'    => 'oldest',
    ]));
    $searchedIds = $searched->collection->map(fn ($resource) => $resource->resource->uuid)->values()->all();

    $paginated = company_controller()->users('company_public_1', company_controller_request('GET', [
        'paginate' => true,
        'limit'    => 1,
        'sort'     => 'oldest',
    ]));
    $payload = $paginated->getData(true);

    expect($searchedIds)->toBe(['owner-1'])
        ->and($paginated->getStatusCode())->toBe(200)
        ->and($payload['users'][0]['uuid'])->toBe('owner-1')
        ->and($payload['meta'])->toMatchArray([
            'current_page' => 1,
            'from'         => 1,
            'last_page'    => 1,
            'per_page'     => 20,
            'to'           => 2,
            'total'        => 2,
        ]);
});

test('company controller reads and saves current organization two factor settings', function () {
    $capsule = company_controller_fixtures();

    $defaults = company_controller()->getTwoFactorSettings();

    expect($defaults->getStatusCode())->toBe(200)
        ->and($defaults->getData(true))->toBe([
            'enabled' => false,
            'method'  => 'email',
        ]);

    $saved = company_controller()->saveTwoFactorSettings(company_controller_request('POST', [
        'twoFaSettings' => [
            'enabled'  => true,
            'method'   => 'sms',
            'enforced' => true,
        ],
    ]));

    expect($saved->getStatusCode())->toBe(200)
        ->and($saved->getData(true))->toBe(['message' => 'Two-Factor Authentication saved successfully'])
        ->and(json_decode($capsule->getConnection('mysql')->table('settings')->where('key', 'company.company-1.2fa')->value('value'), true))->toMatchArray([
            'enabled'  => true,
            'method'   => 'sms',
            'enforced' => true,
        ]);

    $disabled = company_controller()->saveTwoFactorSettings(company_controller_request('POST', [
        'twoFaSettings' => [
            'enabled'  => false,
            'method'   => 'email',
            'enforced' => true,
        ],
    ]));

    expect($disabled->getStatusCode())->toBe(200)
        ->and(json_decode($capsule->getConnection('mysql')->table('settings')->where('key', 'company.company-1.2fa')->value('value'), true))->toMatchArray([
            'enabled'  => false,
            'method'   => 'email',
            'enforced' => false,
        ]);
});

test('company controller two factor settings require an active organization session', function () {
    company_controller_fixtures();
    session()->flush();

    $read = company_controller()->getTwoFactorSettings();
    $save = company_controller()->saveTwoFactorSettings(company_controller_request('POST', [
        'twoFaSettings' => ['enabled' => true, 'method' => 'email'],
    ]));

    expect($read->getStatusCode())->toBe(401)
        ->and($read->getData(true))->toBe(['errors' => ['No company session found']])
        ->and($save->getStatusCode())->toBe(401)
        ->and($save->getData(true))->toBe(['errors' => ['No company session found']]);
});

test('company controller admin extensions endpoint scopes installs and skips missing extension records', function () {
    $capsule = company_controller_fixtures();
    $admin   = company_controller_user('admin-1');
    $now     = '2026-07-18 12:00:00';

    $capsule->getConnection('mysql')->table('extensions')->insert([
        [
            'uuid'         => 'extension-1',
            'public_id'    => 'ext_public_1',
            'extension_id' => 'DISPATCHBOARD',
            'author_uuid'  => 'company-1',
            'name'         => 'Dispatch Board',
            'display_name' => 'Dispatch Board Pro',
            'key'          => 'dispatch-board',
            'description'  => 'Dispatch operations board',
            'tags'         => json_encode(['dispatch']),
            'namespace'    => Extension::createNamespace('Fleetbase', 'Dispatch Board'),
            'fa_icon'      => 'route',
            'version'      => '1.2.3',
            'core_service' => false,
            'meta'         => json_encode([]),
            'config'       => json_encode([]),
            'status'       => 'published',
            'slug'         => 'dispatch-board',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => 'extension-2',
            'public_id'    => 'ext_public_2',
            'extension_id' => 'NODISPLAY',
            'author_uuid'  => 'company-1',
            'name'         => 'Fallback Extension',
            'display_name' => null,
            'key'          => 'fallback-extension',
            'description'  => 'Fallback extension description',
            'tags'         => json_encode([]),
            'namespace'    => Extension::createNamespace('Fleetbase', 'Fallback Extension'),
            'fa_icon'      => null,
            'version'      => '2.0.0',
            'core_service' => false,
            'meta'         => json_encode([]),
            'config'       => json_encode([]),
            'status'       => null,
            'slug'         => 'fallback-extension',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ]);

    $capsule->getConnection('mysql')->table('extension_installs')->insert([
        ['uuid' => 'install-1', 'extension_id' => 'extension-1', 'extension_uuid' => 'extension-1', 'company_uuid' => 'company-1', 'meta' => json_encode([]), 'config' => json_encode([]), 'overwrite' => json_encode([]), 'created_at' => '2026-07-18 12:00:00', 'updated_at' => $now],
        ['uuid' => 'install-2', 'extension_id' => 'extension-2', 'extension_uuid' => 'extension-2', 'company_uuid' => 'company-1', 'meta' => json_encode([]), 'config' => json_encode([]), 'overwrite' => json_encode([]), 'created_at' => '2026-07-18 13:00:00', 'updated_at' => $now],
        ['uuid' => 'install-missing-extension', 'extension_id' => 'missing-extension', 'extension_uuid' => 'missing-extension', 'company_uuid' => 'company-1', 'meta' => json_encode([]), 'config' => json_encode([]), 'overwrite' => json_encode([]), 'created_at' => '2026-07-18 14:00:00', 'updated_at' => $now],
        ['uuid' => 'install-foreign', 'extension_id' => 'extension-1', 'extension_uuid' => 'extension-1', 'company_uuid' => 'company-2', 'meta' => json_encode([]), 'config' => json_encode([]), 'overwrite' => json_encode([]), 'created_at' => '2026-07-18 15:00:00', 'updated_at' => $now],
    ]);

    $missing = company_controller()->extensions('missing-company', company_controller_admin_request('GET', [], $admin));
    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Organization not found.']);

    $response = company_controller()->extensions('company_public_1', company_controller_admin_request('GET', [], $admin));
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and(array_column($payload['extensions'], 'uuid'))->toBe(['install-2', 'install-1'])
        ->and($payload['extensions'][0])->toMatchArray([
            'id'           => 'install-2',
            'extension_id' => 'NODISPLAY',
            'name'         => 'Fallback Extension',
            'description'  => 'Fallback extension description',
            'icon'         => 'puzzle-piece',
            'slug'         => 'fallback-extension',
            'key'          => 'fallback-extension',
            'version'      => '2.0.0',
            'status'       => 'installed',
        ])
        ->and($payload['extensions'][1])->toMatchArray([
            'id'           => 'install-1',
            'extension_id' => 'DISPATCHBOARD',
            'name'         => 'Dispatch Board Pro',
            'icon'         => 'route',
            'status'       => 'published',
        ]);
});

test('company controller admin status updates validate status persist active state and log activity', function () {
    $capsule  = company_controller_fixtures();
    $activity = company_controller_bind_activity();
    $admin    = company_controller_user('admin-1');

    $missing = company_controller()->setAdminStatus('missing-company', company_controller_admin_request('POST', [
        'status' => 'active',
    ], $admin));
    $invalid = company_controller()->setAdminStatus('company_public_2', company_controller_admin_request('POST', [
        'status' => 'archived',
    ], $admin));

    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Organization not found.'])
        ->and($invalid->getStatusCode())->toBe(422)
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

test('company controller export downloads selected organizations in requested format', function () {
    company_controller_fixtures();
    $excel = new CompanyControllerExcelFake();
    Excel::swap($excel);

    $response = company_controller()->export(ExportRequest::create('/int/v1/companies/export', 'GET', [
        'format'     => 'csv',
        'selections' => ['company-1', 'company-2'],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('company export')
        ->and($excel->export)->toBeInstanceOf(CompanyExport::class)
        ->and($excel->filename)->toEndWith('.csv');
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

test('company controller admin ownership transfer validates organization membership and logs owner changes', function () {
    $capsule  = company_controller_fixtures();
    $activity = company_controller_bind_activity();
    $admin    = company_controller_user('admin-1');

    $missingCompany = company_controller()->transferOwnershipAdmin('missing-company', company_controller_admin_request('POST', [
        'newOwner' => 'member-1',
    ], $admin));
    $notMember = company_controller()->transferOwnershipAdmin('company_public_1', company_controller_admin_request('POST', [
        'newOwner' => 'foreign-1',
    ], $admin));

    expect($missingCompany->getStatusCode())->toBe(404)
        ->and($missingCompany->getData(true))->toBe(['error' => 'Organization not found.'])
        ->and($notMember->getStatusCode())->toBe(422)
        ->and($notMember->getData(true))->toBe(['error' => 'The new owner is not a member of this organization.']);

    $transferred = company_controller()->transferOwnershipAdmin('company_public_1', company_controller_admin_request('POST', [
        'newOwner' => 'member-1',
    ], $admin));
    $payload     = $transferred->getData(true);

    expect($transferred->getStatusCode())->toBe(200)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['newOwner']['uuid'])->toBe('member-1')
        ->and($payload['company']['uuid'])->toBe('company-1')
        ->and($capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->value('owner_uuid'))->toBe('member-1')
        ->and($activity->entries)->toHaveCount(1)
        ->and($activity->entries[0]['message'])->toBe('Organization ownership transferred')
        ->and($activity->entries[0]['properties'])->toBe([
            'old'        => ['owner_uuid' => 'owner-1'],
            'attributes' => ['owner_uuid' => 'member-1'],
        ]);
});

test('company controller admin user lifecycle updates membership verification and removal contracts', function () {
    $capsule  = company_controller_fixtures();
    $activity = company_controller_bind_activity();
    $admin    = company_controller_user('admin-1');

    $missingCompany = company_controller()->deactivateAdminUser('missing-company', 'member-1', company_controller_admin_request('POST', [], $admin));
    $missingUser    = company_controller()->deactivateAdminUser('company_public_1', 'missing-user', company_controller_admin_request('POST', [], $admin));
    $notMember      = company_controller()->deactivateAdminUser('company_public_1', 'foreign-1', company_controller_admin_request('POST', [], $admin));
    $ownerBlocked   = company_controller()->deactivateAdminUser('company_public_1', 'owner-1', company_controller_admin_request('POST', [], $admin));
    $verifyMissing  = company_controller()->verifyAdminUser('missing-company', 'member-1', company_controller_admin_request('POST', [], $admin));
    $removeMissing  = company_controller()->removeAdminUser('missing-company', 'member-1', company_controller_admin_request('POST', [], $admin));

    expect($missingCompany->getStatusCode())->toBe(404)
        ->and($missingCompany->getData(true))->toBe(['error' => 'Organization not found.'])
        ->and($missingUser->getStatusCode())->toBe(404)
        ->and($missingUser->getData(true))->toBe(['error' => 'User not found.'])
        ->and($notMember->getStatusCode())->toBe(404)
        ->and($notMember->getData(true))->toBe(['error' => 'User is not a member of this organization.'])
        ->and($ownerBlocked->getStatusCode())->toBe(422)
        ->and($ownerBlocked->getData(true))->toBe(['error' => 'Transfer ownership before deactivating the organization owner.'])
        ->and($verifyMissing->getStatusCode())->toBe(404)
        ->and($verifyMissing->getData(true))->toBe(['error' => 'Organization not found.'])
        ->and($removeMissing->getStatusCode())->toBe(404)
        ->and($removeMissing->getData(true))->toBe(['error' => 'Organization not found.']);

    $deactivated       = company_controller()->deactivateAdminUser('company_public_1', 'member-1', company_controller_admin_request('POST', [], $admin));
    $deactivatedStatus = $capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-1')->value('status');
    $activated         = company_controller()->activateAdminUser('company_public_1', 'member-1', company_controller_admin_request('POST', [], $admin));
    $activatedStatus   = $capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-1')->value('status');
    $verified          = company_controller()->verifyAdminUser('company_public_1', 'member-1', company_controller_admin_request('POST', [], $admin));
    $removed           = company_controller()->removeAdminUser('company_public_1', 'member-1', company_controller_admin_request('POST', [], $admin));

    expect($deactivated->getStatusCode())->toBe(200)
        ->and($deactivated->getData(true)['message'])->toBe('User deactivated')
        ->and($deactivated->getData(true)['status'])->toBe('inactive')
        ->and($deactivatedStatus)->toBe('inactive')
        ->and($activated->getStatusCode())->toBe(200)
        ->and($activated->getData(true)['message'])->toBe('User activated')
        ->and($activatedStatus)->toBe('active')
        ->and($verified->getStatusCode())->toBe(200)
        ->and($verified->getData(true)['message'])->toBe('User verified')
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'member-1')->value('email_verified_at'))->not->toBeNull()
        ->and($removed->getStatusCode())->toBe(200)
        ->and($removed->getData(true))->toBe(['message' => 'User removed'])
        ->and($capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-1')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'member-1')->value('company_uuid'))->toBe('company-3')
        ->and($activity->entries)->toHaveCount(4)
        ->and(array_column($activity->entries, 'message'))->toBe([
            'Organization user deactivated',
            'Organization user activated',
            'Organization user verified',
            'Organization user removed',
        ])
        ->and($activity->entries[0]['properties']['old'])->toBe(['status' => 'active'])
        ->and($activity->entries[0]['properties']['attributes'])->toBe(['status' => 'inactive', 'user_uuid' => 'member-1'])
        ->and($activity->entries[3]['event'])->toBe('deleted')
        ->and($activity->entries[3]['properties']['attributes'])->toBe(['user_uuid' => 'member-1', 'email' => 'member@example.test']);
});

test('company controller admin user removal protects organization owners', function () {
    company_controller_fixtures();
    $admin = company_controller_user('admin-1');

    $response = company_controller()->removeAdminUser('company_public_1', 'owner-1', company_controller_admin_request('POST', [], $admin));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe(['error' => 'Transfer ownership before removing the organization owner.']);
});

test('company controller transfer ownership rejects invalid session ownership and company mismatches', function () {
    $capsule = company_controller_fixtures();

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

    $capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->delete();
    $missingCompany = company_controller()->transferOwnership(company_controller_request('POST', [
        'company'  => 'company-1',
        'newOwner' => 'member-1',
    ], company_controller_user('owner-1')));

    expect($missingCompany->getStatusCode())->toBe(400)
        ->and($missingCompany->getData(true))->toBe(['errors' => ['No organization found to transfer ownership for.']]);
});

test('company controller transfers ownership to another organization member and can remove the previous owner', function () {
    $capsule = company_controller_fixtures();

    $transfer = company_controller()->transferOwnership(company_controller_request('POST', [
        'company'  => 'company-1',
        'newOwner' => 'member-1',
    ], company_controller_user('owner-1')));

    expect($transfer->getStatusCode())->toBe(200)
        ->and($transfer->getData(true)['status'])->toBe('ok')
        ->and($transfer->getData(true)['newOwner']['uuid'])->toBe('member-1')
        ->and($transfer->getData(true)['currentUserLeft'])->toBeFalse()
        ->and($capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->value('owner_uuid'))->toBe('member-1')
        ->and($capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-owner-1')->whereNull('deleted_at')->exists())->toBeTrue();

    $capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-owner-1')->update([
        'deleted_at' => null,
    ]);
    $capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->update([
        'owner_uuid' => 'owner-1',
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        'uuid'         => 'pivot-owner-3',
        'company_uuid' => 'company-3',
        'user_uuid'    => 'owner-1',
        'status'       => 'active',
        'created_at'   => '2026-07-18 00:00:00',
        'updated_at'   => '2026-07-18 00:00:00',
    ]);
    session(['company' => 'company-1', 'user' => 'owner-1']);

    $leave = company_controller()->transferOwnership(company_controller_request('POST', [
        'company'  => 'company-1',
        'newOwner' => 'member-1',
        'leave'    => true,
    ], company_controller_user('owner-1')));

    expect($leave->getStatusCode())->toBe(200)
        ->and($leave->getData(true)['status'])->toBe('ok')
        ->and($leave->getData(true)['currentUserLeft'])->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->value('owner_uuid'))->toBe('member-1')
        ->and($capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-owner-1')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'owner-1')->value('company_uuid'))->toBe('company-3');
});

test('company controller transfer ownership blocks non members and self-transfer leave requests', function () {
    company_controller_fixtures();

    $notMember = company_controller()->transferOwnership(company_controller_request('POST', [
        'company'  => 'company-1',
        'newOwner' => 'foreign-1',
    ], company_controller_user('owner-1')));
    $selfLeave = company_controller()->transferOwnership(company_controller_request('POST', [
        'company'  => 'company-1',
        'newOwner' => 'owner-1',
        'leave'    => true,
    ], company_controller_user('owner-1')));

    expect($notMember->getStatusCode())->toBe(400)
        ->and($notMember->getData(true))->toBe(['errors' => ['The new owner provided could not be found for transfer of ownership.']])
        ->and($selfLeave->getStatusCode())->toBe(422)
        ->and($selfLeave->getData(true))->toBe(['errors' => ['Select a different organization member before leaving.']]);
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

test('company controller leave organization rejects missing sessions missing companies and non memberships', function () {
    $capsule = company_controller_fixtures();

    session()->flush();
    $noSession = company_controller()->leaveOrganization(company_controller_request('POST', [
        'company' => 'company-1',
    ], company_controller_user('member-1')));

    session(['company' => 'company-1', 'user' => 'member-1']);
    $wrongCompany = company_controller()->leaveOrganization(company_controller_request('POST', [
        'company' => 'company-2',
    ], company_controller_user('member-1')));

    $capsule->getConnection('mysql')->table('companies')->where('uuid', 'company-1')->delete();
    $missingCompany = company_controller()->leaveOrganization(company_controller_request('POST', [
        'company' => 'company-1',
    ], company_controller_user('member-1')));

    $capsule->getConnection('mysql')->table('companies')->insert([
        'uuid'       => 'company-1',
        'public_id'  => 'company_public_1',
        'name'       => 'Acme Logistics',
        'owner_uuid' => 'owner-1',
        'slug'       => 'acme-logistics',
        'status'     => null,
        'timezone'   => 'UTC',
        'country'    => 'US',
        'currency'   => 'USD',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
    $capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-1')->update([
        'deleted_at' => '2026-07-18 01:00:00',
    ]);
    $notMember = company_controller()->leaveOrganization(company_controller_request('POST', [
        'company' => 'company-1',
    ], company_controller_user('member-1')));

    expect($noSession->getStatusCode())->toBe(400)
        ->and($noSession->getData(true))->toBe(['errors' => ['Unable to leave organization.']])
        ->and($wrongCompany->getStatusCode())->toBe(400)
        ->and($wrongCompany->getData(true))->toBe(['errors' => ['Unable to leave organization.']])
        ->and($missingCompany->getStatusCode())->toBe(400)
        ->and($missingCompany->getData(true))->toBe(['errors' => ['No organization found for user to leave.']])
        ->and($notMember->getStatusCode())->toBe(400)
        ->and($notMember->getData(true))->toBe(['errors' => ['User selected to leave organization is not a member of this organization.']]);
});
