<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds columns to the schedules table to support the rolling materialization engine.
     *
     * - last_materialized_at: timestamp of the last successful materialization run
     * - materialization_horizon: the furthest date up to which items have been materialized
     *
     * @return void
     */
    public function up()
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->timestamp('last_materialized_at')->nullable()->after('status')
                ->comment('Timestamp of the last successful RRULE materialization run');
            $table->date('materialization_horizon')->nullable()->after('last_materialized_at')
                ->comment('The furthest future date up to which ScheduleItems have been generated');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['last_materialized_at', 'materialization_horizon']);
        });
    }
};
