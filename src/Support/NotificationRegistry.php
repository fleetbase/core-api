<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Setting;
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
            'params' => static::getNotificationClassParameters($notificationClass),
            'options' => static::getNotificationClassProperty($notificationClass, 'notificationOptions', []),
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
     * @param mixed $defaultValue The default value if the property is not found.
     * @return mixed|null The value of the property or null if not found.
     */
    private static function getNotificationClassProperty(string $notificationClass, string $property, $defaultValue = null)
    {
        if (!class_exists($notificationClass) || !property_exists($notificationClass, $property)) {
            return $defaultValue;
        }

        $properties = get_class_vars($notificationClass);
        return data_get($properties, $property, $defaultValue);
    }

    /**
     * Get the parameters required by a specific notification class constructor.
     *
     * @param string $notificationClass The class name of the notification.
     *
     * @return array An array of associative arrays, each containing details about a parameter required by the constructor.
     */
    private function getNotificationClassParameters(string $notificationClass): array
    {
        // Make sure class exists
        if (!class_exists($notificationClass)) {
            return [];
        }

        // Create ReflectionMethod object for the constructor
        $reflection = new \ReflectionMethod($notificationClass, '__construct');

        // Get parameters
        $params = $reflection->getParameters();

        // Array to store required parameters
        $requiredParams = [];

        foreach ($params as $param) {
            // Get parameter name
            $name = $param->getName();

            // Get parameter type
            $type = $param->getType();

            // Check if the parameter is optional
            $isOptional = $param->isOptional();

            $requiredParams[] = [
                'name' => $name,
                'type' => $type ? $type->getName() : 'mixed',  // If type is null, set it as 'mixed'
                'optional' => $isOptional
            ];
        }

        return $requiredParams;
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
                $modelClass = get_class($notifiableModel);
                $hasCompanyColumn = Schema::hasColumn($table, 'company_uuid');

                if ($hasCompanyColumn) {
                    $records = $notifiableModel->where('company_uuid', $companySessionId)->get();

                    foreach ($records as $record) {
                        $recordId = Utils::or($record, ['uuid', 'id']);
                        $notifiables[] = [
                            'label' => Str::title($type) . ': ' . Utils::or($record, ['name', 'public_id']),
                            'key' => $recordId,
                            'primaryKey' => $notifiableModel->getKeyName(),
                            'definition' => $modelClass,
                            'value' => Str::slug(str_replace('\\', '-', $modelClass)) . ':' . $recordId,
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

    /**
     * Notify one or multiple notifiables using a specific notification class.
     *
     * @param string $notificationClass The class name of the notification to use.
     * @param mixed ...$params           Additional parameters for the notification class.
     *
     * @return void
     * @throws \Exception
     */
    public static function notify($notificationClass,  ...$params): void
    {
        // if the class doesn't exist return false
        if (!class_exists($notificationClass)) {
            return;
        }

        // resolve settings for notification
        $notificationSettings = Setting::lookup('notification_settings');
        $notificationSettingsKey = Str::camel(str_replace('\\', '', $notificationClass));

        // get the notification settings for this $notificationClass
        $settings = data_get($notificationSettings, $notificationSettingsKey, []);

        // if we have the settings resolve the notifiables
        if ($settings) {
            $notifiables = data_get($settings, 'notifiables', []);

            if (is_array($notifiables)) {
                foreach ($notifiables as $notifiable) {
                    $notifiableModel = static::resolveNotifiable($notifiable);

                    // if has multiple notifiables 
                    if (isset($notifiableModel->containsMultipleNotifiables) && is_string($notifiableModel->containsMultipleNotifiables)) {
                        $notifiablesRelationship = $notifiableModel->containsMultipleNotifiables;
                        $multipleNotifiables = data_get($notifiableModel, $notifiablesRelationship, []);

                        // notifiy each
                        foreach ($multipleNotifiables as $singleNotifiable) {
                            $singleNotifiable->notify(new $notificationClass(...$params));
                        }

                        // continue
                        continue;
                    }

                    if ($notifiableModel) {
                        $notifiableModel->notify(new $notificationClass(...$params));
                    }
                }
            }
        }
    }


    /**
     * Resolve a notifiable object to an Eloquent model.
     *
     * @param array $notifiableObject An associative array containing the definition and primary key to resolve the notifiable object.
     *
     * @return \Illuminate\Database\Eloquent\Model|null The Eloquent model or null if it cannot be resolved.
     */
    protected static function resolveNotifiable(array $notifiableObject): ?\Illuminate\Database\Eloquent\Model
    {
        $definition = data_get($notifiableObject, 'definition');
        $primaryKey = data_get($notifiableObject, 'primaryKey');
        $key = data_get($notifiableObject, 'key');

        // resolve the notifiable
        $modelInstance = app($definition);

        if ($modelInstance instanceof \Illuminate\Database\Eloquent\Model) {
            return $modelInstance->where($primaryKey, $key)->first();
        }

        return null;
    }
}
