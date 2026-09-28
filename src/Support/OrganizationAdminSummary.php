<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Company;
use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Organization diagnostics for the platform administration views.
 */
class OrganizationAdminSummary
{
    public static function usage(Company $company): array
    {
        $connection = $company->getConnection();
        $schema     = $connection->getSchemaBuilder();
        $usage      = ['users_count' => $company->users()->count()];

        foreach ([
            'drivers_count'           => 'drivers',
            'customers_count'         => 'contacts',
            'orders_count'            => 'orders',
            'api_requests_count'      => 'api_request_logs',
            'webhook_callbacks_count' => 'webhook_request_logs',
        ] as $key => $table) {
            // Fleet-Ops is optional. An unavailable module is distinct from zero records.
            if (!$schema->hasTable($table)) {
                $usage[$key] = null;
                continue;
            }

            $query = $connection->table($table)->where('company_uuid', $company->uuid);
            if ($schema->hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            if ($table === 'contacts') {
                $query->where('type', 'customer');
            }
            $usage[$key] = $query->count();
        }

        return $usage;
    }

    /**
     * Attach only non-secret authentication facts, using two queries for the entire page.
     * Reading the admin view must not create default 2FA settings for other users.
     */
    public static function attachAuthentication(Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $keys       = $users->map(fn ($user) => 'user.' . $user->uuid . '.2fa')->all();
        $settings   = Setting::whereIn('key', $keys)->get(['key', 'value'])->keyBy('key');
        $identities = OAuthIdentity::whereIn('user_uuid', $users->pluck('uuid'))->get(['user_uuid', 'provider'])->groupBy('user_uuid');

        foreach ($users as $user) {
            $settingsForUser = $settings->get('user.' . $user->uuid . '.2fa');
            $enabled         = filter_var(data_get($settingsForUser, 'value.enabled', false), FILTER_VALIDATE_BOOLEAN);
            $user->setAttribute('admin_authentication', [
                'two_factor_enabled' => $enabled,
                'two_factor_method'  => $enabled ? data_get($settingsForUser, 'value.method', 'email') : null,
                'oauth_providers'    => $identities->get($user->uuid, collect())->pluck('provider')->unique()->sort()->values()->all(),
            ]);
        }
    }
}
