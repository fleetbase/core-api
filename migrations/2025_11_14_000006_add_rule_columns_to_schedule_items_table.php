<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            // Link back to the ScheduleTemplate that generated this item (nullable for standalone items)
            $table->string('template_uuid', 191)->nullable()->after('schedule_uuid')->index()
                ->comment('The ScheduleTemplate that generated this item via RRULE expansion');

            // Flags for recurrence management
            $table->boolean('is_exception')->default(false)->after('status')->index()
                ->comment('True when this item has been manually edited and should not be overwritten by re-materialization');

            $table->string('exception_for_date', 20)->nullable()->after('is_exception')
                ->comment('The original RRULE occurrence date (YYYY-MM-DD) this item is an exception for');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->dropColumn(['template_uuid', 'is_exception', 'exception_for_date']);
        });
    }
};
