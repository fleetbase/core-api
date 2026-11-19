<?php

namespace Fleetbase\Push;

/**
 * The APNs or FCM credentials of one app, as the push transports need them.
 *
 * `config` for APNs: `key_id`, `team_id`, `app_bundle_id`, `private_key_content` (the .p8 key)
 * and optionally `environment` (`production` or `sandbox`). For FCM: `credentials`, the
 * Firebase service account (JSON string or array), and optionally `android_channel_id`.
 */
final class PushCredentials
{
    public function __construct(public string $id, public string $name, public string $scheme, public array $config)
    {
    }
}
