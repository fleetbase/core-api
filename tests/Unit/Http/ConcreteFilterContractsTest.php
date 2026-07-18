<?php

use Fleetbase\Expansions\Builder as BuilderExpansion;
use Fleetbase\Http\Filter\CategoryFilter;
use Fleetbase\Http\Filter\CompanyFilter;
use Fleetbase\Http\Filter\ScheduleExceptionFilter;
use Fleetbase\Http\Filter\ScheduleItemFilter;
use Fleetbase\Http\Filter\ScheduleTemplateFilter;
use Fleetbase\Http\Filter\UserFilter;
use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\ScheduleException;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\ScheduleTemplate;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class ConcreteFilterRoute
{
    public array $action;

    public function __construct(private string $uri, string $namespace = '')
    {
        $this->action = ['namespace' => $namespace];
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

function concrete_filter_database(): Capsule
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
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    EloquentBuilder::macro('searchWhere', (new BuilderExpansion())->searchWhere());

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();

    foreach ([
        'categories',
        'company_users',
        'companies',
        'invites',
        'roles',
        'schedule_exceptions',
        'schedule_items',
        'schedule_templates',
        'schedules',
        'users',
    ] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('country')->nullable();
        $table->string('status')->nullable();
        $table->string('plan')->nullable();
        $table->timestamp('onboarding_completed_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $schema->create('company_users', function ($table) {
        $table->increments('id');
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->softDeletes();
    });

    $schema->create('invites', function ($table) {
        $table->increments('id');
        $table->string('company_uuid')->nullable();
        $table->text('recipients')->nullable();
        $table->string('reason')->nullable();
        $table->softDeletes();
    });

    $schema->create('categories', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('parent_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('for')->nullable();
        $table->boolean('core_category')->default(false);
        $table->softDeletes();
    });

    $schema->create('schedules', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->softDeletes();
    });

    $schema->create('schedule_items', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('schedule_uuid')->nullable();
        $table->string('template_uuid')->nullable();
        $table->string('assignee_uuid')->nullable();
        $table->string('assignee_type')->nullable();
        $table->timestamp('start_at')->nullable();
        $table->timestamp('end_at')->nullable();
        $table->softDeletes();
    });

    $schema->create('schedule_exceptions', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('schedule_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->softDeletes();
    });

    $schema->create('schedule_templates', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('schedule_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->softDeletes();
    });

    $schema->create('roles', function ($table) {
        $table->increments('id');
    });

    session()->flush();
    session(['company' => 'company-1', 'user' => 'user-1']);

    return $capsule;
}

function concrete_filter_request(array $query = [], string $routeUri = 'int/v1/resources'): Request
{
    $request = Request::create('/' . $routeUri, 'GET', $query);
    $request->setRouteResolver(fn () => new ConcreteFilterRoute($routeUri));

    return $request;
}

function concrete_filter_uuids(string $filterClass, string $modelClass, array $query = [], string $routeUri = 'int/v1/resources'): array
{
    return (new $filterClass(concrete_filter_request($query, $routeUri)))
        ->apply($modelClass::query())
        ->orderBy('uuid')
        ->pluck('uuid')
        ->all();
}

function concrete_filter_admin_uuids(string $filterClass, string $modelClass, array $query = [], string $routeUri = 'int/v1/resources'): array
{
    $request = concrete_filter_request(['view' => 'admin', ...$query], $routeUri);
    $request->setUserResolver(fn () => new class {
        public function isAdmin(): bool
        {
            return true;
        }
    });

    return (new $filterClass($request))
        ->apply($modelClass::query())
        ->orderBy('uuid')
        ->pluck('uuid')
        ->all();
}

function concrete_filter_with_builder(object $filter, EloquentBuilder $builder): object
{
    $property = new ReflectionProperty(Fleetbase\Http\Filter\Filter::class, 'builder');
    $property->setAccessible(true);
    $property->setValue($filter, $builder);

    return $filter;
}

afterEach(function () {
    session()->flush();
    EloquentModel::unsetEventDispatcher();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('company filter scopes non admin users to owned or joined companies and supports attention flags', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-owned', 'name' => 'Owned Co', 'owner_uuid' => 'user-1', 'country' => 'SG', 'status' => 'active', 'onboarding_completed_at' => '2026-01-01 00:00:00'],
        ['uuid' => 'company-joined', 'name' => 'Joined Co', 'owner_uuid' => 'user-2', 'country' => 'US', 'status' => 'active', 'onboarding_completed_at' => '2026-01-01 00:00:00'],
        ['uuid' => 'company-attention', 'name' => 'Needs Help', 'owner_uuid' => null, 'country' => 'US', 'status' => 'pending', 'onboarding_completed_at' => null],
        ['uuid' => 'company-hidden', 'name' => 'Hidden Co', 'owner_uuid' => 'user-3', 'country' => 'US', 'status' => 'active', 'onboarding_completed_at' => '2026-01-01 00:00:00'],
    ]);
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-1', 'email' => 'member@example.test', 'type' => 'user'],
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        ['company_uuid' => 'company-joined', 'user_uuid' => 'user-1'],
    ]);

    $request = concrete_filter_request(['view' => 'user']);
    $request->setUserResolver(fn () => new class {
        public function isAdmin(): bool
        {
            return false;
        }
    });

    $scoped = (new CompanyFilter($request))
        ->apply(Company::query())
        ->orderBy('uuid')
        ->pluck('uuid')
        ->all();

    expect($scoped)->toBe(['company-joined', 'company-owned'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['needs_attention' => '1']))->toBe(['company-attention'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['missing_owner' => 'true']))->toBe(['company-attention'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['inactive_status' => 'true']))->toBe(['company-attention'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['onboarding_completed' => 'false']))->toBe(['company-attention'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['billing_status' => 'legacy']))->toBe([]);
});

