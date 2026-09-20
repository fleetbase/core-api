<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * The legacy per-provider columns on `users`, added by
     * 2025_01_17_063714_add_social_login_columns_to_users_table, mapped to their provider id.
     *
     * `facebook` is included even though it is not in the supported provider set: the column
     * exists and may hold data, and losing it here would be irreversible.
     *
     * @var array<string, string>
     */
    private const LEGACY_COLUMNS = [
        'google_user_id'   => 'google',
        'apple_user_id'    => 'apple',
        'facebook_user_id' => 'facebook',
    ];

    /**
     * Run the migrations.
     *
     * Copies any existing per-provider subject ids into `oauth_identities`.
     *
     * `email_verified` is false and `provider_email` is null for every backfilled row: the
     * verification state of a historic value is unknown, and an unknown state must never be
     * recorded as verified — doing so would let a backfilled row satisfy the verified-email
     * checks in the OAuth flow.
     *
     * The legacy columns are NOT dropped. Downstream packages may still write them.
     */
    public function up(): void
    {
        if (!Schema::hasTable('oauth_identities') || !Schema::hasTable('users')) {
            return;
        }

        $now = Carbon::now();

        foreach (self::LEGACY_COLUMNS as $column => $provider) {
            if (!Schema::hasColumn('users', $column)) {
                continue;
            }

            DB::table('users')
                ->select('uuid', $column)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('uuid')
                ->chunk(500, function ($users) use ($column, $provider, $now) {
                    $rows = [];

                    foreach ($users as $user) {
                        if (empty($user->uuid)) {
                            continue;
                        }

                        $rows[] = [
                            'uuid'             => (string) Str::uuid(),
                            'user_uuid'        => $user->uuid,
                            'provider'         => $provider,
                            'provider_user_id' => (string) $user->{$column},
                            'provider_email'   => null,
                            'email_verified'   => false,
                            'meta'             => json_encode(['backfilled_from' => $column]),
                            'last_login_at'    => null,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ];
                    }

                    if ($rows !== []) {
                        // insertOrIgnore: a duplicate (provider, provider_user_id) must skip,
                        // not abort the migration.
                        DB::table('oauth_identities')->insertOrIgnore($rows);
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Removes only the rows this migration created, identified by the marker written into
     * `meta`. Identities created by an actual OAuth sign-in are left untouched.
     */
    public function down(): void
    {
        if (!Schema::hasTable('oauth_identities')) {
            return;
        }

        foreach (self::LEGACY_COLUMNS as $column => $provider) {
            DB::table('oauth_identities')
                ->where('provider', $provider)
                ->where('meta', json_encode(['backfilled_from' => $column]))
                ->delete();
        }
    }
};
