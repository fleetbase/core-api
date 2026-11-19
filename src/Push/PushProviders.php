<?php

namespace Fleetbase\Push;

use Fleetbase\Models\UserDevice;
use Fleetbase\Push\Contracts\PushProvider;
use Fleetbase\Push\Transports\ApnTransport;
use Fleetbase\Push\Transports\FcmTransport;

/**
 * The push services push apps can send through, by key: `apn` (Apple Push Notification
 * service) and `fcm` (Firebase Cloud Messaging), plus any an extension registers. A push app
 * stores its settings for each under the same key, and a device records which one issued its
 * token in `push_provider`.
 */
class PushProviders
{
    /** @var array<string, class-string<PushProvider>|PushProvider> */
    protected static array $registered = ['apn' => ApnTransport::class, 'fcm' => FcmTransport::class];

    /** @var array<string, string> the names apps send, by the key they mean */
    protected const ALIASES = ['apns' => 'apn', 'apple' => 'apn', 'firebase' => 'fcm', 'gcm' => 'fcm'];

    /** @var array<string, class-string<PushProvider>|PushProvider> */
    protected array $providers;

    /**
     * @param array<string, class-string<PushProvider>|PushProvider>|null $providers the
     *                                                                               registered ones when null
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? static::$registered;
    }

    /**
     * @param class-string<PushProvider>|PushProvider $provider
     */
    public static function register(string $key, string|PushProvider $provider): void
    {
        static::$registered[strtolower($key)] = $provider;
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function get(?string $key): ?PushProvider
    {
        $key = static::normalize($key);
        if (!$key || !isset($this->providers[$key])) {
            return null;
        }
        if (is_string($this->providers[$key])) {
            $this->providers[$key] = app($this->providers[$key]);
        }

        return $this->providers[$key];
    }

    /**
     * The provider key for a name an app sends (`apns`, `firebase`, …), or null.
     */
    public static function normalize(?string $key): ?string
    {
        $key = strtolower(trim((string) $key));

        return $key === '' ? null : (self::ALIASES[$key] ?? $key);
    }

    /**
     * Which provider issued a device's token: the one the device registered with, else the
     * one that recognizes the token (an FCM token from an iOS app that uses Firebase), else
     * the one for the device's platform.
     */
    public function forDevice(object $device): ?string
    {
        $declared = static::normalize($device->push_provider ?? null);
        if ($declared && isset($this->providers[$declared])) {
            return $declared;
        }

        $token = (string) ($device->token ?? '');
        foreach ($this->keys() as $key) {
            if ($token !== '' && $this->get($key)->recognizes($token)) {
                return $key;
            }
        }

        $platform = UserDevice::normalizePlatform($device->platform ?? null) ?? (strtolower(trim((string) ($device->platform ?? ''))) ?: null);
        foreach ($this->keys() as $key) {
            if ($platform && in_array($platform, $this->get($key)->platforms(), true)) {
                return $key;
            }
        }

        return null;
    }
}
