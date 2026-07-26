<?php

use Fleetbase\Http\Controllers\Internal\v1\NotificationController;
use Fleetbase\Models\Notification;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class NotificationControllerCoreNotice
{
    public static string $name        = 'Core Alert';
    public static string $description = 'Core package alert.';
    public static string $package     = 'core';

    public function __construct(public string $subjectUuid)
    {
    }
}

function notification_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();
    $_SERVER['REQUEST_METHOD'] = 'GET';

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
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');
    Facade::clearResolvedInstance('schema');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-1',
    ]);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('notifications', function ($table) {
        $table->string('id')->primary();
        $table->string('type');
        $table->string('notifiable_type');
        $table->string('notifiable_id')->index();
        $table->json('data')->nullable();
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->json('value')->nullable();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-1', 'public_id' => 'user_public_1', 'company_uuid' => 'company-1', 'name' => 'Current User', 'email' => 'current@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-2', 'public_id' => 'user_public_2', 'company_uuid' => 'company-1', 'name' => 'Second User', 'email' => 'second@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-other-company', 'public_id' => 'user_public_other', 'company_uuid' => 'company-2', 'name' => 'Other Company', 'email' => 'other@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('notifications')->insert([
        ['id' => 'notice-1', 'type' => 'coverage.notice', 'notifiable_type' => Fleetbase\Models\User::class, 'notifiable_id' => 'user-1', 'data' => json_encode(['message' => 'First']), 'read_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'notice-2', 'type' => 'coverage.notice', 'notifiable_type' => Fleetbase\Models\User::class, 'notifiable_id' => 'user-1', 'data' => json_encode(['message' => 'Second']), 'read_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'notice-other-user', 'type' => 'coverage.notice', 'notifiable_type' => Fleetbase\Models\User::class, 'notifiable_id' => 'user-2', 'data' => json_encode(['message' => 'Other User']), 'read_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'notice-other-type', 'type' => 'coverage.notice', 'notifiable_type' => Fleetbase\Models\Company::class, 'notifiable_id' => 'user-1', 'data' => json_encode(['message' => 'Other Type']), 'read_at' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('settings')->insert([
        'key'   => 'company.company-1.notification_settings',
        'value' => json_encode(['email' => true, 'sms' => false]),
    ]);

    NotificationRegistry::$notifications = [];
    NotificationRegistry::$notifiables   = [Fleetbase\Models\User::class];

    return $capsule;
}

function notification_controller(): NotificationController
{
    return new NotificationController();
}

function notification_controller_reflect(NotificationController $controller, string $method): mixed
{
    $reflection = new ReflectionMethod(NotificationController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller);
}

afterEach(function () {
    session()->flush();
    NotificationRegistry::$notifications = [];
    NotificationRegistry::$notifiables   = [
        Fleetbase\Models\User::class,
        Fleetbase\Models\Group::class,
        Fleetbase\Models\Role::class,
        Fleetbase\Models\Company::class,
    ];
    config([
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
    ]);
    EloquentModel::clearBootedModels();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('notification controller marks only current user notifications as read and reports partial totals', function () {
    notification_controller_database();

    $response = notification_controller()->markAsRead(Request::create('/int/v1/notifications/read', 'POST', [
        'notifications' => ['notice-1', 'notice-other-user', 'missing-notice'],
    ]));

    expect($response->getData(true))->toMatchArray([
        'status'         => 'ok',
        'message'        => 'Notifications marked as read',
        'marked_as_read' => 1,
        'total'          => 3,
    ])
        ->and(Notification::where('id', 'notice-1')->value('read_at'))->not->toBeNull()
        ->and(Notification::where('id', 'notice-2')->value('read_at'))->toBeNull()
        ->and(Notification::where('id', 'notice-other-user')->value('read_at'))->toBeNull();
});

test('notification controller marks all current user notifications as read without touching other notifiables', function () {
    notification_controller_database();

    $response = notification_controller()->markAllAsRead();

    expect($response->getData(true))->toBe([
        'status'  => 'ok',
        'message' => 'All notifications marked as read',
    ])
        ->and(Notification::where('notifiable_id', 'user-1')->where('notifiable_type', Fleetbase\Models\User::class)->whereNull('read_at')->count())->toBe(0)
        ->and(Notification::where('id', 'notice-other-user')->value('read_at'))->toBeNull()
        ->and(Notification::where('id', 'notice-other-type')->value('read_at'))->toBeNull();
});

test('notification controller deletes scoped notifications and rejects missing ids consistently', function () {
    notification_controller_database();
    $controller = notification_controller();

    $deleted      = $controller->deleteNotification('notice-1');
    $foreign      = $controller->deleteNotification('notice-other-user');
    $routeDeleted = $controller->deleteRecord('notice-2', Request::create('/int/v1/notifications/notice-2', 'DELETE'));
    $missing      = $controller->deleteRecord('missing-notice', Request::create('/int/v1/notifications/missing', 'DELETE'));

    expect($deleted->getStatusCode())->toBe(200)
        ->and($deleted->getData(true))->toBe(['message' => 'Notification deleted successfully'])
        ->and($routeDeleted->getStatusCode())->toBe(200)
        ->and($routeDeleted->getData(true))->toBe(['message' => 'Notification deleted successfully'])
        ->and($foreign->getStatusCode())->toBe(404)
        ->and($foreign->getData(true))->toBe(['error' => 'Notification not found'])
        ->and($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Notification not found'])
        ->and(Notification::where('id', 'notice-1')->exists())->toBeFalse()
        ->and(Notification::where('id', 'notice-2')->exists())->toBeFalse()
        ->and(Notification::where('id', 'notice-other-user')->exists())->toBeTrue();
});

test('notification controller bulk deletes selected or all current user notifications only', function () {
    notification_controller_database();
    $controller = notification_controller();

    $selected = $controller->bulkDelete(Request::create('/int/v1/notifications/bulk-delete', 'POST', [
        'notifications' => ['notice-1', 'notice-other-user'],
    ]));
    $all = $controller->bulkDelete(Request::create('/int/v1/notifications/bulk-delete', 'POST', [
        'notifications' => [],
    ]));

    expect($selected->getData(true))->toBe([
        'status'  => 'ok',
        'message' => 'Selected notifications deleted successfully',
    ])
        ->and($all->getData(true))->toBe([
            'status'  => 'ok',
            'message' => 'Selected notifications deleted successfully',
        ])
        ->and(Notification::where('notifiable_id', 'user-1')->where('notifiable_type', Fleetbase\Models\User::class)->count())->toBe(0)
        ->and(Notification::where('id', 'notice-other-user')->exists())->toBeTrue()
        ->and(Notification::where('id', 'notice-other-type')->exists())->toBeTrue();
});

test('notification controller exposes core registry and company scoped notifiables', function () {
    notification_controller_database();
    NotificationRegistry::register(NotificationControllerCoreNotice::class);

    $registry    = notification_controller()->registry();
    $notifiables = notification_controller()->notifiables();
    $registered  = $registry->getData(true)[0];

    expect($registered['definition'])->toBe(NotificationControllerCoreNotice::class)
        ->and($registered['name'])->toBe('Core Alert')
        ->and($registered['description'])->toBe('Core package alert.')
        ->and($registered['package'])->toBe('core')
        ->and($registered['params'])->toBe([
            ['name' => 'subjectUuid', 'type' => 'string', 'optional' => false],
        ])
        ->and(collect($notifiables->getData(true))->pluck('key')->all())->toBe(['user-1', 'user-2'])
        ->and(collect($notifiables->getData(true))->pluck('definition')->unique()->values()->all())->toBe([Fleetbase\Models\User::class]);
});

test('notification controller merges notification settings and rejects invalid payloads', function () {
    notification_controller_database();
    $controller = notification_controller();

    $saved = $controller->saveSettings(Request::create('/int/v1/notifications/settings', 'POST', [
        'notificationSettings' => [
            'sms'   => true,
            'push'  => false,
        ],
    ]));
    $settings = $controller->getSettings();

    expect($saved->getData(true))->toBe([
        'status'  => 'ok',
        'message' => 'Notification settings succesfully saved.',
    ])
        ->and($settings->getData(true))->toBe([
            'status'               => 'ok',
            'message'              => 'Notification settings successfully fetched.',
            'notificationSettings' => [
                'email' => true,
                'sms'   => true,
                'push'  => false,
            ],
        ])
        ->and(fn () => $controller->saveSettings(Request::create('/int/v1/notifications/settings', 'POST', [
            'notificationSettings' => 'invalid',
        ])))->toThrow(Exception::class, 'Invalid notification settings data.');
});
