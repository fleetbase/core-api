<?php

namespace Fleetbase\Notifications;

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
     * Register a notification.
     *
     * @param string|array $notificationClass The class or an array of classes to register.
     * @param string $name The name of the notification.
     * @param string $description The description of the notification.
     * @param string $package The package to which the notification belongs.
     * @param array $options Additional options for the notification.
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
}
