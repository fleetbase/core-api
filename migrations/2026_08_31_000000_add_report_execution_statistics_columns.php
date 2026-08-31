<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add the running-statistics columns Report::updateExecutionStats writes.
     *
     * The report builder's execution statistics were written but never migrated.
     * `Report::updateExecutionStats()` sets `execution_count`, `average_execution_time`
     * and `last_result_count` on every run, `getPerformanceMetrics()` returns all three,
     * and `cloneWithConfig()` resets them — but the reports table has none of them, so
     * the save at the end of updateExecutionStats fails:
     *
     *     SQLSTATE[42S22]: Column not found: 1054
     *     Unknown column 'execution_count' in 'field list'
     *
     * The effect is that **every saved report fails to execute**, in every extension.
     * The query itself succeeds — the failure happens afterwards, while recording that
     * it ran — so the caller sees an error for a report that actually worked, and the
     * report builder's preview shows an empty result with no explanation.
     *
     * These are distinct from `execution_time` and `row_count`, which
     * 2025_09_25_084135_report_enhancements added and the API resource exposes: those
     * describe the last run, while these accumulate across runs.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->unsignedInteger('execution_count')->default(0)->after('row_count');

            // A mean of integer millisecond timings, so it needs somewhere for the
            // fraction to go — `execution_time` is an integer because it holds one
            // measurement rather than an average of several.
            $table->float('average_execution_time')->nullable()->comment('Mean execution time in milliseconds across all runs')->after('execution_count');

            $table->integer('last_result_count')->nullable()->after('average_execution_time');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn([
                'execution_count',
                'average_execution_time',
                'last_result_count',
            ]);
        });
    }
};
