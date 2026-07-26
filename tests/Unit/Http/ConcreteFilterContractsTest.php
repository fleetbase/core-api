<?php

use Fleetbase\Expansions\Builder as BuilderExpansion;
use Fleetbase\Http\Filter\ActivityFilter;
use Fleetbase\Http\Filter\ApiCredentialFilter;
use Fleetbase\Http\Filter\ApiEventFilter;
use Fleetbase\Http\Filter\ApiRequestLogFilter;
use Fleetbase\Http\Filter\CategoryFilter;
use Fleetbase\Http\Filter\ChatChannelFilter;
use Fleetbase\Http\Filter\ChatLogFilter;
use Fleetbase\Http\Filter\ChatMessageFilter;
use Fleetbase\Http\Filter\ChatReceiptFilter;
use Fleetbase\Http\Filter\CommentFilter;
use Fleetbase\Http\Filter\CompanyFilter;
use Fleetbase\Http\Filter\DashboardFilter;
use Fleetbase\Http\Filter\FileFilter;
use Fleetbase\Http\Filter\GroupFilter;
use Fleetbase\Http\Filter\NotificationFilter;
use Fleetbase\Http\Filter\PermissionFilter;
use Fleetbase\Http\Filter\PolicyFilter;
use Fleetbase\Http\Filter\RoleFilter;
use Fleetbase\Http\Filter\ScheduleExceptionFilter;
use Fleetbase\Http\Filter\ScheduleFilter;
use Fleetbase\Http\Filter\ScheduleItemFilter;
use Fleetbase\Http\Filter\ScheduleTemplateFilter;
use Fleetbase\Http\Filter\UserFilter;
use Fleetbase\Http\Filter\WebhookEndpointFilter;
use Fleetbase\Http\Filter\WebhookRequestLogFilter;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\ApiRequestLog;
use Fleetbase\Models\Category;
use Fleetbase\Models\ChatLog;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatReceipt;
use Fleetbase\Models\Comment;
use Fleetbase\Models\Company;
use Fleetbase\Models\Dashboard;
use Fleetbase\Models\File;
use Fleetbase\Models\Group;
use Fleetbase\Models\Notification;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Models\ScheduleException;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\ScheduleTemplate;
use Fleetbase\Models\User;
use Fleetbase\Models\WebhookEndpoint;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class ConcreteFilterActivityRecord extends EloquentModel
{
    protected $connection = 'mysql';
    protected $table      = 'activity_log';
    protected $guarded    = [];
    public $timestamps    = false;
}

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

class ConcreteFilterSearchBuilderFake
{
    public array $queries     = [];
    public array $searchWhere = [];

    public function search(?string $query): self
    {
        $this->queries[] = $query;

        return $this;
    }

    public function searchWhere(string $column, ?string $query): self
    {
        $this->searchWhere[] = [$column, $query];

        return $this;
    }
}

class ConcreteFilterRelationBuilderFake
{
    public array $whereHas   = [];
    public array $wheres     = [];
    public array $orWheres   = [];
    public array $whereNulls = [];

    public function where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        $this->wheres[] = [$column, $operator, $value, $boolean];

        return $this;
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        $this->orWheres[] = [$column, $operator, $value];

        return $this;
    }

    public function whereHas(string $relation, callable $callback): self
    {
        $related = new self();
        $callback($related);

        $this->whereHas[] = [
            'relation'    => $relation,
            'wheres'      => $related->wheres,
            'orWheres'    => $related->orWheres,
            'whereNulls'  => $related->whereNulls,
            'whereHas'    => $related->whereHas,
        ];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->whereNulls[] = $column;

        return $this;
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
        'api_credentials',
        'api_events',
        'api_request_logs',
        'activity_log',
        'chat_channels',
        'chat_logs',
        'chat_messages',
        'chat_participants',
        'chat_receipts',
        'comments',
        'dashboards',
        'files',
        'groups',
        'invites',
        'notifications',
        'policies',
        'roles',
        'schedule_exceptions',
        'schedule_items',
        'schedule_templates',
        'schedules',
        'users',
        'webhook_endpoints',
        'webhook_request_logs',
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
        $table->string('status')->nullable();
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
        $table->string('id')->primary();
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $schema->create('api_credentials', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->softDeletes();
    });

    $schema->create('groups', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('slug')->nullable();
        $table->softDeletes();
    });

    $schema->create('dashboards', function ($table) {
        $table->string('uuid')->primary();
        $table->string('user_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->softDeletes();
    });

    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->softDeletes();
    });

    $schema->create('webhook_endpoints', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('url')->nullable();
        $table->text('description')->nullable();
        $table->text('events')->nullable();
        $table->string('status')->nullable();
        $table->softDeletes();
    });

    $schema->create('api_request_logs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('api_credential_uuid')->nullable();
        $table->string('method')->nullable();
        $table->string('path')->nullable();
        $table->string('full_url')->nullable();
        $table->string('content_type')->nullable();
        $table->string('ip_address')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->softDeletes();
    });

    $schema->create('api_events', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('event')->nullable();
        $table->string('description')->nullable();
        $table->string('method')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->softDeletes();
    });

    $schema->create('webhook_request_logs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('api_event_uuid')->nullable();
        $table->string('method')->nullable();
        $table->string('url')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->softDeletes();
    });

    $schema->create('activity_log', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_id')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_id')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    $schema->create('chat_channels', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->softDeletes();
    });

    $schema->create('chat_messages', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('sender_uuid')->nullable();
        $table->text('content')->nullable();
        $table->softDeletes();
    });

    $schema->create('chat_logs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('initiator_uuid')->nullable();
        $table->string('event_type')->nullable();
        $table->text('content')->nullable();
        $table->softDeletes();
    });

    $schema->create('chat_participants', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->softDeletes();
    });

    $schema->create('chat_receipts', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('chat_message_uuid')->nullable();
        $table->string('participant_uuid')->nullable();
        $table->timestamp('read_at')->nullable();
        $table->softDeletes();
    });

    $schema->create('comments', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('author_uuid')->nullable();
        $table->string('parent_comment_uuid')->nullable();
        $table->text('content')->nullable();
        $table->text('tags')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $schema->create('notifications', function ($table) {
        $table->string('id')->primary();
        $table->string('uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('notifiable_type')->nullable();
        $table->string('notifiable_id')->nullable();
        $table->text('data')->nullable();
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
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

function concrete_filter_with_any_builder(object $filter, object $builder): object
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
        ['uuid' => 'company-owned', 'name' => 'Owned Co', 'owner_uuid' => 'user-1', 'country' => 'SG', 'status' => 'active', 'plan' => 'legacy', 'onboarding_completed_at' => '2026-01-01 00:00:00', 'created_at' => '2026-07-18 08:00:00'],
        ['uuid' => 'company-joined', 'name' => 'Joined Co', 'owner_uuid' => 'user-2', 'country' => 'US', 'status' => 'active', 'plan' => null, 'onboarding_completed_at' => '2026-01-01 00:00:00', 'created_at' => '2026-07-19 08:00:00'],
        ['uuid' => 'company-attention', 'name' => 'Needs Help', 'owner_uuid' => null, 'country' => 'US', 'status' => 'pending', 'plan' => null, 'onboarding_completed_at' => null, 'created_at' => '2026-07-20 08:00:00'],
        ['uuid' => 'company-hidden', 'name' => 'Hidden Co', 'owner_uuid' => 'user-3', 'country' => 'US', 'status' => 'active', 'plan' => null, 'onboarding_completed_at' => '2026-01-01 00:00:00', 'created_at' => '2026-07-21 08:00:00'],
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
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['needs_attention' => '0']))->toBe(['company-attention', 'company-hidden', 'company-joined', 'company-owned'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['missing_owner' => 'true']))->toBe(['company-attention'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['inactive_status' => 'true']))->toBe(['company-attention'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['onboarding_completed' => 'false']))->toBe(['company-attention'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['onboarding_completed' => 'true']))->toBe(['company-hidden', 'company-joined', 'company-owned'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['onboarding_completed' => '']))->toBe(['company-attention', 'company-hidden', 'company-joined', 'company-owned'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['billing_status' => 'legacy']))->toBe(['company-owned'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['billing_status' => '']))->toBe(['company-attention', 'company-hidden', 'company-joined', 'company-owned'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['owner_email' => 'member']))->toBe(['company-owned'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['created_at' => '2026-07-18']))->toBe(['company-owned'])
        ->and(concrete_filter_admin_uuids(CompanyFilter::class, Company::class, ['created_at' => '']))->toBe(['company-attention', 'company-hidden', 'company-joined', 'company-owned']);
});

