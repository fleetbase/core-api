<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Give alerts the columns a triage queue needs: when a snooze ends, who
     * set it, who owns the alert, and when the owner planned to handle it.
     *
     * `Alert::snooze()` used to keep `snoozed_until` inside the `meta` JSON,
     * which meant a list of open-but-not-snoozed alerts had to load every row
     * and ask `isSnoozed()` one at a time. A real column can be indexed and
     * queried, and `assigned_to_uuid` gives an alert an owner distinct from
     * whoever acknowledged or resolved it. `planned_at` is a time the owner
     * chose to deal with it — a scheduling hint, not a due date.
     *
     * Every column is guarded so the migration is safe to run against a
     * table that already has some of them.
     */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            if (!Schema::hasColumn('alerts', 'snoozed_until')) {
                $table->timestamp('snoozed_until')->nullable()->index()->after('acknowledged_at');
            }

            if (!Schema::hasColumn('alerts', 'snoozed_by_uuid')) {
                $table->foreignUuid('snoozed_by_uuid')->nullable()->after('resolved_by_uuid')->constrained('users', 'uuid')->nullOnDelete();
            }

            if (!Schema::hasColumn('alerts', 'assigned_to_uuid')) {
                $table->foreignUuid('assigned_to_uuid')->nullable()->after('snoozed_by_uuid')->constrained('users', 'uuid')->nullOnDelete();
            }

            if (!Schema::hasColumn('alerts', 'planned_at')) {
                $table->timestamp('planned_at')->nullable()->index()->after('snoozed_until');
            }
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->index(['company_uuid', 'status', 'snoozed_until'], 'alerts_company_status_snoozed_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex('alerts_company_status_snoozed_index');
        });

        Schema::table('alerts', function (Blueprint $table) {
            foreach (['snoozed_by_uuid', 'assigned_to_uuid'] as $column) {
                if (Schema::hasColumn('alerts', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['planned_at', 'snoozed_until'] as $column) {
                if (Schema::hasColumn('alerts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
