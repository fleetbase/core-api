<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fleetbase Hosted Routing keys at install time.
 *
 * With the admin's consent (the first-admin onboarding choice, or an admin
 * running `php artisan routing:register`), the install registers with
 * Fleetbase Cloud and gets a free routing key, without a Cloud account. The
 * request carries this install's runtime ID and secret (InstanceIdentity),
 * the admin's email, the install's URL and the Fleetbase version. Nothing
 * else, and it is never sent as part of telemetry.
 *
 * On success the key goes into the system `valhalla` setting, the one the
 * Valhalla extension's panel in Admin → Fleet-Ops → Routing edits, with the
 * hosted API host. Fleet-Ops then uses it for distance, ETAs and map routes.
 * The registration itself (plan, claim link for upgrading, email) is kept in
 * `system.hosted-routing` for that panel. The key is never stored there.
 *
 * ROUTING_AUTO_REGISTER=false turns it off: the onboarding choice is not
 * offered and the command refuses unless forced.
 */
class HostedRouting
{
    public const REGISTRATION_KEY = 'system.hosted-routing';
    public const VALHALLA_KEY     = 'valhalla';

    public const STATUS_REGISTERED = 'registered';
    public const STATUS_SKIPPED    = 'skipped';
    public const STATUS_FAILED     = 'failed';

    public static function isEnabled(): bool
    {
        return (bool) config('fleetbase.hosted_routing.auto_register', true);
    }

    /**
     * Whether the onboarding step should offer hosted routing: on, and no
     * Valhalla server of the admin's own is configured.
     */
    public static function isAvailable(): bool
    {
        return static::isEnabled() && !static::usesOwnServer(static::valhallaSettings());
    }

    /**
     * The recorded registration, or null when this install never registered.
     */
    public static function registration(): ?array
    {
        $registration = Setting::lookup(static::REGISTRATION_KEY);

        return is_array($registration) ? $registration : null;
    }

    /**
     * Whether a host is Fleetbase Hosted Routing (routing.fleetbase.io or a
     * subdomain of it).
     */
    public static function isHostedHost(?string $host): bool
    {
        $hostname = strtolower((string) parse_url((string) $host, PHP_URL_HOST));
        $domain   = strtolower((string) config('fleetbase.hosted_routing.domain', 'routing.fleetbase.io'));

        return $hostname !== '' && $domain !== '' && ($hostname === $domain || str_ends_with($hostname, '.' . $domain));
    }

    /**
     * Register this install and store its key.
     *
     * Idempotent: an install that already holds its hosted key is skipped,
     * and one whose own Valhalla server is configured is left alone. $force
     * registers anyway (a new key replaces the old one) and ignores
     * ROUTING_AUTO_REGISTER.
     *
     * @return array{status: string, reason?: string, message: string, plan?: ?string, email?: string, email_verified?: bool, claim_url?: ?string}
     */
    public static function register(?string $email = null, ?string $domain = null, bool $force = false): array
    {
        $valhalla = static::valhallaSettings();

        if (!$force && !static::isEnabled()) {
            return static::result(static::STATUS_SKIPPED, 'disabled', 'Hosted routing registration is turned off (ROUTING_AUTO_REGISTER=false).');
        }

        if (!$force && static::registration() !== null && !empty($valhalla['api_key']) && static::isHostedHost($valhalla['api_host'] ?? null)) {
            return static::result(static::STATUS_SKIPPED, 'already_registered', 'This install already has a Fleetbase Hosted Routing key.');
        }

        if (!$force && static::usesOwnServer($valhalla)) {
            return static::result(static::STATUS_SKIPPED, 'own_server', 'A Valhalla server is already configured; it was left unchanged.');
        }

        $email ??= User::where('type', 'admin')->whereNotNull('email')->orderBy('created_at')->value('email');
        if (empty($email)) {
            return static::result(static::STATUS_FAILED, 'no_email', 'No administrator email to register with. Pass --email.');
        }

        try {
            $response = Http::timeout((int) config('fleetbase.hosted_routing.timeout', 10))
                ->acceptJson()
                ->asJson()
                ->post((string) config('fleetbase.hosted_routing.registration_url'), [
                    'product'         => 'routing',
                    'instance_id'     => InstanceIdentity::id(),
                    'instance_secret' => InstanceIdentity::secret(),
                    'email'           => $email,
                    'domain'          => $domain ?? config('app.url'),
                    'version'         => (string) config('fleetbase.version'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Hosted routing registration failed: ' . $e->getMessage());

            return static::result(static::STATUS_FAILED, 'unreachable', 'Fleetbase Cloud could not be reached. Try again later.');
        }

        $body = $response->json();
        if (!$response->successful() || !is_array($body) || empty($body['key'])) {
            $reason  = is_array($body) ? ($body['reason'] ?? null) : null;
            $message = is_array($body) ? ($body['error'] ?? null) : null;
            Log::warning('Hosted routing registration refused: ' . $response->status() . ' ' . ($reason ?? 'no reason'));

            return static::result(static::STATUS_FAILED, $reason ?? 'refused', $message ?? 'Registration was refused (HTTP ' . $response->status() . ').');
        }

        $apiHost = ($body['api_host'] ?? null) ?: config('fleetbase.hosted_routing.api_host');

        Setting::configure(static::VALHALLA_KEY, array_merge($valhalla, [
            'api_host' => $apiHost,
            'api_key'  => $body['key'],
        ]));

        $registration = [
            'license'        => data_get($body, 'license.uuid'),
            'key_hint'       => substr((string) $body['key'], -4),
            'plan'           => $body['plan'] ?? null,
            'email'          => $email,
            'email_verified' => (bool) ($body['email_verified'] ?? false),
            'claimed'        => (bool) ($body['claimed'] ?? false),
            'claim_url'      => $body['claim_url'] ?? null,
            'api_host'       => $apiHost,
            'registered_at'  => now()->toIso8601String(),
        ];
        Setting::configure(static::REGISTRATION_KEY, $registration);

        return static::result(static::STATUS_REGISTERED, null, 'Fleetbase Hosted Routing is on.') + [
            'plan'           => $registration['plan'],
            'email'          => $email,
            'email_verified' => $registration['email_verified'],
            'claim_url'      => $registration['claim_url'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function valhallaSettings(): array
    {
        $settings = Setting::lookup(static::VALHALLA_KEY, []);

        return is_array($settings) ? $settings : [];
    }

    /**
     * A Valhalla host of the admin's own, other than the public OSM demo
     * servers (Fleet-Ops never uses those) and the hosted service.
     *
     * @param array<string, mixed> $valhalla
     */
    protected static function usesOwnServer(array $valhalla): bool
    {
        $host = trim((string) ($valhalla['api_host'] ?? ''));
        if ($host === '' || static::isHostedHost($host)) {
            return false;
        }

        return !str_contains(strtolower((string) parse_url($host, PHP_URL_HOST)), 'openstreetmap.de');
    }

    /**
     * @return array<string, string>
     */
    protected static function result(string $status, ?string $reason, string $message): array
    {
        return array_filter(['status' => $status, 'reason' => $reason, 'message' => $message], fn ($value) => $value !== null);
    }
}
