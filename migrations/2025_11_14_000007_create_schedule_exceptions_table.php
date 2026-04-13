<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Stores explicit deviations from a driver's (or any subject's) recurring schedule.
     * This replaces the ambiguous schedule_availability table for time-off and absence tracking.
     *
     * Examples:
     *   - Approved annual leave: type=time_off, status=approved
     *   - Sick day: type=sick, status=approved
     *   - Shift swap (driver unavailable): type=swap, status=pending
     *   - Public holiday override: type=holiday, status=approved
     *
     * @return void
     */
    public function up()
    {
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->unique()->index();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->string('company_uuid', 191)->nullable()->index();

            // Polymorphic subject — the entity this exception applies to (e.g. Driver)
            $table->string('subject_uuid', 191)->nullable()->index();
            $table->string('subject_type')->nullable()->index();

            // Optional link to the schedule this exception belongs to
            $table->string('schedule_uuid', 191)->nullable()->index();

            // The date range the exception covers
            $table->timestamp('start_at')->nullable()->index();
            $table->timestamp('end_at')->nullable()->index();

            // Exception classification
            $table->string('type', 50)->nullable()->index()
                ->comment('e.g., time_off, sick, holiday, swap, training');

            // Workflow status
            $table->string('status', 50)->default('pending')->index()
                ->comment('pending | approved | rejected | cancelled');

            // Human-readable reason and optional notes
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();

            // Who approved/rejected the exception
            $table->string('reviewed_by_uuid', 191)->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();

            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->index(['subject_uuid', 'subject_type', 'start_at', 'end_at'], 'schedule_exception_subject_range_idx');
            $table->index(['company_uuid', 'status', 'start_at', 'end_at'], 'schedule_exception_company_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('schedule_exceptions');
    }
};
