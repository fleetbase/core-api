<?php

use Fleetbase\Support\NotificationRegistry;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class NotificationRegistryPrimaryNotification
{
    public static string $name               = 'Order Assigned';
    public static string $description        = 'Sent when an order is assigned.';
    public static string $package            = 'fleet-ops';
    public static array $notificationOptions = ['channels' => ['mail', 'database']];

    public function __construct(public string $orderUuid, public ?int $attempt = null)
    {
    }
}

class NotificationRegistryFallbackNotification
{
    public function __construct(public string $subjectUuid)
    {
    }
}

class NotificationRegistryDispatchNotification
{
    public static string $name    = 'Dispatch Notice';
    public static string $package = 'core-api';

    public function __construct(public EloquentModel $subject, public string $label)
    {
    }
}

class NotificationRegistryDispatchTarget extends EloquentModel
{
    public static array $sent = [];

    protected $table = 'notification_registry_targets';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['uuid', 'company_uuid', 'name'];

    public function notify(mixed $notification): void
    {
        static::$sent[] = [
            'target'       => $this->uuid,
            'notification' => get_class($notification),
            'subject'      => $notification->subject->uuid,
            'label'        => $notification->label,
        ];
    }
}

class NotificationRegistryDispatchGroup extends EloquentModel
{
    public string $containsMultipleNotifiables = 'members';

    protected $table = 'notification_registry_groups';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['uuid'];

    public function getMembersAttribute(): array
    {
        return [
            NotificationRegistryDispatchTarget::query()->find('target-2'),
            NotificationRegistryDispatchTarget::query()->find('target-1'),
        ];
    }
}

class NotificationRegistryDispatchSubject extends EloquentModel
{
    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public function resolveDynamicNotifiable(string $property): ?EloquentModel
    {
        return $property === 'dispatcher'
            ? NotificationRegistryDispatchTarget::query()->find('target-1')
            : null;
    }
}

class NotificationRegistryDispatchPropertySubject extends EloquentModel
{
    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}

class NotificationRegistryDispatchCacheFake
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

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        $this->values = [];

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
}

function notification_registry_dispatch_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connectionConfig,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new NotificationRegistryDispatchCacheFake());

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('notification_registry_targets', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('name');
    });
    $schema->create('notification_registry_groups', function ($table) {
        $table->string('uuid')->primary();
    });

    NotificationRegistryDispatchTarget::query()->insert([
        ['uuid' => 'target-1', 'company_uuid' => 'company-1', 'name' => 'Primary Target'],
        ['uuid' => 'target-2', 'company_uuid' => 'company-1', 'name' => 'Secondary Target'],
    ]);
    NotificationRegistryDispatchGroup::query()->create(['uuid' => 'group-1']);

    return $capsule;
}

beforeEach(function () {
    NotificationRegistry::$notifications = [];
    NotificationRegistry::$notifiables   = [
        Fleetbase\Models\User::class,
        Fleetbase\Models\Group::class,
        Fleetbase\Models\Role::class,
        Fleetbase\Models\Company::class,
    ];
    NotificationRegistryDispatchTarget::$sent = [];
});

