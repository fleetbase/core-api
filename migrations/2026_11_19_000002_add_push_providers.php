<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * `user_devices.push_provider`: the push provider that issued a device's token (`apn` or
     * `fcm`), which isn't the platform's usual one when an iOS app gets its token from
     * Firebase. Also moves a push app's `apn` and `fcm` columns, from an early build of the
     * push apps migration, into `providers`.
     */
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('user_devices', 'push_provider')) {
                $table->string('push_provider', 32)->nullable()->after('platform');
            }
        });

        if (!Schema::hasTable('push_apps') || !Schema::hasColumn('push_apps', 'apn') || !Schema::hasColumn('push_apps', 'fcm')) {
            return;
        }
        if (!Schema::hasColumn('push_apps', 'providers')) {
            Schema::table('push_apps', fn (Blueprint $table) => $table->text('providers')->nullable()->after('key'));
        }

        foreach (DB::table('push_apps')->get(['id', 'apn', 'fcm']) as $row) {
            $providers = [];
            foreach (['apn', 'fcm'] as $key) {
                $config = $row->{$key} ? json_decode(Crypt::decryptString($row->{$key}), true) : null;
                if (is_array($config)) {
                    $providers[$key] = $config;
                }
            }
            DB::table('push_apps')->where('id', $row->id)->update(['providers' => $providers ? Crypt::encryptString(json_encode($providers)) : null]);
        }

        Schema::table('push_apps', fn (Blueprint $table) => $table->dropColumn(['apn', 'fcm']));
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            if (Schema::hasColumn('user_devices', 'push_provider')) {
                $table->dropColumn('push_provider');
            }
        });
    }
};
