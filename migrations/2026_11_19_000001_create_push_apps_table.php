<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Push apps: an organization's own mobile apps, each with the APNs and FCM credentials
     * its pushes are sent with (stored encrypted). A device that registers with an app's key
     * is linked to it in `user_devices.push_app_uuid`.
     */
    public function up(): void
    {
        if (!Schema::hasTable('push_apps')) {
            Schema::create('push_apps', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->unique();
                $table->string('public_id')->nullable()->unique();
                $table->uuid('company_uuid')->nullable()->index();
                $table->uuid('created_by_uuid')->nullable();
                $table->string('name');
                $table->string('key')->unique();
                $table->text('apn')->nullable();
                $table->text('fcm')->nullable();
                $table->string('status', 32)->default('active');
                $table->uuid('owner_uuid')->nullable();
                $table->string('owner_type')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['owner_uuid', 'owner_type']);
            });
        }

        Schema::table('user_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('user_devices', 'push_app_uuid')) {
                $table->uuid('push_app_uuid')->nullable()->index()->after('user_uuid');
            }
            if (!Schema::hasColumn('user_devices', 'company_uuid')) {
                $table->uuid('company_uuid')->nullable()->index()->after('user_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            foreach (['push_app_uuid', 'company_uuid'] as $column) {
                if (Schema::hasColumn('user_devices', $column)) {
                    $table->dropIndex(['' . $column]);
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('push_apps');
    }
};