test('company filter leaves admin company listing unscoped and applies searchable fields', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'Acme Logistics', 'owner_uuid' => 'user-1', 'country' => 'US', 'status' => 'active', 'plan' => 'legacy'],
        ['uuid' => 'company-2', 'name' => 'Beta Freight', 'owner_uuid' => 'user-2', 'country' => 'SG', 'status' => 'pending', 'plan' => null],
    ]);

    $request = concrete_filter_request(['view' => 'admin', 'query' => 'Freight', 'country' => 'SG', 'status' => 'pending']);
    $request->setUserResolver(fn () => new class {
        public function isAdmin(): bool
        {
            return true;
        }
    });

    $matches = (new CompanyFilter($request))
        ->apply(Company::query())
        ->pluck('uuid')
        ->all();

    expect($matches)->toBe(['company-2']);
});

test('user filter includes current company members and pending invite recipients', function () {
    $capsule = concrete_filter_database();
    $pdo     = $capsule->getConnection('mysql')->getPdo();
    $pdo->sqliteCreateFunction('JSON_CONTAINS', fn ($json, $needle) => str_contains((string) $json, trim((string) $needle, '"')) ? 1 : 0, 2);

    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-member', 'name' => 'Ada Member', 'email' => 'member@example.test', 'phone' => '+15550001', 'type' => 'user'],
        ['uuid' => 'user-invited', 'name' => 'Grace Invited', 'email' => 'invited@example.test', 'phone' => '+15550002', 'type' => 'user'],
        ['uuid' => 'user-admin', 'name' => 'Root Admin', 'email' => 'admin@example.test', 'phone' => '+15550003', 'type' => 'admin'],
        ['uuid' => 'user-hidden', 'name' => 'Hidden Person', 'email' => 'hidden@example.test', 'phone' => '+15550004', 'type' => 'user'],
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        ['company_uuid' => 'company-1', 'user_uuid' => 'user-member'],
        ['company_uuid' => 'company-2', 'user_uuid' => 'user-hidden'],
    ]);
    $capsule->getConnection('mysql')->table('invites')->insert([
        ['company_uuid' => 'company-1', 'recipients' => '["invited@example.test"]', 'reason' => 'join_company', 'deleted_at' => null],
    ]);

    expect(concrete_filter_uuids(UserFilter::class, User::class, [], 'int/v1/users'))->toBe(['user-invited', 'user-member'])
        ->and(concrete_filter_uuids(UserFilter::class, User::class, ['is_not_admin' => '1'], 'int/v1/users'))->toBe(['user-invited', 'user-member'])
        ->and(concrete_filter_uuids(UserFilter::class, User::class, ['is_user' => '1'], 'int/v1/users'))->toBe(['user-invited', 'user-member'])
        ->and(concrete_filter_uuids(UserFilter::class, User::class, ['email' => 'invited'], 'int/v1/users'))->toBe(['user-invited']);
});