test('company filter leaves admin company listing unscoped and applies searchable fields', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'Acme Logistics', 'owner_uuid' => 'user-1', 'country' => 'US', 'status' => 'active', 'plan' => 'legacy'],
        ['uuid' => 'company-2', 'name' => 'Beta Freight', 'owner_uuid' => 'user-2', 'country' => 'SG', 'status' => 'pending', 'plan' => null],
    ]);

    $request = concrete_filter_request(['view' => 'admin', 'query' => 'Freight', 'name' => 'Beta', 'country' => 'SG', 'status' => 'pending']);
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

test('company filter ignores blank controls and supports subscription billing status filters', function () {
    concrete_filter_database();

    if (!class_exists('\\Fleetbase\\Billing\\Models\\Subscription', false)) {
        eval('namespace Fleetbase\\Billing\\Models; class Subscription extends \Illuminate\Database\Eloquent\Model { public static function where(...$arguments) { return new class { public function latest(...$arguments) { return $this; } public function first() { return null; } }; } }');
    }

    $filter = concrete_filter_with_any_builder(
        new CompanyFilter(concrete_filter_request(['view' => 'admin'])),
        $builder = new ConcreteFilterRelationBuilderFake()
    );
    $filter->needsAttention(false);
    $filter->onboardingCompleted('');
    $filter->billingStatus('');
    $filter->createdAt(null);
    $filter->billingStatus('active');

    expect($builder->wheres)->toBe([])
        ->and($builder->whereHas)->toHaveCount(1)
        ->and($builder->whereHas[0]['relation'])->toBe('billingSubscriptions')
        ->and($builder->whereHas[0]['wheres'])->toBe([
            ['payment_gateway_status', 'active', null, 'and'],
        ]);
});

test('role and policy filters include organization and fleetbase managed records unless type is explicit', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('roles')->insert([
        ['id' => 'role-org', 'uuid' => 'role-org', 'company_uuid' => 'company-1', 'name' => 'Dispatcher', 'guard_name' => 'sanctum', 'service' => 'iam'],
        ['id' => 'role-flb', 'uuid' => 'role-flb', 'company_uuid' => null, 'name' => 'Administrator', 'guard_name' => 'sanctum', 'service' => 'iam'],
        ['id' => 'role-hidden', 'uuid' => 'role-hidden', 'company_uuid' => 'company-2', 'name' => 'Other', 'guard_name' => 'sanctum', 'service' => 'iam'],
    ]);
    $capsule->getConnection('mysql')->table('policies')->insert([
        ['id' => 'policy-org', 'uuid' => 'policy-org', 'company_uuid' => 'company-1', 'name' => 'DispatchPolicy', 'guard_name' => 'sanctum', 'service' => 'iam'],
        ['id' => 'policy-flb', 'uuid' => 'policy-flb', 'company_uuid' => null, 'name' => 'SystemPolicy', 'guard_name' => 'sanctum', 'service' => 'iam'],
        ['id' => 'policy-hidden', 'uuid' => 'policy-hidden', 'company_uuid' => 'company-2', 'name' => 'OtherPolicy', 'guard_name' => 'sanctum', 'service' => 'iam'],
    ]);

    expect(concrete_filter_uuids(RoleFilter::class, Role::class, [], 'int/v1/roles'))->toBe(['role-flb', 'role-org'])
        ->and(concrete_filter_uuids(RoleFilter::class, Role::class, ['type' => 'flb-managed'], 'int/v1/roles'))->toBe(['role-flb'])
        ->and(concrete_filter_uuids(RoleFilter::class, Role::class, ['type' => 'org-managed'], 'int/v1/roles'))->toBe(['role-org'])
        ->and(concrete_filter_uuids(RoleFilter::class, Role::class, ['query' => 'Dispatch'], 'int/v1/roles'))->toBe(['role-org'])
        ->and(concrete_filter_uuids(PolicyFilter::class, Policy::class, [], 'int/v1/policies'))->toBe(['policy-flb', 'policy-org'])
        ->and(concrete_filter_uuids(PolicyFilter::class, Policy::class, ['type' => 'flb-managed'], 'int/v1/policies'))->toBe(['policy-flb'])
        ->and(concrete_filter_uuids(PolicyFilter::class, Policy::class, ['type' => 'org-managed'], 'int/v1/policies'))->toBe(['policy-org'])
        ->and(concrete_filter_uuids(PolicyFilter::class, Policy::class, ['query' => 'System'], 'int/v1/policies'))->toBe(['policy-flb']);
});

test('permission filter delegates free text query to searchable builder contract', function () {
    concrete_filter_database();

    $filter = concrete_filter_with_any_builder(
        new PermissionFilter(concrete_filter_request(['query' => 'iam'])),
        $builder = new ConcreteFilterSearchBuilderFake()
    );

    expect($filter->query('iam'))->toBeNull()
        ->and($builder->queries)->toBe(['iam']);
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
        ->and(concrete_filter_uuids(UserFilter::class, User::class, [], 'v1/users'))->toBe(['user-invited', 'user-member'])
        ->and(concrete_filter_uuids(UserFilter::class, User::class, ['is_not_admin' => '1'], 'int/v1/users'))->toBe(['user-invited', 'user-member'])
        ->and(concrete_filter_uuids(UserFilter::class, User::class, ['is_user' => '1'], 'int/v1/users'))->toBe(['user-invited', 'user-member'])
        ->and(concrete_filter_uuids(UserFilter::class, User::class, ['email' => 'invited'], 'int/v1/users'))->toBe(['user-invited']);
});

