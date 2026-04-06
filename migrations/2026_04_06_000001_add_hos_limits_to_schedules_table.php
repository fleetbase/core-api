<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-schedule Hours of Service (HOS) limits.
 *
 * These columns allow each schedule to override the global HOS defaults
 * configured in the scheduling settings. When NULL, the global defaults
 * (11h daily / 70h weekly) are used.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            // Per-schedule HOS limits (NULL = use global settings default)
            $table->unsignedTinyInteger('hos_daily_limit')->nullable()->after('timezone')
                ->comment('Max driving hours per day. NULL = use global default (11h).');
            $table->unsignedTinyInteger('hos_weekly_limit')->nullable()->after('hos_daily_limit')
                ->comment('Max driving hours per rolling 7-day period. NULL = use global default (70h).');
            // HOS data source — extensible for future integrations
            $table->string('hos_source', 50)->default('schedule')->after('hos_weekly_limit')
                ->comment('Source used to calculate HOS hours: schedule | telematics | manual');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['hos_daily_limit', 'hos_weekly_limit', 'hos_source']);
        });
    }
};