test('category filter applies tenant core parent and list filters', function () {
    $capsule = concrete_filter_database();
    $parentUuid = '11111111-1111-4111-8111-111111111111';
    $capsule->getConnection('mysql')->table('categories')->insert([
        ['uuid' => $parentUuid, 'public_id' => 'category_parent', 'company_uuid' => 'company-1', 'parent_uuid' => null, 'name' => 'Parent', 'for' => 'order', 'core_category' => false],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'category_child', 'company_uuid' => 'company-1', 'parent_uuid' => $parentUuid, 'name' => 'Child', 'for' => 'order', 'core_category' => false],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'category_core', 'company_uuid' => null, 'parent_uuid' => null, 'name' => 'Core', 'for' => 'shipment', 'core_category' => true],
        ['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'category_hidden', 'company_uuid' => 'company-2', 'parent_uuid' => null, 'name' => 'Hidden', 'for' => 'order', 'core_category' => false],
    ]);

    expect(concrete_filter_uuids(CategoryFilter::class, Category::class, [], 'int/v1/categories'))->toBe(['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['parents_only' => '1'], 'int/v1/categories'))->toBe(['11111111-1111-4111-8111-111111111111'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['core_category' => '1'], 'int/v1/categories'))->toBe(['33333333-3333-4333-8333-333333333333'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['parent_category' => $parentUuid], 'int/v1/categories'))->toBe(['22222222-2222-4222-8222-222222222222'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['for' => 'shipment'], 'int/v1/categories'))->toBe([]);
});

test('schedule item filter resolves schedule identifiers and date ranges within tenant scope', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('schedules')->insert([
        ['uuid' => 'schedule-1', 'public_id' => 'schedule_public_1', 'company_uuid' => 'company-1'],
        ['uuid' => 'schedule-2', 'public_id' => 'schedule_public_2', 'company_uuid' => 'company-2'],
    ]);
    $capsule->getConnection('mysql')->table('schedule_items')->insert([
        ['uuid' => 'item-direct', 'company_uuid' => 'company-1', 'schedule_uuid' => 'schedule-1', 'assignee_uuid' => 'driver-1', 'assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'start_at' => '2026-07-18 08:00:00', 'end_at' => '2026-07-18 12:00:00'],
        ['uuid' => 'item-fallback', 'company_uuid' => null, 'schedule_uuid' => 'schedule-1', 'assignee_uuid' => 'driver-2', 'assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'start_at' => '2026-07-19 08:00:00', 'end_at' => '2026-07-19 12:00:00'],
        ['uuid' => 'item-hidden', 'company_uuid' => 'company-2', 'schedule_uuid' => 'schedule-2', 'assignee_uuid' => 'driver-1', 'assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'start_at' => '2026-07-18 08:00:00', 'end_at' => '2026-07-18 12:00:00'],
    ]);

    $rangeBuilder = ScheduleItem::query();
    $rangeFilter = concrete_filter_with_builder(
        new ScheduleItemFilter(concrete_filter_request([], 'int/v1/schedule-items')),
        $rangeBuilder
    );
    $rangeFilter->startAtBetween('2026-07-19 00:00:00', '2026-07-19 23:59:59');
    $rangeFilter->endAtBetween(null, '2026-07-19 23:59:59');
    $rangeMatches = $rangeBuilder
        ->pluck('uuid')
        ->all();

    expect(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, [], 'int/v1/schedule-items'))->toBe(['item-direct', 'item-fallback'])
        ->and(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, ['schedule_uuid' => 'schedule_public_1'], 'int/v1/schedule-items'))->toBe(['item-direct', 'item-fallback'])
        ->and(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, ['schedule_uuid' => 'missing_schedule'], 'int/v1/schedule-items'))->toBe([])
        ->and(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, ['assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'assignee_uuid' => 'driver-1'], 'int/v1/schedule-items'))->toBe(['item-direct'])
        ->and($rangeMatches)->toBe(['item-fallback']);
});

test('schedule exception and template filters scope tenants and resolve subjects and schedules', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('schedules')->insert([
        ['uuid' => 'schedule-1', 'public_id' => 'schedule_public_1', 'company_uuid' => 'company-1'],
        ['uuid' => 'schedule-2', 'public_id' => 'schedule_public_2', 'company_uuid' => 'company-2'],
    ]);
    $capsule->getConnection('mysql')->table('schedule_exceptions')->insert([
        ['uuid' => 'exception-1', 'company_uuid' => 'company-1', 'schedule_uuid' => 'schedule-1', 'subject_uuid' => 'driver-1', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'],
        ['uuid' => 'exception-hidden', 'company_uuid' => 'company-2', 'schedule_uuid' => 'schedule-2', 'subject_uuid' => 'driver-2', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'],
    ]);
    $capsule->getConnection('mysql')->table('schedule_templates')->insert([
        ['uuid' => 'template-1', 'company_uuid' => 'company-1', 'schedule_uuid' => 'schedule-1', 'subject_uuid' => 'driver-1', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'],
        ['uuid' => 'template-hidden', 'company_uuid' => 'company-2', 'schedule_uuid' => 'schedule-2', 'subject_uuid' => 'driver-2', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'],
    ]);

    expect(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['schedule_uuid' => 'schedule_public_1'], 'int/v1/schedule-exceptions'))->toBe(['exception-1'])
        ->and(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'subject_uuid' => 'driver-1'], 'int/v1/schedule-exceptions'))->toBe(['exception-1'])
        ->and(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['schedule_uuid' => 'missing_schedule'], 'int/v1/schedule-exceptions'))->toBe([])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['schedule_uuid' => 'schedule_public_1'], 'int/v1/schedule-templates'))->toBe(['template-1'])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'subject_uuid' => 'driver-1'], 'int/v1/schedule-templates'))->toBe(['template-1'])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['schedule_uuid' => 'missing_schedule'], 'int/v1/schedule-templates'))->toBe([]);
});