test('user filter delegates search helpers and scopes role filters to the active company', function () {
    concrete_filter_database();

    $searchFilter = concrete_filter_with_any_builder(
        new UserFilter(concrete_filter_request([], 'int/v1/users')),
        $searchBuilder = new ConcreteFilterSearchBuilderFake()
    );
    $searchFilter->query('ada');
    $searchFilter->name('grace');
    $searchFilter->phone('+15550002');

    $roleFilter = concrete_filter_with_any_builder(
        new UserFilter(concrete_filter_request([], 'int/v1/users')),
        $roleBuilder = new ConcreteFilterRelationBuilderFake()
    );
    $roleFilter->role('role-dispatcher');

    expect($searchBuilder->queries)->toBe(['ada'])
        ->and($searchBuilder->searchWhere)->toBe([
            ['name', 'grace'],
            ['phone', '+15550002'],
        ])
        ->and($roleBuilder->whereHas)->toHaveCount(1)
        ->and($roleBuilder->whereHas[0]['relation'])->toBe('companyUsers')
        ->and($roleBuilder->whereHas[0]['wheres'])->toBe([
            ['company_uuid', 'company-1', null, 'and'],
        ])
        ->and($roleBuilder->whereHas[0]['whereHas'])->toHaveCount(1)
        ->and($roleBuilder->whereHas[0]['whereHas'][0]['relation'])->toBe('roles')
        ->and($roleBuilder->whereHas[0]['whereHas'][0]['wheres'])->toBe([
            ['id', 'role-dispatcher', null, 'and'],
        ]);
});

test('api request log filter scopes tenant logs by credential and date ranges', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('api_credentials')->insert([
        ['uuid' => 'credential-1', 'public_id' => 'cred_public_1', 'company_uuid' => 'company-1', 'name' => 'Primary key', 'key' => 'pk_1'],
        ['uuid' => 'credential-2', 'public_id' => 'cred_public_2', 'company_uuid' => 'company-2', 'name' => 'Other key', 'key' => 'pk_2'],
    ]);
    $capsule->getConnection('mysql')->table('api_request_logs')->insert([
        ['uuid' => 'log-1', 'public_id' => 'req_1', 'company_uuid' => 'company-1', 'api_credential_uuid' => 'credential-1', 'method' => 'GET', 'path' => '/v1/orders', 'full_url' => 'https://api.test/v1/orders', 'created_at' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 09:00:00'],
        ['uuid' => 'log-2', 'public_id' => 'req_2', 'company_uuid' => 'company-1', 'api_credential_uuid' => 'credential-1', 'method' => 'POST', 'path' => '/v1/files', 'full_url' => 'https://api.test/v1/files', 'created_at' => '2026-07-19 08:00:00', 'updated_at' => '2026-07-19 09:00:00'],
        ['uuid' => 'log-hidden', 'public_id' => 'req_hidden', 'company_uuid' => 'company-2', 'api_credential_uuid' => 'credential-2', 'method' => 'GET', 'path' => '/v1/orders', 'full_url' => 'https://api.test/v1/orders', 'created_at' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 09:00:00'],
    ]);

    expect(concrete_filter_uuids(ApiRequestLogFilter::class, ApiRequestLog::class, [], 'int/v1/api-request-logs'))->toBe(['log-1', 'log-2'])
        ->and(concrete_filter_uuids(ApiRequestLogFilter::class, ApiRequestLog::class, ['key' => 'cred_public_1'], 'int/v1/api-request-logs'))->toBe(['log-1', 'log-2'])
        ->and(concrete_filter_uuids(ApiRequestLogFilter::class, ApiRequestLog::class, ['query' => 'files'], 'int/v1/api-request-logs'))->toBe(['log-2'])
        ->and(concrete_filter_uuids(ApiRequestLogFilter::class, ApiRequestLog::class, ['query' => 'orders'], 'v1/api-request-logs'))->toBe(['log-1'])
        ->and(concrete_filter_uuids(ApiRequestLogFilter::class, ApiRequestLog::class, ['created_at' => '2026-07-19'], 'v1/api-request-logs'))->toBe(['log-2'])
        ->and(concrete_filter_uuids(ApiRequestLogFilter::class, ApiRequestLog::class, ['created_at' => '2026-07-18,2026-07-18 23:59:59'], 'int/v1/api-request-logs'))->toBe(['log-1'])
        ->and(concrete_filter_uuids(ApiRequestLogFilter::class, ApiRequestLog::class, ['updated_at' => '2026-07-18,2026-07-18 23:59:59'], 'int/v1/api-request-logs'))->toBe(['log-1'])
        ->and(concrete_filter_uuids(ApiRequestLogFilter::class, ApiRequestLog::class, ['updated_at' => '2026-07-19'], 'int/v1/api-request-logs'))->toBe(['log-2']);
});

test('simple tenant filters scope credentials groups webhooks and dashboards', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('api_credentials')->insert([
        ['uuid' => 'credential-visible', 'public_id' => 'cred_visible', 'company_uuid' => 'company-1', 'name' => 'Primary key', 'key' => 'pk_visible'],
        ['uuid' => 'credential-hidden', 'public_id' => 'cred_hidden', 'company_uuid' => 'company-2', 'name' => 'Hidden key', 'key' => 'pk_hidden'],
    ]);
    $capsule->getConnection('mysql')->table('groups')->insert([
        ['uuid' => 'group-visible', 'public_id' => 'group_visible', 'company_uuid' => 'company-1', 'name' => 'Dispatch Admins'],
        ['uuid' => 'group-hidden', 'public_id' => 'group_hidden', 'company_uuid' => 'company-2', 'name' => 'Hidden Admins'],
    ]);
    $capsule->getConnection('mysql')->table('webhook_endpoints')->insert([
        ['uuid' => 'webhook-visible', 'company_uuid' => 'company-1', 'url' => 'https://hooks.test/dispatch', 'description' => 'Dispatch webhooks', 'events' => '[]', 'status' => 'enabled'],
        ['uuid' => 'webhook-hidden', 'company_uuid' => 'company-2', 'url' => 'https://hooks.test/hidden', 'description' => 'Hidden webhooks', 'events' => '[]', 'status' => 'enabled'],
    ]);
    $capsule->getConnection('mysql')->table('dashboards')->insert([
        ['uuid' => 'dashboard-visible', 'user_uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Dispatch dashboard'],
        ['uuid' => 'dashboard-hidden', 'user_uuid' => 'user-2', 'company_uuid' => 'company-1', 'name' => 'Hidden dashboard'],
    ]);

    expect(concrete_filter_uuids(ApiCredentialFilter::class, ApiCredential::class, [], 'int/v1/api-credentials'))->toBe(['credential-visible'])
        ->and(concrete_filter_uuids(GroupFilter::class, Group::class, [], 'int/v1/groups'))->toBe(['group-visible'])
        ->and(concrete_filter_uuids(WebhookEndpointFilter::class, WebhookEndpoint::class, [], 'int/v1/webhook-endpoints'))->toBe(['webhook-visible'])
        ->and(concrete_filter_uuids(DashboardFilter::class, Dashboard::class, [], 'int/v1/dashboards'))->toBe(['dashboard-visible']);
});

