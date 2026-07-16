<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Models\Notification;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Http\Request;

/**
 * Controller for managing notifications.
 */
class NotificationController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'notification';

    /**
     * Receives an array of ID's for notifications which should be marked as read.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the HTTP response
     */
    public function markAsRead(Request $request)
    {
        $notifications = $request->input('notifications');
        $total         = count($notifications);
        $read          = [];

        foreach ($notifications as $id) {
            $notification = $this->notificationQuery()->where('id', $id)->first();

            if ($notification) {
                $read[] = $notification->markAsRead();
            }
        }

        return response()->json([
            'status'         => 'ok',
            'message'        => 'Notifications marked as read',
            'marked_as_read' => count($read),
            'total'          => $total,
        ]);
    }

    /**
     * Receives an array of ID's for notifications which should be marked as read.
     *
     * @return \Illuminate\Http\Response the HTTP response
     */
    public function markAllAsRead()
    {
        $notifications = $this->notificationQuery()->get();

        foreach ($notifications as $notification) {
            $notification->markAsRead();
        }

        return response()->json([
            'status'  => 'ok',
            'message' => 'All notifications marked as read',
        ]);
    }

    /**
     * Deletes a single notification.
     *
     * @param int $notificationId the ID of the notification to delete
     *
     * @return \Illuminate\Http\Response the HTTP response
     */
    public function deleteNotification($notificationId)
    {
        $notification = $this->notificationQuery()->where('id', $notificationId)->first();

        if ($notification) {
            $notification->deleteNotification();

            return response()->json(['message' => 'Notification deleted successfully'], 200);
        } else {
            return response()->json(['error' => 'Notification not found'], 404);
        }
    }

    /**
     * Deletes all notifications for the authenticated user.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the HTTP response
     */
    public function bulkDelete(Request $request)
    {
        $notifications = $request->input('notifications');

        if (empty($notifications)) {
            $this->notificationQuery()->delete();
        } else {
            $this->notificationQuery()->whereIn('id', $notifications)->delete();
        }

        return response()->json([
            'status'  => 'ok',
            'message' => 'Selected notifications deleted successfully',
        ]);
    }

    /**
     * Deletes a notification belonging to the authenticated user.
     *
     * @param string|int $id the ID of the notification to delete
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteRecord($id, Request $request)
    {
        $notification = $this->notificationQuery()->where('id', $id)->first();

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->deleteNotification();

        return response()->json(['message' => 'Notification deleted successfully'], 200);
    }

    /**
     * Base query for notifications owned by the authenticated user.
     */
    protected function notificationQuery()
    {
        return Notification::where('notifiable_id', session('user'))
            ->where('notifiable_type', User::class);
    }

    /**
     * Get the list of registered notifications from the NotificationRegistry.
     *
     * @return \Illuminate\Http\JsonResponse The JSON response
     */
    public function registry()
    {
        return response()->json(NotificationRegistry::getNotificationsByPackage('core'));
    }

    /**
     * Get list of all valid notifiables.
     *
     * @return \Illuminate\Http\JsonResponse The JSON response
     */
    public function notifiables()
    {
        return response()->json(NotificationRegistry::getNotifiables());
    }

    /**
     * Save user notification settings.
     *
     * @param Request $request the HTTP request object containing the notification settings data
     *
     * @return \Illuminate\Http\JsonResponse a JSON response
     *
     * @throws \Exception if the provided notification settings data is not an array
     */
    public function saveSettings(Request $request)
    {
        $notificationSettings = $request->input('notificationSettings');
        if (!is_array($notificationSettings)) {
            throw new \Exception('Invalid notification settings data.');
        }
        $currentNotificationSettings = Setting::lookupCompany('notification_settings', []);
        Setting::configureCompany('notification_settings', array_merge($currentNotificationSettings, $notificationSettings));

        return response()->json([
            'status'  => 'ok',
            'message' => 'Notification settings succesfully saved.',
        ]);
    }

    /**
     * Retrieve and return the notification settings for the user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSettings()
    {
        $notificationSettings = Setting::lookupCompany('notification_settings');

        return response()->json([
            'status'               => 'ok',
            'message'              => 'Notification settings successfully fetched.',
            'notificationSettings' => $notificationSettings,
        ]);
    }
}
