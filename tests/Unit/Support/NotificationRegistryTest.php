<?php

use Fleetbase\Support\NotificationRegistry;

class NotificationRegistryPrimaryNotification
{
    public static string $name = 'Order Assigned';
    public static string $description = 'Sent when an order is assigned.';
    public static string $package = 'fleet-ops';
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

beforeEach(function () {
    NotificationRegistry::$notifications = [];
    NotificationRegistry::$notifiables = [
        \Fleetbase\Models\User::class,
        \Fleetbase\Models\Group::class,
        \Fleetbase\Models\Role::class,
        \Fleetbase\Models\Company::class,
    ];
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
                'name' => 'Fallback Notice',
                'description' => 'Fallback description',
                'package' => 'core-api',
                'notificationOptions' => ['channels' => ['database']],
            ],
        ],
    ]);

    expect(NotificationRegistry::$notifications)->toHaveCount(2)
        ->and(NotificationRegistry::getNotificationsByPackage('fleet-ops'))->toHaveCount(1)
        ->and(NotificationRegistry::getNotificationsByPackage('core-api'))->toHaveCount(1)
        ->and(NotificationRegistry::findNotificationRegistrationByDefinition(NotificationRegistryFallbackNotification::class))
        ->toMatchArray([
            'definition' => NotificationRegistryFallbackNotification::class,
            'name' => 'Fallback Notice',
            'description' => 'Fallback description',
            'package' => 'core-api',
            'options' => ['channels' => ['database']],
        ]);
});

test('notification registry rejects invalid batch entries and ignores unknown notification classes safely', function () {
    expect(fn () => NotificationRegistry::register([123]))
        ->toThrow(Exception::class, 'Attempted to register invalid notification.');

    NotificationRegistry::register('Missing\\Notification\\Class', [
        'name' => 'Missing Notice',
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
            'label' => 'Dynamic: Driver',
            'key' => 'driver',
            'primaryKey' => 'uuid',
            'definition' => 'dynamic:driver',
            'value' => 'dynamic:driver',
        ],
        [
            'label' => 'Dynamic: Customer_Contact',
            'key' => 'customer_contact',
            'primaryKey' => 'uuid',
            'definition' => 'dynamic:customer_contact',
            'value' => 'dynamic:customer_contact',
        ],
    ]);
});
