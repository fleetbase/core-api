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
        Schema::create('schedule_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->index();
            $table->string('public_id', 191)->nullable()->unique()->index();
            $table->string('schedule_uuid', 191)->nullable()->index();
            $table->string('assignee_uuid', 191)->nullable()->index();
            $table->string('assignee_type')->nullable()->index();
            $table->string('resource_uuid', 191)->nullable()->index();
            $table->string('resource_type')->nullable()->index();
            $table->timestamp('start_at')->nullable()->index();
            $table->timestamp('end_at')->nullable()->index();
            $table->integer('duration')->nullable()->comment('Duration in minutes');
            $table->timestamp('break_start_at')->nullable();
            $table->timestamp('break_end_at')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'])->default('pending')->index();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['uuid']);
            $table->index(['schedule_uuid', 'start_at', 'end_at']);
            $table->index(['assignee_uuid', 'assignee_type', 'status']);
            $table->index(['resource_uuid', 'resource_type', 'start_at', 'end_at']);
            $table->index(['status', 'start_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('schedule_items');
    }
};
