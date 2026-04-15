<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->string('company_uuid', 191)->nullable()->index()->after('uuid');
        });

        // Backfill from the parent schedule
        DB::statement("
            UPDATE schedule_items si
            JOIN schedules s ON s.uuid = si.schedule_uuid
            SET si.company_uuid = s.company_uuid
            WHERE si.company_uuid IS NULL
              AND si.schedule_uuid IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->dropColumn('company_uuid');
        });
    }
};
