<?php

namespace Fleetbase\Push;

use Fleetbase\Models\PushApp;
use Fleetbase\Models\UserDevice;
use Fleetbase\Push\Contracts\SendsToPushApps;
use Fleetbase\Push\Transports\ApnTransport;
use Fleetbase\Push\Transports\FcmTransport;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Sends a notification to the notifiable's devices that registered with a push app, each
 * with its own app's APNs or FCM credentials.
 *
 * Devices registered without a push app are left to the install-wide `ApnChannel` and
 * `FcmChannel`, whose routes leave out push-app devices, so no device gets a push twice.
 * For iOS a device that registered its APNs environment is sent to it, otherwise
 * production then sandbox. Dead tokens are pruned and every failure is logged; nothing is
 * thrown, so a push failure never stops the notification's other channels.
 */
class PushAppChannel
{
    public function __construct(protected FcmTransport $fcm, protected ApnTransport $apn)
    {
    }

    /**
     * @return array<string, PushOutcome> the outcome for each device token
     */
    public function send($notifiable, Notification $notification): array
    {
        if (!$notification instanceof SendsToPushApps) {
            return [];
        }

        try {
            $devices = static::devicesFor($notifiable);
            $message = $devices->isEmpty() ? null : $notification->toPushApp($notifiable);
            if (!$message) {
                return [];
            }
            $apps = PushApp::whereIn('uuid', $devices->pluck('push_app_uuid')->unique()->values()->all())->where('status', 'active')->get()->keyBy('uuid');
        } catch (\Throwable $e) {
            static::log('error', 'Unable to prepare the push.', ['notification' => get_class($notification), 'error' => $e->getMessage()]);

            return [];
        }

        $outcomes = [];
        foreach ($devices->groupBy('push_app_uuid') as $appUuid => $appDevices) {
            $app = $apps->get($appUuid);
            if (!$app) {
                static::log('warning', 'The push app of these devices is missing or switched off; skipping them.', ['push_app' => $appUuid, 'devices' => $appDevices->count()]);
                continue;
            }

            foreach ($appDevices->groupBy(fn ($device) => UserDevice::normalizePlatform($device->platform) ?? '') as $platform => $group) {
                $scheme      = $platform === 'ios' ? 'apn' : ($platform === 'android' ? 'fcm' : null);
                $credentials = $scheme ? $app->credentials($scheme) : null;
                if (!$credentials) {
                    static::log('warning', 'The push app has no credentials for this platform; skipping its devices.', ['push_app' => $app->public_id, 'platform' => $platform, 'devices' => $group->count()]);
                    continue;
                }

                $outcomes += $scheme === 'apn' ? $this->sendToIos($credentials, $group, $message) : $this->deliver($credentials, $group, [null], $message);
            }
        }

        return $outcomes;
    }

    protected function sendToIos(PushCredentials $credentials, Collection $devices, PushMessage $message): array
    {
        $outcomes = [];
        foreach ($devices->groupBy(fn ($device) => $device->environment ?? '') as $environment => $group) {
            $outcomes += $this->deliver($credentials, $group, ApnClientFactory::environments($credentials, $environment ?: null), $message);
        }

        return $outcomes;
    }

    /**
     * Sends to each environment in turn, moving on only with the tokens the provider says
     * belong to another one.
     *
     * @param array<int, string|null> $environments
     */
    protected function deliver(PushCredentials $credentials, Collection $devices, array $environments, PushMessage $message): array
    {
        $byToken  = $devices->keyBy('token');
        $pending  = $byToken->keys()->all();
        $outcomes = [];

        foreach ($environments as $environment) {
            if (empty($pending)) {
                break;
            }

            try {
                $results = $credentials->scheme === 'apn' ? $this->apn->send($credentials, $environment, $message, $pending) : $this->fcm->send($credentials, $message, $pending);
            } catch (\Throwable $e) {
                static::log('error', 'The push provider request failed.', ['push_app' => $credentials->id, 'scheme' => $credentials->scheme, 'environment' => $environment, 'error' => $e->getMessage()]);
                foreach ($pending as $token) {
                    $outcomes[$token] = PushOutcome::error($e->getMessage());
                }

                return $outcomes;
            }

            $retry = [];
            foreach ($pending as $token) {
                $outcomes[$token] = $results[$token] ?? PushOutcome::error('No response from the provider.');
                if ($outcomes[$token]->is(PushOutcome::WRONG_ENVIRONMENT)) {
                    $retry[] = $token;
                }
            }
            $pending = $retry;
        }

        // Rejected as a bad token in every environment: the token is invalid.
        foreach ($pending as $token) {
            $outcomes[$token] = PushOutcome::dead($outcomes[$token]->reason ?? 'BadDeviceToken');
        }

        foreach ($outcomes as $token => $outcome) {
            $device = $byToken->get($token);
            if ($outcome->is(PushOutcome::DEAD)) {
                static::prune($device, $outcome->reason);
            } elseif (!$outcome->is(PushOutcome::SENT)) {
                static::log('warning', 'A push was not delivered.', ['device' => $device?->public_id, 'push_app' => $credentials->id, 'status' => $outcome->status, 'reason' => $outcome->reason]);
            }
        }

        return $outcomes;
    }

    /**
     * The notifiable's active devices that registered with a push app.
     */
    public static function devicesFor($notifiable): Collection
    {
        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationForPushApp')) {
            $devices = collect($notifiable->routeNotificationForPushApp());
        } elseif (is_object($notifiable) && method_exists($notifiable, 'devices')) {
            $devices = collect($notifiable->devices);
        } else {
            return collect();
        }

        return $devices
            ->filter(fn ($device) => is_object($device) && !empty($device->token) && !empty($device->push_app_uuid) && !in_array($device->status, ['invalid', 'inactive'], true))
            ->unique('token')
            ->values();
    }

    protected static function prune($device, ?string $reason): void
    {
        if (!$device instanceof UserDevice) {
            return;
        }

        static::log('info', 'Removing a device whose push token is dead.', ['device' => $device->public_id, 'reason' => $reason]);
        try {
            $device->status = 'invalid';
            $device->save();
            $device->delete();
        } catch (\Throwable $e) {
            static::log('error', 'Unable to remove the device.', ['device' => $device->public_id, 'error' => $e->getMessage()]);
        }
    }

    protected static function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}('[Push apps] ' . $message, $context);
        } catch (\Throwable $e) {
            // Logging never stops delivery.
        }
    }
}
