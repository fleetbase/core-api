<?php

namespace Fleetbase\Push;

/**
 * One push app's settings for one push provider, as the provider needs them to send.
 * `provider` is the provider's key (`apn`, `fcm`, …) and `config` its saved settings,
 * secrets included; see each provider for what they are.
 */
final class PushCredentials
{
    public function __construct(public string $id, public string $name, public string $provider, public array $config)
    {
    }
}
