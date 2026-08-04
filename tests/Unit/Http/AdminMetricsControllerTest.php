<?php

use Fleetbase\Http\Controllers\Internal\v1\AdminMetricsController;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function admin_metrics_database(bool $includeFailedJobsTable = true, bool $includeActivityLogTable = true): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'                       => false,
        'database.default'                        => 'mysql',
        'database.connections.mysql'              => $connection,
        'fleetbase.connection.db'                 => 'mysql',
        'activitylog.table_name'                  => 'activity_log',
        'mail.default'                            => null,
        'filesystems.default'                     => 's3',
        'queue.default'                           => null,
        'broadcasting.default'                    => 'socketcluster',
        'fleetbase.notifications.default_channel' => null,
        'schedule-monitor.enabled'                => false,
    ]);

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
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstance('db.schema');
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('onboarding_completed_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    if ($includeFailedJobsTable) {
        $schema->create('failed_jobs', function ($table) {
            $table->increments('id');
            $table->timestamp('failed_at')->nullable();
        });
    }

    if ($includeActivityLogTable) {
        $schema->create('activity_log', function ($table) {
            $table->increments('id');
            $table->string('log_name')->nullable();
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->string('event')->nullable();
            $table->text('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    return $capsule;
}

function admin_metrics_seed(Capsule $capsule): void
{
    $db = $capsule->getConnection('mysql');

    $db->table('users')->insert([
        ['uuid' => 'user-current', 'public_id' => 'user_current', 'name' => 'Current User', 'type' => 'user', 'status' => 'active', 'created_at' => '2026-07-10 08:00:00', 'updated_at' => '2026-07-10 08:00:00', 'deleted_at' => null],
        ['uuid' => 'user-previous', 'public_id' => 'user_previous', 'name' => 'Previous User', 'type' => 'user', 'status' => 'active', 'created_at' => '2026-06-01 08:00:00', 'updated_at' => '2026-06-01 08:00:00', 'deleted_at' => null],
        ['uuid' => 'admin-current', 'public_id' => 'admin_current', 'name' => 'Current Admin', 'type' => 'admin', 'status' => 'active', 'created_at' => '2026-07-01 08:00:00', 'updated_at' => '2026-07-01 08:00:00', 'deleted_at' => null],
        ['uuid' => 'admin-implicit-active', 'public_id' => 'admin_null', 'name' => 'Implicit Admin', 'type' => 'admin', 'status' => null, 'created_at' => '2026-07-05 08:00:00', 'updated_at' => '2026-07-05 08:00:00', 'deleted_at' => null],
        ['uuid' => 'admin-inactive', 'public_id' => 'admin_inactive', 'name' => 'Inactive Admin', 'type' => 'admin', 'status' => 'inactive', 'created_at' => '2026-07-06 08:00:00', 'updated_at' => '2026-07-06 08:00:00', 'deleted_at' => null],
    ]);

    $db->table('companies')->insert([
        ['uuid' => 'company-current', 'public_id' => 'company_current', 'name' => 'Current Org', 'owner_uuid' => 'admin-current', 'status' => 'active', 'onboarding_completed_at' => '2026-07-12 08:00:00', 'created_at' => '2026-07-10 08:00:00', 'updated_at' => '2026-07-12 08:00:00', 'deleted_at' => null],
        ['uuid' => 'company-previous', 'public_id' => 'company_previous', 'name' => 'Previous Org', 'owner_uuid' => 'admin-current', 'status' => 'active', 'onboarding_completed_at' => '2026-06-01 08:00:00', 'created_at' => '2026-06-01 08:00:00', 'updated_at' => '2026-06-01 08:00:00', 'deleted_at' => null],
        ['uuid' => 'company-missing-owner', 'public_id' => 'company_missing_owner', 'name' => 'Missing Owner Org', 'owner_uuid' => null, 'status' => 'active', 'onboarding_completed_at' => '2026-07-01 08:00:00', 'created_at' => '2026-07-01 08:00:00', 'updated_at' => '2026-07-01 08:00:00', 'deleted_at' => null],
        ['uuid' => 'company-onboarding', 'public_id' => 'company_onboarding', 'name' => 'Onboarding Org', 'owner_uuid' => 'admin-current', 'status' => 'active', 'onboarding_completed_at' => null, 'created_at' => '2026-07-08 08:00:00', 'updated_at' => '2026-07-08 08:00:00', 'deleted_at' => null],
        ['uuid' => 'company-suspended', 'public_id' => 'company_suspended', 'name' => 'Suspended Org', 'owner_uuid' => 'admin-current', 'status' => 'suspended', 'onboarding_completed_at' => '2026-07-09 08:00:00', 'created_at' => '2026-07-09 08:00:00', 'updated_at' => '2026-07-09 08:00:00', 'deleted_at' => null],
    ]);

    if ($db->getSchemaBuilder()->hasTable('failed_jobs')) {
        $db->table('failed_jobs')->insert([
            ['failed_at' => '2026-07-17 12:00:00'],
            ['failed_at' => '2026-06-01 12:00:00'],
        ]);
    }

    if ($db->getSchemaBuilder()->hasTable('activity_log')) {
        $db->table('activity_log')->insert([
            ['log_name' => 'default', 'description' => 'admin impersonated user', 'subject_type' => User::class, 'subject_id' => 'user-current', 'causer_type' => null, 'causer_id' => null, 'event' => 'impersonated', 'properties' => '{}', 'batch_uuid' => null, 'created_at' => '2026-07-17 09:00:00', 'updated_at' => '2026-07-17 09:00:00'],
            ['log_name' => 'default', 'description' => 'password reset requested', 'subject_type' => Company::class, 'subject_id' => 'company-current', 'causer_type' => null, 'causer_id' => null, 'event' => 'password.reset', 'properties' => '{}', 'batch_uuid' => null, 'created_at' => '2026-06-01 09:00:00', 'updated_at' => '2026-06-01 09:00:00'],
            ['log_name' => 'default', 'description' => 'ordinary update', 'subject_type' => Company::class, 'subject_id' => 'company-current', 'causer_type' => null, 'causer_id' => null, 'event' => 'updated', 'properties' => '{}', 'batch_uuid' => null, 'created_at' => '2026-07-17 10:00:00', 'updated_at' => '2026-07-17 10:00:00'],
        ]);
    }
}

function admin_metrics_controller(bool $includeFailedJobsTable = true, bool $includeActivityLogTable = true): AdminMetricsController
{
    $capsule = admin_metrics_database($includeFailedJobsTable, $includeActivityLogTable);
    admin_metrics_seed($capsule);

    return new AdminMetricsController();
}

function admin_metrics_request(): Request
{
    return Request::create('/int/v1/metrics/admin', 'GET');
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    config([
        'activitylog.table_name'                  => 'activities',
        'mail.default'                            => null,
        'filesystems.default'                     => null,
        'queue.default'                           => null,
        'broadcasting.default'                    => null,
        'fleetbase.notifications.default_channel' => null,
        'schedule-monitor.enabled'                => true,
    ]);
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('admin metrics kpis expose totals deltas statuses and unknown metric errors', function () {
    $controller = admin_metrics_controller();

    $users            = $controller->kpi(admin_metrics_request(), 'users-total')->getData(true);
    $organizations    = $controller->kpi(admin_metrics_request(), 'organizations-total')->getData(true);
    $admins           = $controller->kpi(admin_metrics_request(), 'active-admins')->getData(true);
    $attention        = $controller->kpi(admin_metrics_request(), 'organizations-attention')->getData(true);
    $newUsers         = $controller->kpi(admin_metrics_request(), 'new-users')->getData(true);
    $newOrganizations = $controller->kpi(admin_metrics_request(), 'new-organizations')->getData(true);
    $failedJobs       = $controller->kpi(admin_metrics_request(), 'failed-jobs')->getData(true);
    $suspicious       = $controller->kpi(admin_metrics_request(), 'suspicious-activity')->getData(true);
    $unknown          = $controller->kpi(admin_metrics_request(), 'missing-metric');

    expect($users)->toMatchArray([
        'title'     => 'Users',
        'value'     => 5,
        'format'    => 'count',
        'delta_pct' => 300,
        'status'    => 'neutral',
        'icon'      => 'users',
        'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [1, 4]],
    ])
        ->and($organizations)->toMatchArray([
            'title'     => 'Organizations',
            'value'     => 5,
            'delta_pct' => 300,
            'icon'      => 'building',
            'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [1, 4]],
        ])
        ->and($admins)->toMatchArray([
            'title'     => 'Active Admins',
            'value'     => 2,
            'delta_pct' => 100,
            'icon'      => 'user-shield',
            'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [0, 3]],
        ])
        ->and($attention)->toMatchArray([
            'title'     => 'Pending Attention',
            'value'     => 3,
            'status'    => 'warning',
            'icon'      => 'building-circle-exclamation',
            'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [0, 1]],
        ])
        ->and($newUsers)->toMatchArray([
            'title'     => 'New Users',
            'value'     => 4,
            'delta_pct' => 300,
            'icon'      => 'user-plus',
            'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [1, 4]],
        ])
        ->and($newOrganizations)->toMatchArray([
            'title'     => 'New Organizations',
            'value'     => 4,
            'delta_pct' => 300,
            'icon'      => 'building-circle-check',
            'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [1, 4]],
        ])
        ->and($failedJobs)->toMatchArray([
            'title'     => 'Failed Jobs',
            'value'     => 2,
            'delta_pct' => 0,
            'status'    => 'danger',
            'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [1, 1]],
        ])
        ->and($suspicious)->toMatchArray([
            'title'     => 'Suspicious Activity',
            'value'     => 1,
            'delta_pct' => 0,
            'status'    => 'warning',
            'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [1, 1]],
        ])
        ->and($unknown->getStatusCode())->toBe(404)
        ->and($unknown->getData(true))->toBe(['error' => 'Unknown admin metric.']);
});

test('admin metrics fall back cleanly when optional activity and failed job tables are unavailable', function () {
    $controller = admin_metrics_controller(false, false);

    $failedJobs = $controller->kpi(admin_metrics_request(), 'failed-jobs')->getData(true);
    $suspicious = $controller->kpi(admin_metrics_request(), 'suspicious-activity')->getData(true);
    $activity   = $controller->widget(admin_metrics_request(), 'admin-activity')->getData(true);

    expect($failedJobs)->toMatchArray([
        'title'     => 'Failed Jobs',
        'value'     => 0,
        'status'    => 'success',
        'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [0, 0]],
    ])
        ->and($suspicious)->toMatchArray([
            'title'     => 'Suspicious Activity',
            'value'     => 0,
            'status'    => 'success',
            'sparkline' => ['labels' => ['Previous', 'Current'], 'data' => [0, 0]],
        ])
        ->and($activity)->toBe([
            'title'    => 'Admin Activity',
            'subtitle' => 'Recent sensitive admin events',
            'icon'     => 'clock-rotate-left',
            'empty'    => 'Activity logging is unavailable.',
            'items'    => [],
        ]);
});

test('admin metrics growth compares users and organizations across current and previous periods', function () {
    $payload = admin_metrics_controller()->growth(admin_metrics_request())->getData(true);

    expect($payload)->toMatchArray([
        'title'    => 'Platform Growth Trend',
        'subtitle' => 'Current 30 days compared with the previous 30 days',
        'icon'     => 'chart-line',
        'type'     => 'line',
        'labels'   => ['Previous 30d', 'Current 30d'],
        'empty'    => 'No growth data available.',
    ])
        ->and($payload['datasets'][0])->toMatchArray([
            'label' => 'Users',
            'data'  => [1, 4],
        ])
        ->and($payload['datasets'][1])->toMatchArray([
            'label' => 'Organizations',
            'data'  => [1, 4],
        ]);
});

test('admin dashboard widgets expose diagnostics configuration gaps and unknown widget errors', function () {
    $controller = admin_metrics_controller();

    $diagnostics = $controller->widget(admin_metrics_request(), 'system-diagnostics')->getData(true);
    $gaps        = $controller->widget(admin_metrics_request(), 'configuration-gaps')->getData(true);
    $unknown     = $controller->widget(admin_metrics_request(), 'missing-widget');

    expect($diagnostics)->toMatchArray([
        'title'    => 'System Diagnostics',
        'subtitle' => 'Core service configuration state',
        'icon'     => 'heart-pulse',
    ])
        ->and(collect($diagnostics['items'])->firstWhere('title', 'Mail'))->toMatchArray([
            'description' => 'Not configured',
            'value'       => 'Missing',
            'status'      => 'danger',
            'route'       => 'console.admin.config.mail',
        ])
        ->and(collect($diagnostics['items'])->firstWhere('title', 'Filesystem'))->toMatchArray([
            'description' => 's3',
            'value'       => 'OK',
            'status'      => 'success',
        ])
        ->and(collect($diagnostics['items'])->firstWhere('title', 'Scheduler'))->toMatchArray([
            'description' => 'Not configured',
            'value'       => 'Missing',
            'status'      => 'danger',
        ])
        ->and($gaps['title'])->toBe('Configuration Gaps')
        ->and(collect($gaps['items'])->pluck('title')->all())->toBe(['Mail driver', 'Queue driver'])
        ->and($unknown->getStatusCode())->toBe(404)
        ->and($unknown->getData(true))->toBe(['error' => 'Unknown admin dashboard widget.']);
});

test('admin dashboard widgets expose activity and organization risk queues', function () {
    $controller = admin_metrics_controller();

    Company::insert([
        'uuid'                    => 'company-no-public-id',
        'public_id'               => null,
        'name'                    => 'No Public ID Org',
        'owner_uuid'              => null,
        'status'                  => 'active',
        'onboarding_completed_at' => '2026-07-11 08:00:00',
        'created_at'              => '2026-07-11 08:00:00',
        'updated_at'              => '2026-07-11 08:00:00',
        'deleted_at'              => null,
    ]);

    $activity = $controller->widget(admin_metrics_request(), 'admin-activity')->getData(true);
    $risk     = $controller->widget(admin_metrics_request(), 'organization-risk-queue')->getData(true);

    expect($activity)->toMatchArray([
        'title' => 'Admin Activity',
        'route' => 'console.admin.organizations.index',
    ])
        ->and($activity['items'])->toHaveCount(3)
        ->and($activity['items'][0])->toMatchArray([
            'title'  => 'ordinary update',
            'value'  => 'updated',
            'status' => 'info',
        ])
        ->and($activity['items'][1])->toMatchArray([
            'title'  => 'admin impersonated user',
            'value'  => 'impersonated',
            'status' => 'info',
        ])
        ->and($activity['items'][2])->toMatchArray([
            'title'  => 'password reset requested',
            'value'  => 'password.reset',
            'status' => 'warning',
        ])
        ->and($risk)->toMatchArray([
            'title'       => 'Organization Risk Queue',
            'queryParams' => ['needs_attention' => 1],
        ])
        ->and(collect($risk['items'])->pluck('value')->all())->toBe(['Missing owner', 'Status review', 'Incomplete onboarding', 'Missing owner'])
        ->and($risk['items'][0])->toMatchArray([
            'title'       => 'No Public ID Org',
            'description' => 'company-no-public-id',
            'status'      => 'warning',
            'routeModels' => [null],
        ])
        ->and($risk['items'][1])->toMatchArray([
            'title'       => 'Suspended Org',
            'description' => 'company_suspended',
            'status'      => 'danger',
            'routeModels' => ['company_suspended'],
        ]);
});