test('simple tenant filters delegate free text queries to searchable builders', function () {
    $apiCredentialBuilder   = new ConcreteFilterSearchBuilderFake();
    $groupBuilder           = new ConcreteFilterSearchBuilderFake();
    $webhookEndpointBuilder = new ConcreteFilterSearchBuilderFake();

    concrete_filter_with_any_builder(new ApiCredentialFilter(concrete_filter_request([], 'int/v1/api-credentials')), $apiCredentialBuilder)->query('primary');
    concrete_filter_with_any_builder(new GroupFilter(concrete_filter_request([], 'int/v1/groups')), $groupBuilder)->query('dispatch');
    concrete_filter_with_any_builder(new WebhookEndpointFilter(concrete_filter_request([], 'int/v1/webhook-endpoints')), $webhookEndpointBuilder)->query('hooks');

    expect($apiCredentialBuilder->queries)->toBe(['primary'])
        ->and($groupBuilder->queries)->toBe(['dispatch'])
        ->and($webhookEndpointBuilder->queries)->toBe(['hooks']);
});

test('comment filter scopes subjects parent relationships and root comments', function () {
    $capsule = concrete_filter_database();
    $now     = '2026-07-18 08:00:00';

    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-subject', 'public_id' => 'user_subject', 'name' => 'Subject User', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-other', 'public_id' => 'user_other', 'name' => 'Other User', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $capsule->getConnection('mysql')->table('comments')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'comment_parent', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-subject', 'subject_type' => User::class, 'author_uuid' => 'user-subject', 'parent_comment_uuid' => null, 'content' => 'Parent', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'comment_reply', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-subject', 'subject_type' => User::class, 'author_uuid' => 'user-subject', 'parent_comment_uuid' => '11111111-1111-4111-8111-111111111111', 'content' => 'Reply', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'comment_other_subject', 'company_uuid' => 'company-1', 'subject_uuid' => 'user-other', 'subject_type' => User::class, 'author_uuid' => 'user-subject', 'parent_comment_uuid' => null, 'content' => 'Other subject', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'comment_hidden', 'company_uuid' => 'company-2', 'subject_uuid' => 'user-subject', 'subject_type' => User::class, 'author_uuid' => 'user-subject', 'parent_comment_uuid' => null, 'content' => 'Hidden', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $commentUuids = function (array $query): array {
        $builder = (new CommentFilter(concrete_filter_request($query, 'int/v1/comments')))
            ->apply(Comment::without(['author', 'replies']));

        return $builder->orderBy('uuid')->pluck('uuid')->all();
    };

    expect($commentUuids(['subject' => 'user_subject']))->toBe(['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'])
        ->and($commentUuids(['subject_uuid' => 'user-subject']))->toBe(['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'])
        ->and($commentUuids(['subject_type' => User::class]))->toBe(['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'])
        ->and($commentUuids(['parent' => '99999999-9999-4999-8999-999999999999']))->toBe([])
        ->and($commentUuids(['parent' => '22222222-2222-4222-8222-222222222222']))->toBe([])
        ->and($commentUuids(['parent' => '11111111-1111-4111-8111-111111111111']))->toBe(['22222222-2222-4222-8222-222222222222'])
        ->and($commentUuids(['without_parent' => 1]))->toBe(['11111111-1111-4111-8111-111111111111', '33333333-3333-4333-8333-333333333333']);
});

test('comment filter records relation constraints for subject and parent public ids', function () {
    $builder = new ConcreteFilterRelationBuilderFake();
    $filter  = concrete_filter_with_any_builder(new CommentFilter(concrete_filter_request()), $builder);

    $filter->subject('user_subject');
    $filter->parent('comment_1234567');

    expect($builder->whereHas[0])->toMatchArray([
        'relation' => 'subject',
        'wheres'   => [['uuid', 'user_subject', null, 'and']],
        'orWheres' => [['public_id', 'user_subject', null]],
    ])->and($builder->whereHas[1]['relation'])->toBe('parent')
        ->and($builder->wheres)->toContain(['public_id', 'comment_1234567', null, 'and']);
});

test('file filter scopes files to the active company and filters type prefixes and suffixes', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('files')->insert([
        ['uuid' => 'file-document-pdf', 'public_id' => 'file_doc_pdf', 'company_uuid' => 'company-1', 'type' => 'document-pdf'],
        ['uuid' => 'file-document-csv', 'public_id' => 'file_doc_csv', 'company_uuid' => 'company-1', 'type' => 'document-csv'],
        ['uuid' => 'file-image-png', 'public_id' => 'file_image_png', 'company_uuid' => 'company-1', 'type' => 'image-png'],
        ['uuid' => 'file-hidden-pdf', 'public_id' => 'file_hidden_pdf', 'company_uuid' => 'company-2', 'type' => 'document-pdf'],
    ]);

    expect(concrete_filter_uuids(FileFilter::class, File::class, [], 'int/v1/files'))->toBe(['file-document-csv', 'file-document-pdf', 'file-image-png'])
        ->and(concrete_filter_uuids(FileFilter::class, File::class, [], 'v1/files'))->toBe(['file-document-csv', 'file-document-pdf', 'file-image-png'])
        ->and(concrete_filter_uuids(FileFilter::class, File::class, ['type_ends_with' => 'pdf'], 'int/v1/files'))->toBe(['file-document-pdf'])
        ->and(concrete_filter_uuids(FileFilter::class, File::class, ['type_starts_with' => 'document'], 'int/v1/files'))->toBe(['file-document-csv', 'file-document-pdf']);
});

