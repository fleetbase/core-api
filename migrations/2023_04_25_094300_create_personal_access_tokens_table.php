<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum 4 no longer loads its own migrations, and
 * 2023_04_25_094311_fix_personal_access_tokens needs this table.
 *
 * On Sanctum 3, its 2019_12_14_000001 migration sorts first and creates the
 * table, as it did on every existing install, so this does nothing there. The
 * sandbox database has never had this table: sandbox:migrate only runs package
 * paths, and fix_personal_access_tokens skips it too.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (config('database.default') === config('fleetbase.connection.sandbox')) {
            return;
        }

        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty: this migration may not have created the table, and
     * rolling it back must not delete live tokens.
     */
    public function down(): void
    {
    }
};
