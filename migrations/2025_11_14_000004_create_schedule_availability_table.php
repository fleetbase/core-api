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
        Schema::create('schedule_availability', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->index();
            $table->string('subject_uuid', 191)->nullable()->index();
            $table->string('subject_type')->nullable()->index();
            $table->timestamp('start_at')->nullable()->index();
            $table->timestamp('end_at')->nullable()->index();
            $table->boolean('is_available')->default(true)->index();
            $table->tinyInteger('preference_level')->nullable()->comment('1-5 preference strength');
            $table->text('rrule')->nullable()->comment('RFC 5545 recurrence rule');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['uuid']);
            $table->index(['subject_uuid', 'subject_type', 'start_at', 'end_at', 'is_available'], 'schedule_subject_availability_composite_idx');
            $table->index(['subject_type', 'is_available', 'start_at', 'end_at'], 'schedule_availability_composite_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('schedule_availability');
    }
};
