<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds two columns that were referenced in the ScheduleTemplate model
     * but omitted from the original create migration:
     *
     *   - schedule_uuid: links an applied template copy to its parent Schedule
     *     (NULL for library/reusable templates, set when applyToSchedule() is called)
     *   - color: hex colour string used by the frontend calendar to render shift blocks
     *
     * @return void
     */
    public function up()
    {
        Schema::table('schedule_templates', function (Blueprint $table) {
            // Add schedule_uuid after company_uuid to keep column order logical
            $table->string('schedule_uuid', 191)
                  ->nullable()
                  ->after('company_uuid')
                  ->index()
                  ->comment('UUID of the Schedule this template is applied to; NULL for library templates');

            // Add color after rrule
            $table->string('color', 20)
                  ->nullable()
                  ->after('rrule')
                  ->comment('Hex colour for calendar rendering, e.g. #6366f1');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('schedule_templates', function (Blueprint $table) {
            $table->dropIndex(['schedule_uuid']);
            $table->dropColumn(['schedule_uuid', 'color']);
        });
    }
};