test('api event and webhook request log filters scope tenant logs and date windows', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('api_events')->insert([
        ['uuid' => 'event-1', 'public_id' => 'event_1', 'company_uuid' => 'company-1', 'event' => 'order.created', 'description' => 'Order created', 'method' => 'POST', 'created_at' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 09:00:00'],
        ['uuid' => 'event-2', 'public_id' => 'event_2', 'company_uuid' => 'company-1', 'event' => 'file.uploaded', 'description' => 'File uploaded', 'method' => 'POST', 'created_at' => '2026-07-19 08:00:00', 'updated_at' => '2026-07-19 09:00:00'],
        ['uuid' => 'event-hidden', 'public_id' => 'event_hidden', 'company_uuid' => 'company-2', 'event' => 'order.created', 'description' => 'Other order', 'method' => 'POST', 'created_at' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 09:00:00'],
    ]);
    $capsule->getConnection('mysql')->table('webhook_request_logs')->insert([
        ['uuid' => 'webhook-log-1', 'public_id' => 'webhook_req_1', 'company_uuid' => 'company-1', 'api_event_uuid' => 'event-1', 'method' => 'POST', 'url' => 'https://hooks.test/orders', 'created_at' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 09:00:00'],
        ['uuid' => 'webhook-log-2', 'public_id' => 'webhook_req_2', 'company_uuid' => 'company-1', 'api_event_uuid' => 'event-2', 'method' => 'POST', 'url' => 'https://hooks.test/files', 'created_at' => '2026-07-19 08:00:00', 'updated_at' => '2026-07-19 09:00:00'],
        ['uuid' => 'webhook-log-hidden', 'public_id' => 'webhook_req_hidden', 'company_uuid' => 'company-2', 'api_event_uuid' => 'event-hidden', 'method' => 'POST', 'url' => 'https://hooks.test/orders', 'created_at' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 09:00:00'],
    ]);

    expect(concrete_filter_uuids(ApiEventFilter::class, ApiEvent::class, [], 'int/v1/api-events'))->toBe(['event-1', 'event-2'])
        ->and(concrete_filter_uuids(ApiEventFilter::class, ApiEvent::class, [], 'v1/api-events'))->toBe(['event-1', 'event-2'])
        ->and(concrete_filter_uuids(ApiEventFilter::class, ApiEvent::class, ['query' => 'uploaded'], 'int/v1/api-events'))->toBe(['event-2'])
        ->and(concrete_filter_uuids(ApiEventFilter::class, ApiEvent::class, ['created_at' => '2026-07-18,2026-07-18 23:59:59'], 'int/v1/api-events'))->toBe(['event-1'])
        ->and(concrete_filter_uuids(ApiEventFilter::class, ApiEvent::class, ['created_at' => '2026-07-19'], 'int/v1/api-events'))->toBe(['event-2'])
        ->and(concrete_filter_uuids(ApiEventFilter::class, ApiEvent::class, ['updated_at' => '2026-07-19'], 'int/v1/api-events'))->toBe(['event-2'])
        ->and(concrete_filter_uuids(ApiEventFilter::class, ApiEvent::class, ['updated_at' => '2026-07-18,2026-07-18 23:59:59'], 'int/v1/api-events'))->toBe(['event-1'])
        ->and(concrete_filter_uuids(WebhookRequestLogFilter::class, Fleetbase\Models\WebhookRequestLog::class, [], 'int/v1/webhook-request-logs'))->toBe(['webhook-log-1', 'webhook-log-2'])
        ->and(concrete_filter_uuids(WebhookRequestLogFilter::class, Fleetbase\Models\WebhookRequestLog::class, ['query' => 'files'], 'int/v1/webhook-request-logs'))->toBe(['webhook-log-1', 'webhook-log-2'])
        ->and(concrete_filter_uuids(WebhookRequestLogFilter::class, Fleetbase\Models\WebhookRequestLog::class, ['created_at' => '2026-07-18,2026-07-18 23:59:59'], 'int/v1/webhook-request-logs'))->toBe(['webhook-log-1'])
        ->and(concrete_filter_uuids(WebhookRequestLogFilter::class, Fleetbase\Models\WebhookRequestLog::class, ['created_at' => '2026-07-19'], 'int/v1/webhook-request-logs'))->toBe(['webhook-log-2'])
        ->and(concrete_filter_uuids(WebhookRequestLogFilter::class, Fleetbase\Models\WebhookRequestLog::class, ['updated_at' => '2026-07-18,2026-07-18 23:59:59'], 'int/v1/webhook-request-logs'))->toBe(['webhook-log-1'])
        ->and(concrete_filter_uuids(WebhookRequestLogFilter::class, Fleetbase\Models\WebhookRequestLog::class, ['updated_at' => '2026-07-19'], 'int/v1/webhook-request-logs'))->toBe(['webhook-log-2']);
});

test('activity filter respects admin override and subject causer constraints', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('activity_log')->insert([
        ['uuid' => 'activity-1', 'company_id' => 'company-1', 'subject_id' => 'order-1', 'causer_id' => 'user-1', 'created_at' => '2026-07-18 08:00:00'],
        ['uuid' => 'activity-2', 'company_id' => 'company-1', 'subject_id' => 'order-2', 'causer_id' => 'user-2', 'created_at' => '2026-07-19 08:00:00'],
        ['uuid' => 'activity-hidden', 'company_id' => 'company-2', 'subject_id' => 'order-1', 'causer_id' => 'user-3', 'created_at' => '2026-07-18 08:00:00'],
    ]);

    $adminRequest = concrete_filter_request(['company_uuid' => 'company-2'], 'int/v1/activities');
    $adminRequest->setUserResolver(fn () => new class {
        public function isAdmin(): bool
        {
            return true;
        }
    });

    $adminMatches = (new ActivityFilter($adminRequest))
        ->apply(ConcreteFilterActivityRecord::query())
        ->orderBy('uuid')
        ->pluck('uuid')
        ->all();

    expect(concrete_filter_uuids(ActivityFilter::class, ConcreteFilterActivityRecord::class, [], 'int/v1/activities'))->toBe(['activity-1', 'activity-2'])
        ->and(concrete_filter_uuids(ActivityFilter::class, ConcreteFilterActivityRecord::class, [], 'v1/activities'))->toBe(['activity-1', 'activity-2'])
        ->and(concrete_filter_uuids(ActivityFilter::class, ConcreteFilterActivityRecord::class, ['subject_id' => 'order-1'], 'int/v1/activities'))->toBe(['activity-1'])
        ->and(concrete_filter_uuids(ActivityFilter::class, ConcreteFilterActivityRecord::class, ['causer_id' => 'user-2'], 'int/v1/activities'))->toBe(['activity-2'])
        ->and(concrete_filter_uuids(ActivityFilter::class, ConcreteFilterActivityRecord::class, ['created_at' => '2026-07-18,2026-07-18 23:59:59'], 'int/v1/activities'))->toBe(['activity-1'])
        ->and(concrete_filter_uuids(ActivityFilter::class, ConcreteFilterActivityRecord::class, ['created_at' => '2026-07-19'], 'int/v1/activities'))->toBe(['activity-2'])
        ->and($adminMatches)->toBe(['activity-hidden']);
});

test('chat receipt filter limits receipts to channels that include current user', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-1', 'name' => 'Visible Participant', 'email' => 'visible@example.test', 'type' => 'user'],
        ['uuid' => 'user-2', 'name' => 'Other Participant', 'email' => 'other@example.test', 'type' => 'user'],
    ]);
    $capsule->getConnection('mysql')->table('chat_channels')->insert([
        ['uuid' => 'channel-1', 'company_uuid' => 'company-1', 'deleted_at' => null],
        ['uuid' => 'channel-2', 'company_uuid' => 'company-1', 'deleted_at' => null],
    ]);
    $capsule->getConnection('mysql')->table('chat_messages')->insert([
        ['uuid' => 'message-1', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-1', 'deleted_at' => null],
        ['uuid' => 'message-2', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-2', 'deleted_at' => null],
    ]);
    $capsule->getConnection('mysql')->table('chat_participants')->insert([
        ['uuid' => 'participant-current', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-1', 'user_uuid' => 'user-1', 'deleted_at' => null],
        ['uuid' => 'participant-other', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-2', 'user_uuid' => 'user-2', 'deleted_at' => null],
    ]);
    $capsule->getConnection('mysql')->table('chat_receipts')->insert([
        ['uuid' => 'receipt-visible', 'company_uuid' => 'company-1', 'chat_message_uuid' => 'message-1', 'participant_uuid' => 'participant-current', 'read_at' => '2026-07-18 08:00:00', 'deleted_at' => null],
        ['uuid' => 'receipt-hidden', 'company_uuid' => 'company-1', 'chat_message_uuid' => 'message-2', 'participant_uuid' => 'participant-other', 'read_at' => '2026-07-18 08:00:00', 'deleted_at' => null],
        ['uuid' => 'receipt-other-company', 'company_uuid' => 'company-2', 'chat_message_uuid' => 'message-1', 'participant_uuid' => 'participant-current', 'read_at' => '2026-07-18 08:00:00', 'deleted_at' => null],
    ]);

    $searchFilter = concrete_filter_with_any_builder(
        new ChatReceiptFilter(concrete_filter_request([], 'v1/chat-receipts')),
        $searchBuilder = new ConcreteFilterSearchBuilderFake()
    );
    $searchFilter->query('receipt-visible');

    expect(concrete_filter_uuids(ChatReceiptFilter::class, ChatReceipt::class, [], 'int/v1/chat-receipts'))->toBe(['receipt-visible'])
        ->and(concrete_filter_uuids(ChatReceiptFilter::class, ChatReceipt::class, [], 'v1/chat-receipts'))->toBe(['receipt-visible'])
        ->and($searchBuilder->queries)->toBe(['receipt-visible']);
});

test('chat message and log filters expose only tenant channels with current user participation', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-1', 'name' => 'Visible Participant', 'email' => 'visible@example.test', 'type' => 'user'],
        ['uuid' => 'user-2', 'name' => 'Other Participant', 'email' => 'other@example.test', 'type' => 'user'],
    ]);
    $capsule->getConnection('mysql')->table('chat_channels')->insert([
        ['uuid' => 'channel-visible', 'company_uuid' => 'company-1', 'deleted_at' => null],
        ['uuid' => 'channel-hidden', 'company_uuid' => 'company-1', 'deleted_at' => null],
        ['uuid' => 'channel-other-company', 'company_uuid' => 'company-2', 'deleted_at' => null],
    ]);
    $capsule->getConnection('mysql')->table('chat_participants')->insert([
        ['uuid' => 'participant-current', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-visible', 'user_uuid' => 'user-1', 'deleted_at' => null],
        ['uuid' => 'participant-other', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-hidden', 'user_uuid' => 'user-2', 'deleted_at' => null],
        ['uuid' => 'participant-other-company', 'company_uuid' => 'company-2', 'chat_channel_uuid' => 'channel-other-company', 'user_uuid' => 'user-1', 'deleted_at' => null],
    ]);
    $capsule->getConnection('mysql')->table('chat_messages')->insert([
        ['uuid' => 'message-visible', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-visible', 'sender_uuid' => 'participant-current', 'content' => 'visible', 'deleted_at' => null],
        ['uuid' => 'message-hidden', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-hidden', 'sender_uuid' => 'participant-other', 'content' => 'hidden', 'deleted_at' => null],
        ['uuid' => 'message-other-company', 'company_uuid' => 'company-2', 'chat_channel_uuid' => 'channel-other-company', 'sender_uuid' => 'participant-other-company', 'content' => 'other company', 'deleted_at' => null],
    ]);
    $capsule->getConnection('mysql')->table('chat_logs')->insert([
        ['uuid' => 'log-visible', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-visible', 'initiator_uuid' => 'participant-current', 'event_type' => 'message_sent', 'content' => 'visible log', 'deleted_at' => null],
        ['uuid' => 'log-hidden', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-hidden', 'initiator_uuid' => 'participant-other', 'event_type' => 'message_sent', 'content' => 'hidden log', 'deleted_at' => null],
        ['uuid' => 'log-other-company', 'company_uuid' => 'company-2', 'chat_channel_uuid' => 'channel-other-company', 'initiator_uuid' => 'participant-other-company', 'event_type' => 'message_sent', 'content' => 'other company log', 'deleted_at' => null],
    ]);

    expect(concrete_filter_uuids(ChatMessageFilter::class, ChatMessage::class, [], 'int/v1/chat-messages'))->toBe(['message-visible'])
        ->and(concrete_filter_uuids(ChatMessageFilter::class, ChatMessage::class, [], 'v1/chat-messages'))->toBe(['message-visible'])
        ->and(concrete_filter_uuids(ChatLogFilter::class, ChatLog::class, [], 'int/v1/chat-logs'))->toBe(['log-visible'])
        ->and(concrete_filter_uuids(ChatLogFilter::class, ChatLog::class, [], 'v1/chat-logs'))->toBe(['log-visible']);
});

test('chat filters delegate free text queries to searchable builders', function () {
    $builder = new ConcreteFilterSearchBuilderFake();
    $filter  = concrete_filter_with_any_builder(new ChatChannelFilter(concrete_filter_request([], 'int/v1/chat-channels')), $builder);

    $filter->query('dispatch');

    $messageBuilder = new ConcreteFilterSearchBuilderFake();
    $messageFilter  = concrete_filter_with_any_builder(new ChatMessageFilter(concrete_filter_request([], 'int/v1/chat-messages')), $messageBuilder);

    $messageFilter->query('pickup');

    $logBuilder = new ConcreteFilterSearchBuilderFake();
    $logFilter  = concrete_filter_with_any_builder(new ChatLogFilter(concrete_filter_request([], 'int/v1/chat-logs')), $logBuilder);

    $logFilter->query('delivered');

    expect($builder->queries)->toBe(['dispatch'])
        ->and($messageBuilder->queries)->toBe(['pickup'])
        ->and($logBuilder->queries)->toBe(['delivered']);
});

test('category filter applies tenant core parent and list filters', function () {
    $capsule    = concrete_filter_database();
    $parentUuid = '11111111-1111-4111-8111-111111111111';
    $capsule->getConnection('mysql')->table('categories')->insert([
        ['uuid' => $parentUuid, 'public_id' => 'category_parent', 'company_uuid' => 'company-1', 'parent_uuid' => null, 'name' => 'Parent', 'for' => 'order', 'core_category' => false],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'category_child', 'company_uuid' => 'company-1', 'parent_uuid' => $parentUuid, 'name' => 'Child', 'for' => 'order', 'core_category' => false],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'category_core', 'company_uuid' => null, 'parent_uuid' => null, 'name' => 'Core', 'for' => 'shipment', 'core_category' => true],
        ['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'category_hidden', 'company_uuid' => 'company-2', 'parent_uuid' => null, 'name' => 'Hidden', 'for' => 'order', 'core_category' => false],
    ]);

    expect(concrete_filter_uuids(CategoryFilter::class, Category::class, [], 'int/v1/categories'))->toBe(['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, [], 'v1/categories'))->toBe(['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['parents_only' => '1'], 'int/v1/categories'))->toBe(['11111111-1111-4111-8111-111111111111'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['core_category' => '1'], 'int/v1/categories'))->toBe(['33333333-3333-4333-8333-333333333333'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['parent_category' => $parentUuid], 'int/v1/categories'))->toBe(['22222222-2222-4222-8222-222222222222'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['parent_category' => 'category_parent'], 'int/v1/categories'))->toBe(['22222222-2222-4222-8222-222222222222'])
        ->and(concrete_filter_uuids(CategoryFilter::class, Category::class, ['for' => 'shipment'], 'int/v1/categories'))->toBe([]);
});

test('schedule item filter resolves schedule identifiers and date ranges within tenant scope', function () {
    $capsule         = concrete_filter_database();
    $rawScheduleUuid = '11111111-1111-4111-8111-111111111111';

    $capsule->getConnection('mysql')->table('schedules')->insert([
        ['uuid' => 'schedule-1', 'public_id' => 'schedule_public_1', 'company_uuid' => 'company-1'],
        ['uuid' => $rawScheduleUuid, 'public_id' => 'schedule_public_uuid', 'company_uuid' => 'company-1'],
        ['uuid' => 'schedule-2', 'public_id' => 'schedule_public_2', 'company_uuid' => 'company-2'],
    ]);
    $capsule->getConnection('mysql')->table('schedule_items')->insert([
        ['uuid' => 'item-direct', 'company_uuid' => 'company-1', 'schedule_uuid' => 'schedule-1', 'assignee_uuid' => 'driver-1', 'assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'start_at' => '2026-07-18 08:00:00', 'end_at' => '2026-07-18 12:00:00'],
        ['uuid' => 'item-fallback', 'company_uuid' => null, 'schedule_uuid' => 'schedule-1', 'assignee_uuid' => 'driver-2', 'assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'start_at' => '2026-07-19 08:00:00', 'end_at' => '2026-07-19 12:00:00'],
        ['uuid' => 'item-uuid', 'company_uuid' => 'company-1', 'schedule_uuid' => $rawScheduleUuid, 'assignee_uuid' => 'driver-3', 'assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'start_at' => '2026-07-20 08:00:00', 'end_at' => '2026-07-20 12:00:00'],
        ['uuid' => 'item-hidden', 'company_uuid' => 'company-2', 'schedule_uuid' => 'schedule-2', 'assignee_uuid' => 'driver-1', 'assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'start_at' => '2026-07-18 08:00:00', 'end_at' => '2026-07-18 12:00:00'],
    ]);

    $rangeBuilder = ScheduleItem::query();
    $rangeFilter  = concrete_filter_with_builder(
        new ScheduleItemFilter(concrete_filter_request([], 'int/v1/schedule-items')),
        $rangeBuilder
    );
    $rangeFilter->startAtBetween('2026-07-19 00:00:00', '2026-07-19 23:59:59');
    $rangeFilter->endAtBetween(null, '2026-07-19 23:59:59');
    $rangeMatches = $rangeBuilder
        ->pluck('uuid')
        ->all();

    expect(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, [], 'int/v1/schedule-items'))->toBe(['item-direct', 'item-fallback', 'item-uuid'])
        ->and(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, [], 'v1/schedule-items'))->toBe(['item-direct', 'item-fallback', 'item-uuid'])
        ->and(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, ['schedule_uuid' => 'schedule_public_1'], 'int/v1/schedule-items'))->toBe(['item-direct', 'item-fallback'])
        ->and(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, ['schedule_uuid' => $rawScheduleUuid], 'int/v1/schedule-items'))->toBe(['item-uuid'])
        ->and(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, ['schedule_uuid' => 'missing_schedule'], 'int/v1/schedule-items'))->toBe([])
        ->and(concrete_filter_uuids(ScheduleItemFilter::class, ScheduleItem::class, ['assignee_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'assignee_uuid' => 'driver-1'], 'int/v1/schedule-items'))->toBe(['item-direct'])
        ->and($rangeMatches)->toBe(['item-fallback']);
});

test('schedule item filter ignores blank identifiers and resolves assignee aliases with range lower bounds', function () {
    concrete_filter_database();

    $filter = concrete_filter_with_any_builder(
        new ScheduleItemFilter(concrete_filter_request([], 'int/v1/schedule-items')),
        $builder = new ConcreteFilterRelationBuilderFake()
    );
    $filter->scheduleUuid('');
    $filter->assigneeType('');
    $filter->assigneeUuid('');
    $filter->assigneeType('fleet-ops:driver');
    $filter->endAtBetween('2026-07-20 00:00:00', null);

    expect($builder->wheres)->toBe([
        ['assignee_type', 'Fleetbase\\FleetOps\\Models\\Driver', null, 'and'],
        ['end_at', '>=', '2026-07-20 00:00:00', 'and'],
    ]);
});

test('schedule filter scopes tenant schedules and applies subject and status filters', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->table('schedules')->insert([
        ['uuid' => 'schedule-active', 'public_id' => 'schedule_active', 'company_uuid' => 'company-1', 'subject_uuid' => 'driver-1', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'status' => 'active'],
        ['uuid' => 'schedule-paused', 'public_id' => 'schedule_paused', 'company_uuid' => 'company-1', 'subject_uuid' => 'driver-2', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'status' => 'paused'],
        ['uuid' => 'schedule-hidden', 'public_id' => 'schedule_hidden', 'company_uuid' => 'company-2', 'subject_uuid' => 'driver-1', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'status' => 'active'],
        ['uuid' => 'schedule-alias', 'public_id' => 'schedule_alias', 'company_uuid' => 'company-1', 'subject_uuid' => 'vehicle-1', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Vehicle', 'status' => 'active'],
    ]);

    $emptyBuilder = Fleetbase\Models\Schedule::query();
    $emptyFilter  = concrete_filter_with_builder(
        new ScheduleFilter(concrete_filter_request([], 'int/v1/schedules')),
        $emptyBuilder
    );
    $emptyFilter->subjectType(null);
    $emptyFilter->subjectUuid(null);
    $emptyFilter->status(null);

    expect(concrete_filter_uuids(ScheduleFilter::class, Fleetbase\Models\Schedule::class, [], 'int/v1/schedules'))->toBe(['schedule-active', 'schedule-alias', 'schedule-paused'])
        ->and(concrete_filter_uuids(ScheduleFilter::class, Fleetbase\Models\Schedule::class, [], 'v1/schedules'))->toBe(['schedule-active', 'schedule-alias', 'schedule-paused'])
        ->and(concrete_filter_uuids(ScheduleFilter::class, Fleetbase\Models\Schedule::class, ['subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'], 'int/v1/schedules'))->toBe(['schedule-active', 'schedule-paused'])
        ->and(concrete_filter_uuids(ScheduleFilter::class, Fleetbase\Models\Schedule::class, ['subject_uuid' => 'driver-1'], 'int/v1/schedules'))->toBe(['schedule-active'])
        ->and(concrete_filter_uuids(ScheduleFilter::class, Fleetbase\Models\Schedule::class, ['status' => 'paused'], 'int/v1/schedules'))->toBe(['schedule-paused'])
        ->and(concrete_filter_uuids(ScheduleFilter::class, Fleetbase\Models\Schedule::class, ['subject_type' => 'fleet-ops:vehicle'], 'int/v1/schedules'))->toBe(['schedule-alias'])
        ->and(concrete_filter_uuids(ScheduleFilter::class, Fleetbase\Models\Schedule::class, ['subject_type' => 'unknown:subject'], 'int/v1/schedules'))->toBe([])
        ->and($emptyBuilder->orderBy('uuid')->pluck('uuid')->all())->toBe(['schedule-active', 'schedule-alias', 'schedule-hidden', 'schedule-paused']);
});

test('schedule exception and template filters scope tenants and resolve subjects and schedules', function () {
    $capsule            = concrete_filter_database();
    $scheduleUuid       = '11111111-1111-4111-8111-111111111111';
    $hiddenScheduleUuid = '22222222-2222-4222-8222-222222222222';
    $capsule->getConnection('mysql')->table('schedules')->insert([
        ['uuid' => $scheduleUuid, 'public_id' => 'schedule_public_1', 'company_uuid' => 'company-1'],
        ['uuid' => $hiddenScheduleUuid, 'public_id' => 'schedule_public_2', 'company_uuid' => 'company-2'],
    ]);
    $capsule->getConnection('mysql')->table('schedule_exceptions')->insert([
        ['uuid' => 'exception-1', 'company_uuid' => 'company-1', 'schedule_uuid' => $scheduleUuid, 'subject_uuid' => 'driver-1', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'],
        ['uuid' => 'exception-hidden', 'company_uuid' => 'company-2', 'schedule_uuid' => $hiddenScheduleUuid, 'subject_uuid' => 'driver-2', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'],
    ]);
    $capsule->getConnection('mysql')->table('schedule_templates')->insert([
        ['uuid' => 'template-1', 'company_uuid' => 'company-1', 'schedule_uuid' => $scheduleUuid, 'subject_uuid' => 'driver-1', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'],
        ['uuid' => 'template-hidden', 'company_uuid' => 'company-2', 'schedule_uuid' => $hiddenScheduleUuid, 'subject_uuid' => 'driver-2', 'subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver'],
    ]);

    $emptyExceptionBuilder = ScheduleException::query();
    $emptyExceptionFilter  = concrete_filter_with_builder(
        new ScheduleExceptionFilter(concrete_filter_request([], 'int/v1/schedule-exceptions')),
        $emptyExceptionBuilder
    );
    $emptyExceptionFilter->scheduleUuid(null);
    $emptyExceptionFilter->subjectType(null);
    $emptyExceptionFilter->subjectUuid(null);

    $emptyTemplateBuilder = ScheduleTemplate::query();
    $emptyTemplateFilter  = concrete_filter_with_builder(
        new ScheduleTemplateFilter(concrete_filter_request([], 'int/v1/schedule-templates')),
        $emptyTemplateBuilder
    );
    $emptyTemplateFilter->scheduleUuid(null);
    $emptyTemplateFilter->subjectType(null);
    $emptyTemplateFilter->subjectUuid(null);

    expect(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['schedule_uuid' => 'schedule_public_1'], 'int/v1/schedule-exceptions'))->toBe(['exception-1'])
        ->and(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['schedule_uuid' => $scheduleUuid], 'int/v1/schedule-exceptions'))->toBe(['exception-1'])
        ->and(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['schedule_uuid' => $scheduleUuid], 'v1/schedule-exceptions'))->toBe(['exception-1'])
        ->and(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'subject_uuid' => 'driver-1'], 'int/v1/schedule-exceptions'))->toBe(['exception-1'])
        ->and(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['subject_type' => 'unknown:driver'], 'int/v1/schedule-exceptions'))->toBe([])
        ->and(concrete_filter_uuids(ScheduleExceptionFilter::class, ScheduleException::class, ['schedule_uuid' => 'missing_schedule'], 'int/v1/schedule-exceptions'))->toBe([])
        ->and($emptyExceptionBuilder->orderBy('uuid')->pluck('uuid')->all())->toBe(['exception-1', 'exception-hidden'])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['schedule_uuid' => 'schedule_public_1'], 'int/v1/schedule-templates'))->toBe(['template-1'])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['schedule_uuid' => $scheduleUuid], 'int/v1/schedule-templates'))->toBe(['template-1'])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['schedule_uuid' => $scheduleUuid], 'v1/schedule-templates'))->toBe(['template-1'])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['subject_type' => 'Fleetbase\\FleetOps\\Models\\Driver', 'subject_uuid' => 'driver-1'], 'int/v1/schedule-templates'))->toBe(['template-1'])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['subject_type' => 'unknown:driver'], 'int/v1/schedule-templates'))->toBe([])
        ->and(concrete_filter_uuids(ScheduleTemplateFilter::class, ScheduleTemplate::class, ['schedule_uuid' => 'missing_schedule'], 'int/v1/schedule-templates'))->toBe([])
        ->and($emptyTemplateBuilder->orderBy('uuid')->pluck('uuid')->all())->toBe(['template-1', 'template-hidden']);
});

