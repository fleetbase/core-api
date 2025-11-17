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
        Schema::create('schedule_constraints', function (Blueprint $table) {
            $table->increments('id');
            $table->string('_key')->nullable();
            $table->string('uuid', 191)->nullable()->index();
            $table->string('company_uuid', 191)->nullable()->index();
            $table->string('subject_uuid', 191)->nullable()->index();
            $table->string('subject_type')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('type', 50)->nullable()->index()->comment('e.g., hos, labor, business, capacity');
            $table->string('category', 50)->nullable()->index()->comment('e.g., compliance, optimization');
            $table->string('constraint_key', 100)->nullable()->index();
            $table->text('constraint_value')->nullable();
            $table->string('jurisdiction', 50)->nullable()->comment('e.g., US-Federal, US-CA, EU');
            $table->integer('priority')->default(0)->comment('Higher = more important');
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['uuid']);
            $table->index(['type', 'category', 'is_active']);
            $table->index(['company_uuid', 'is_active']);
            $table->index(['subject_uuid', 'subject_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('schedule_constraints');
    }
};
