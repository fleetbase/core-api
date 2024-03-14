<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Resize `api_credential_uuid` column to 120 chars for `api_events`
        Schema::table('api_events', function (Blueprint $table) {
            $table->string('api_credential_uuid', 120)->change();
        });

        // Resize `api_credential_uuid` column to 120 chars for `api_request_logs`
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->string('api_credential_uuid', 120)->change();
        });

        // Resize `api_credential_uuid` column to 120 chars for `webhook_request_logs`
        Schema::table('webhook_request_logs', function (Blueprint $table) {
            $table->string('api_credential_uuid', 120)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert `api_credential_uuid` column size back to 36 for `api_events`
        Schema::table('api_events', function (Blueprint $table) {
            $table->string('api_credential_uuid', 36)->change();
        });

        // Revert `api_credential_uuid` column size back to 36 for `api_request_logs`
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->string('api_credential_uuid', 36)->change();
        });

        // Revert `api_credential_uuid` column size back to 36 for `webhook_request_logs`
        Schema::table('webhook_request_logs', function (Blueprint $table) {
            $table->string('api_credential_uuid', 36)->change();
        });
    }
};