test('notification filter limits internal results to current user or company and supports unread search', function () {
    $capsule = concrete_filter_database();
    $capsule->getConnection('mysql')->getPdo()->sqliteCreateFunction('json_unquote', fn ($value) => $value, 1);
    $capsule->getConnection('mysql')->table('notifications')->insert([
        ['id' => 'notification-company-unread', 'uuid' => 'notification-company-unread', 'type' => 'alert', 'notifiable_type' => Company::class, 'notifiable_id' => 'company-1', 'data' => '{"message":"Dispatch exception raised"}', 'read_at' => null],
        ['id' => 'notification-user-read', 'uuid' => 'notification-user-read', 'type' => 'alert', 'notifiable_type' => User::class, 'notifiable_id' => 'user-1', 'data' => '{"message":"Welcome back"}', 'read_at' => '2026-07-18 08:00:00'],
        ['id' => 'notification-hidden', 'uuid' => 'notification-hidden', 'type' => 'alert', 'notifiable_type' => Company::class, 'notifiable_id' => 'company-2', 'data' => '{"message":"Dispatch exception raised"}', 'read_at' => null],
    ]);

    expect(concrete_filter_uuids(NotificationFilter::class, Notification::class, [], 'int/v1/notifications'))->toBe([
        'notification-company-unread',
        'notification-user-read',
    ])
        ->and(concrete_filter_uuids(NotificationFilter::class, Notification::class, ['unread' => '1'], 'int/v1/notifications'))->toBe([
            'notification-company-unread',
        ])
        ->and(concrete_filter_uuids(NotificationFilter::class, Notification::class, ['query' => 'dispatch'], 'int/v1/notifications'))->toBe([
            'notification-company-unread',
        ]);
});
