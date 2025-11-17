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
        Schema::create('schedule_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->string('company_uuid', 191)->nullable()->index();
            $table->string('subject_uuid', 191)->nullable()->index();
            $table->string('subject_type')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->integer('duration')->nullable()->comment('Duration in minutes');
            $table->integer('break_duration')->nullable()->comment('Break duration in minutes');
            $table->text('rrule')->nullable()->comment('RFC 5545 recurrence rule');
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['uuid']);
            $table->index(['subject_uuid', 'subject_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('schedule_templates');
    }
};
