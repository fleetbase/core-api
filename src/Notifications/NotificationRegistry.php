<?php

namespace Fleetbase\Notifications;

use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Notification Registry for managing registered notifications.
 */
class NotificationRegistry
{
    /**
     * Array to store registered notifications.
     *
     * @var array
     */
    public static $notifications = [];

    /**
     * Array to store registered notificable types.
     *
     * @var array
     */
    public static $notifiables = [
        \Fleetbase\Models\User::class,
        \Fleetbase\Models\Group::class,
        \Fleetbase\Models\Role::class,
        \Fleetbase\Models\Company::class,
    ];

    /**
     * Register a notification.
     *
     * @param string|array $notificationClass The class or an array of classes to register.
     * @return void
     */
    public static function register($notificationClass): void
    {
        if (is_array($notificationClass)) {
            foreach ($notificationClass as $notificationClassElement) {
                static::register($notificationClassElement);
            }

            return;
        }

        static::$notifications[] = [
            'definition' => $notificationClass,
            'name' => static::getNotificationClassProperty($notificationClass, 'name'),
            'description' => static::getNotificationClassProperty($notificationClass, 'description'),
            'package' => static::getNotificationClassProperty($notificationClass, 'package'),
            'options' => static::getNotificationClassProperty($notificationClass, 'notificationOptions'),
        ];
    }

    /**
     * Register a notifiable.
     *
     * @param string|array $notifiableClass The class of the notifiable.
     * @return void
     */
    public static function registerNotifiable($notifiableClass): void
    {
        if (is_array($notifiableClass)) {
            foreach ($notifiableClass as $notifiableClassElement) {
                static::registerNotifiable($notifiableClassElement);
            }

            return;
        }

        static::$notifiables[] = $notifiableClass;
    }

    /**
     * Get a property of a notification class.
     *
     * @param string $notificationClass The class name.
     * @param string $property The name of the property to retrieve.
     * @return mixed|null The value of the property or null if not found.
     */
    private static function getNotificationClassProperty(string $notificationClass, string $property)
    {
        if (!class_exists($notificationClass) || !property_exists($notificationClass, $property)) {
            return null;
        }

        $properties = get_class_vars($notificationClass);
        return data_get($properties, $property);
    }

    /**
     * Get all notificables for a company.
     *
     * @return array
     */
    public static function getNotifiablesForCompany(string $companyId): array
    {
        $companySessionId = $companyId;

        // if no company session provided, no notifiables
        if (!$companySessionId) {
            return [];
        }

        $notifiables = [];

        // iterate through each notifiable types and get records
        foreach (static::$notifiables as $notifiableClass) {
            $notifiableModel = app($notifiableClass);
            $type = class_basename($notifiableClass);

            if ($notifiableModel && $notifiableModel instanceof \Illuminate\Database\Eloquent\Model) {
                $table = $notifiableModel->getTable();
                $hasCompanyColumn = Schema::hasColumn($table, 'company_uuid');

                if ($hasCompanyColumn) {
                    $records = $notifiableModel->where('company_uuid', $companySessionId)->get();
                    
                    foreach ($records as $record) {
                        $recordId = Utils::or($record, ['uuid', 'id']);
                        $notifiables[] = [
                            'label' => Str::title($type) . ': ' . Utils::or($record, ['name', 'public_id']),
                            'uuid' => $recordId,
                            'value' => strtolower($type) . ':' . $recordId,
                        ];
                    }
                }
            }
        }

        return $notifiables;
    }

    /**
     * Gets all notifiables for the current company session.
     *
     * @return array
     */
    public static function getNotifiables(): array
    {
        $companySessionId = session('company');
        return static::getNotifiablesForCompany($companySessionId);
    }
}
