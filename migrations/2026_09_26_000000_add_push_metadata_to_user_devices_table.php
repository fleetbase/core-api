<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Record which app a push token was registered from and which APNs
     * environment issued it, so push senders can pick the right credentials
     * instead of guessing: `app_identifier` is the uuid of the storefront (or
     * other app owner) the device registered through, `environment` is the
     * APNs environment (production or sandbox) of an iOS token, and
     * `last_seen_at` is when the device last re-registered its token.
     *
     * Every column is guarded so the migration is safe to run against a
     * table that already has some of them.
     */
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('user_devices', 'app_identifier')) {
                $table->string('app_identifier', 191)->nullable()->index()->after('platform');
            }

            if (!Schema::hasColumn('user_devices', 'environment')) {
                $table->string('environment', 32)->nullable()->after('app_identifier');
            }

            if (!Schema::hasColumn('user_devices', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            foreach (['app_identifier', 'environment', 'last_seen_at'] as $column) {
                if (Schema::hasColumn('user_devices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