afterEach(function () {
    session()->flush();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('notification registry registers notification metadata and constructor params', function () {
    NotificationRegistry::register(NotificationRegistryPrimaryNotification::class);

    $registration = NotificationRegistry::findNotificationRegistrationByDefinition(NotificationRegistryPrimaryNotification::class);

    expect($registration)->not->toBeNull()
        ->and($registration['definition'])->toBe(NotificationRegistryPrimaryNotification::class)
        ->and($registration['name'])->toBe('Order Assigned')
        ->and($registration['description'])->toBe('Sent when an order is assigned.')
        ->and($registration['package'])->toBe('fleet-ops')
        ->and($registration['options'])->toBe(['channels' => ['mail', 'database']])
        ->and($registration['params'])->toBe([
            ['name' => 'orderUuid', 'type' => 'string', 'optional' => false],
            ['name' => 'attempt', 'type' => 'int', 'optional' => true],
        ]);
});

test('notification registry supports batch registration option fallbacks and package filtering', function () {
    NotificationRegistry::register([
        NotificationRegistryPrimaryNotification::class,
        [
            NotificationRegistryFallbackNotification::class,
            [
                'name'                => 'Fallback Notice',
                'description'         => 'Fallback description',
                'package'             => 'core-api',
                'notificationOptions' => ['channels' => ['database']],
            ],
        ],
    ]);

    expect(NotificationRegistry::$notifications)->toHaveCount(2)
        ->and(NotificationRegistry::getNotificationsByPackage('fleet-ops'))->toHaveCount(1)
        ->and(NotificationRegistry::getNotificationsByPackage('core-api'))->toHaveCount(1)
        ->and(NotificationRegistry::findNotificationRegistrationByDefinition(NotificationRegistryFallbackNotification::class))
        ->toMatchArray([
            'definition'  => NotificationRegistryFallbackNotification::class,
            'name'        => 'Fallback Notice',
            'description' => 'Fallback description',
            'package'     => 'core-api',
            'options'     => ['channels' => ['database']],
        ]);
});

test('notification registry rejects invalid batch entries and ignores unknown notification classes safely', function () {
    expect(fn () => NotificationRegistry::register([123]))
        ->toThrow(Exception::class, 'Attempted to register invalid notification.');

    NotificationRegistry::register('Missing\\Notification\\Class', [
        'name'    => 'Missing Notice',
        'package' => 'missing-package',
    ]);

    $registration = NotificationRegistry::findNotificationRegistrationByDefinition('Missing\\Notification\\Class');

    expect($registration['name'])->toBe('Missing Notice')
        ->and($registration['description'])->toBeNull()
        ->and($registration['package'])->toBe('missing-package')
        ->and($registration['params'])->toBe([]);
});

test('notification registry registers dynamic notifiables and exposes company-safe definitions', function () {
    expect(NotificationRegistry::getNotifiablesForCompany(''))->toBe([]);

    NotificationRegistry::$notifiables = [];
    NotificationRegistry::registerNotifiable([
        'dynamic:driver',
        'dynamic:customer_contact',
    ]);

    expect(NotificationRegistry::getNotifiablesForCompany('company-1'))->toBe([
        [
            'label'      => 'Dynamic: Driver',
            'key'        => 'driver',
            'primaryKey' => 'uuid',
            'definition' => 'dynamic:driver',
            'value'      => 'dynamic:driver',
        ],
        [
            'label'      => 'Dynamic: Customer_Contact',
            'key'        => 'customer_contact',
            'primaryKey' => 'uuid',
            'definition' => 'dynamic:customer_contact',
            'value'      => 'dynamic:customer_contact',
        ],
    ]);
});

test('notification registry dispatches configured notifiables once across direct dynamic and grouped targets', function () {
    notification_registry_dispatch_database();
    session(['company' => 'company-1']);

    NotificationRegistry::register(NotificationRegistryDispatchNotification::class);

    Fleetbase\Models\Setting::query()->create([
        'key'   => 'company.company-1.notification_settings',
        'value' => [
            'notificationRegistryDispatchNotification__dispatchNotice' => [
                'notifiables' => [
                    [
                        'definition' => NotificationRegistryDispatchTarget::class,
                        'primaryKey' => 'uuid',
                        'key'        => 'target-1',
                    ],
                    [
                        'definition' => 'dynamic:assignee',
                        'primaryKey' => 'uuid',
                        'key'        => 'ignored-for-dynamic',
                    ],
                    [
                        'definition' => NotificationRegistryDispatchGroup::class,
                        'primaryKey' => 'uuid',
                        'key'        => 'group-1',
                    ],
                ],
            ],
        ],
    ]);

    $subject       = new NotificationRegistryDispatchSubject();
    $subject->uuid = 'subject-1';

    NotificationRegistry::notify(NotificationRegistryDispatchNotification::class, $subject, 'ready');

    expect(NotificationRegistryDispatchTarget::$sent)->toBe([
        [
            'target'       => 'target-1',
            'notification' => NotificationRegistryDispatchNotification::class,
            'subject'      => 'subject-1',
            'label'        => 'ready',
        ],
        [
            'target'       => 'target-2',
            'notification' => NotificationRegistryDispatchNotification::class,
            'subject'      => 'subject-1',
            'label'        => 'ready',
        ],
    ]);
});

test('notification registry ignores missing notification classes before resolving settings', function () {
    notification_registry_dispatch_database();

    NotificationRegistry::notify('Missing\\Notification\\Class');
    NotificationRegistry::notifyUsingDefinitionName('Missing\\Notification\\Class', 'Missing Notice');

    expect(NotificationRegistryDispatchTarget::$sent)->toBe([]);
});

test('notification registry dispatches by definition name with dynamic subject context', function () {
    notification_registry_dispatch_database();

    Fleetbase\Models\Setting::query()->create([
        'key'   => 'notification_settings',
        'value' => [
            'notificationRegistryDispatchNotification__manualNotice' => [
                'notifiables' => [
                    [
                        'definition' => 'dynamic:dispatcher',
                        'primaryKey' => 'uuid',
                        'key'        => 'ignored-for-dynamic',
                    ],
                    [
                        'definition' => NotificationRegistryDispatchTarget::class,
                        'primaryKey' => 'uuid',
                        'key'        => 'target-2',
                    ],
                ],
            ],
        ],
    ]);

    $subject       = new NotificationRegistryDispatchSubject();
    $subject->uuid = 'subject-2';

    NotificationRegistry::notifyUsingDefinitionName(NotificationRegistryDispatchNotification::class, 'Manual Notice', $subject, 'manual');

    expect(NotificationRegistryDispatchTarget::$sent)->toBe([
        [
            'target'       => 'target-1',
            'notification' => NotificationRegistryDispatchNotification::class,
            'subject'      => 'subject-2',
            'label'        => 'manual',
        ],
        [
            'target'       => 'target-2',
            'notification' => NotificationRegistryDispatchNotification::class,
            'subject'      => 'subject-2',
            'label'        => 'manual',
        ],
    ]);
});

test('notification registry dispatches by definition name to grouped notifiables', function () {
    notification_registry_dispatch_database();

    Fleetbase\Models\Setting::query()->create([
        'key'   => 'notification_settings',
        'value' => [
            'notificationRegistryDispatchNotification__manualNotice' => [
                'notifiables' => [
                    [
                        'definition' => NotificationRegistryDispatchGroup::class,
                        'primaryKey' => 'uuid',
                        'key'        => 'group-1',
                    ],
                ],
            ],
        ],
    ]);

    $subject       = new NotificationRegistryDispatchPropertySubject();
    $subject->uuid = 'subject-3';

    NotificationRegistry::notifyUsingDefinitionName(NotificationRegistryDispatchNotification::class, 'Manual Notice', $subject, 'property');

    expect(NotificationRegistryDispatchTarget::$sent)->toBe([
        [
            'target'       => 'target-2',
            'notification' => NotificationRegistryDispatchNotification::class,
            'subject'      => 'subject-3',
            'label'        => 'property',
        ],
        [
            'target'       => 'target-1',
            'notification' => NotificationRegistryDispatchNotification::class,
            'subject'      => 'subject-3',
            'label'        => 'property',
        ],
    ]);
});

test('notification registry skips duplicate direct notifiables during configured dispatch', function () {
    notification_registry_dispatch_database();
    session(['company' => 'company-1']);

    NotificationRegistry::register(NotificationRegistryDispatchNotification::class);

    Fleetbase\Models\Setting::query()->create([
        'key'   => 'company.company-1.notification_settings',
        'value' => [
            'notificationRegistryDispatchNotification__dispatchNotice' => [
                'notifiables' => [
                    [
                        'definition' => NotificationRegistryDispatchTarget::class,
                        'primaryKey' => 'uuid',
                        'key'        => 'target-1',
                    ],
                    [
                        'definition' => NotificationRegistryDispatchTarget::class,
                        'primaryKey' => 'uuid',
                        'key'        => 'target-1',
                    ],
                ],
            ],
        ],
    ]);

    $subject       = new NotificationRegistryDispatchSubject();
    $subject->uuid = 'subject-4';

    NotificationRegistry::notify(NotificationRegistryDispatchNotification::class, $subject, 'dedupe');

    expect(NotificationRegistryDispatchTarget::$sent)->toBe([
        [
            'target'       => 'target-1',
            'notification' => NotificationRegistryDispatchNotification::class,
            'subject'      => 'subject-4',
            'label'        => 'dedupe',
        ],
    ]);
});

test('notification registry resolves dynamic notifiables from subject properties', function () {
    notification_registry_dispatch_database();

    Fleetbase\Models\Setting::query()->create([
        'key'   => 'notification_settings',
        'value' => [
            'notificationRegistryDispatchNotification__manualNotice' => [
                'notifiables' => [
                    [
                        'definition' => 'dynamic:assignee',
                        'primaryKey' => 'uuid',
                        'key'        => 'ignored-for-dynamic',
                    ],
                ],
            ],
        ],
    ]);

    $subject       = new NotificationRegistryDispatchPropertySubject();
    $subject->uuid = 'subject-5';
    $subject->setRelation('assignee', NotificationRegistryDispatchTarget::query()->find('target-2'));

    NotificationRegistry::notifyUsingDefinitionName(NotificationRegistryDispatchNotification::class, 'Manual Notice', $subject, 'property');

    expect(NotificationRegistryDispatchTarget::$sent)->toBe([
        [
            'target'       => 'target-2',
            'notification' => NotificationRegistryDispatchNotification::class,
            'subject'      => 'subject-5',
            'label'        => 'property',
        ],
    ]);
});

test('notification registry ignores configured notifiable definitions that do not resolve to models', function () {
    notification_registry_dispatch_database();

    Fleetbase\Models\Setting::query()->create([
        'key'   => 'notification_settings',
        'value' => [
            'notificationRegistryDispatchNotification__manualNotice' => [
                'notifiables' => [
                    [
                        'definition' => stdClass::class,
                        'primaryKey' => 'uuid',
                        'key'        => 'target-1',
                    ],
                ],
            ],
        ],
    ]);

    $subject       = new NotificationRegistryDispatchSubject();
    $subject->uuid = 'subject-6';

    NotificationRegistry::notifyUsingDefinitionName(NotificationRegistryDispatchNotification::class, 'Manual Notice', $subject, 'ignored');

    expect(NotificationRegistryDispatchTarget::$sent)->toBe([]);
});
