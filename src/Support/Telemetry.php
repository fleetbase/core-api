<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Telemetry
{
    /**
     * The endpoint to send telemetry to.
     */
    protected static string $endpoint = 'https://telemetry.fleetbase.io/';

    /**
     * Cached IP info.
     */
    protected static ?array $ipInfo = null;

    /**
     * Whether telemetry is globally disabled.
     */
    protected static function isDisabled(): bool
    {
        return env('TELEMETRY_DISABLED', false) === true;
    }

    /**
     * Sends a telemetry event.
     *
     * @param array $payload custom telemetry data to send
     *
     * @return bool true on success, false on failure
     */
    public static function send(array $payload = []): bool
    {
        if (self::isDisabled()) {
            return false;
        }

        try {
            $ipinfo = self::getIpInfo();
            $tags   = [
                'fleetbase.instance_id:' . self::getInstanceId(),
                'fleetbase.company:' . self::getCompanyName(),
                'fleetbase.version:' . self::getVersion(),
                'fleetbase.domain:' . request()->getHost(),
                'fleetbase.api:' . config('app.url'),
                'fleetbase.console:' . config('fleetbase.console.host'),
                'fleetbase.app_name:' . config('app.name'),
                'fleetbase.version:' . config('fleetbase.version'),
                'php.version:' . PHP_VERSION,
                'laravel.version:' . app()->version(),
                'env:' . app()->environment(),
                'timezone:' . data_get($ipinfo, 'time_zone.name', 'unknown'),
                'region:' . data_get($ipinfo, 'region', 'unknown'),
                'country:' . data_get($ipinfo, 'country_name', 'unknown'),
                'country_code:' . data_get($ipinfo, 'country_code', 'unknown'),
                'installation_type:' . self::getInstallationType(),
                'users.count:' . self::countUsers(),
                'companies.count:' . self::countCompanies(),
                'orders.count:' . self::countOrders(),
                'source.modified:' . (self::isSourceModified() ? 'true' : 'false'),
                'source.commit_hash:' . self::getCurrentCommitHash(),
                'source.main_hash:' . self::getOfficialRepoCommitHash(),
            ];

            $defaultPayload = [
                'title'      => 'Fleetbase Instance Telemetry',
                'text'       => 'Periodic instance telemetry from Fleetbase',
                'tags'       => $tags,
                'alert_type' => 'info',
            ];

            $response = Http::timeout(5)->post(self::$endpoint, array_merge($defaultPayload, $payload));
            if ($response->successful()) {
                return true;
            }

            Log::warning('[Telemetry] Failed to send telemetry event.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Telemetry] Exception thrown:', ['message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Sends a telemetry ping once every 24 hours if in the production environment.
     *
     * This method checks when the last telemetry ping was sent using the cache.
     * If the last ping was more than 24 hours ago (or never sent), it triggers
     * a new telemetry event and updates the timestamp in the cache.
     */
    public static function ping(): void
    {
        Cache::remember(
            'telemetry:last_ping',
            now()->addHours(24),
            function () {
                try {
                    static::send();
                } catch (\Throwable $e) {
                    Log::warning('[Telemetry] Send failed: ' . $e->getMessage());
                }

                return now()->toDateTimeString();
            }
        );
    }

    /**
     * Get the instance UUID.
     */
    protected static function getInstanceId(): string
    {
        return config('fleetbase.instance_id', self::generateInstanceId());
    }

    /**
     * Get the primary company name for the instance.
     */
    protected static function getCompanyName(): string
    {
        $company = Auth::getCompany();
        if (!$company) {
            // default to first company
            $company = Company::first();
        }

        return $company ? $company->name : 'unknown';
    }

    /**
     * Get the current Fleetbase version.
     */
    protected static function getVersion(): string
    {
        return config('fleetbase.version', '0.7.1');
    }

    /**
     * Generates and stores a UUID for the instance if not already present.
     */
    public static function generateInstanceId(): string
    {
        $file = base_path('.fleetbase-id');

        if (!file_exists($file)) {
            file_put_contents($file, $uuid = Str::uuid()->toString());

            return $uuid;
        }

        return trim(file_get_contents($file));
    }

    /**
     * Detects installation environment type (docker, linux, etc).
     */
    public static function getInstallationType(): string
    {
        if (File::exists('/.dockerenv')) {
            return 'docker';
        }

        if (File::exists('/etc/lsb-release') && str_contains(file_get_contents('/etc/lsb-release'), 'Ubuntu')) {
            return 'linux-baremetal';
        }

        return 'unknown';
    }

    /**
     * Count the number of users in the system.
     */
    public static function countUsers(): int
    {
        return DB::table('users')->count();
    }

    /**
     * Count the number of companies in the system.
     */
    public static function countCompanies(): int
    {
        return DB::table('companies')->count();
    }

    /**
     * Count the number of orders in the system.
     */
    public static function countOrders(): int
    {
        return DB::table('orders')->count();
    }

    /**
     * Check if the source code has been modified from the expected Git commit hash.
     */
    public static function isSourceModified(): bool
    {
        $officialHash = self::getOfficialRepoCommitHash();
        $currentHash  = self::getCurrentCommitHash();

        if (!$officialHash || !$currentHash) {
            Log::info('[Telemetry] Skipping source verification: hash unavailable.', [
                'official' => $officialHash,
                'current'  => $currentHash,
            ]);

            return false;
        }

        return $officialHash !== $currentHash;
    }

    /**
     * Get the current commit hash from the running codebase.
     */
    public static function getCurrentCommitHash(): ?string
    {
        // @todo Implement a method to get current git hash
        return null;
    }

    /**
     * Fetch the official commit hash from GitHub.
     */
    public static function getOfficialRepoCommitHash(): ?string
    {
        try {
            $response = Http::acceptJson()->get('https://api.github.com/repos/fleetbase/fleetbase/commits/main');

            if ($response->successful()) {
                return data_get($response->json(), 'sha');
            }

            Log::warning('[Telemetry] Failed to fetch official commit hash from GitHub.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Telemetry] GitHub commit hash lookup failed.', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Lookup IP metadata using the configured geo service.
     */
    protected static function getIpInfo(): array
    {
        if (self::$ipInfo !== null) {
            return self::$ipInfo;
        }

        try {
            self::$ipInfo = \Fleetbase\Support\Http::lookupIp();
        } catch (\Throwable $e) {
            Log::warning('[Telemetry] IP lookup failed: ' . $e->getMessage());
            self::$ipInfo = [];
        }

        return self::$ipInfo;
    }
}
