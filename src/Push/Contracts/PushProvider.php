<?php

namespace Fleetbase\Push\Contracts;

use Fleetbase\Push\PushConfigurationException;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushOutcome;

/**
 * A push service a push app can send through, such as APNs or FCM. It checks the settings an
 * organization enters for it, summarizes them without their secrets, and sends to the device
 * tokens it issued. Register more with `PushProviders::register()`.
 */
interface PushProvider
{
    /**
     * The platforms whose devices use this provider when they don't say which issued their
     * token, for example `['ios']`.
     *
     * @return string[]
     */
    public function platforms(): array;

    /**
     * Whether a token can only have come from this provider, whatever platform the device
     * says it is. FCM tokens, for example, are recognized on iOS too.
     */
    public function recognizes(string $token): bool;

    /**
     * The settings that are secrets: never shown again, and kept when an edit leaves them
     * empty.
     *
     * @return string[]
     */
    public function secrets(): array;

    /**
     * The settings to save from what was entered, or null when everything was left empty to
     * switch the provider off. `$saved` is what's saved now, for secrets left empty.
     *
     * @throws PushConfigurationException with a message saying what to fix
     */
    public function configure(array $input, ?array $saved = null): ?array;

    /**
     * What the console shows of the saved settings, without secrets.
     */
    public function summary(array $config): array;

    /**
     * The environments to try in order for a device, or `[null]` when the provider has none.
     *
     * @return array<int, string|null>
     */
    public function environments(PushCredentials $credentials, ?string $deviceEnvironment = null): array;

    /**
     * @param string[] $tokens
     *
     * @return array<string, PushOutcome> keyed by token
     */
    public function send(PushCredentials $credentials, PushMessage $message, array $tokens, ?string $environment = null): array;
}
