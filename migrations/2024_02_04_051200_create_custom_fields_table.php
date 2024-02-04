<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->primary();
            $table->uuid('company_uuid');
            $table->uuid('subject_uuid');
            $table->string('subject_type');
            $table->string('name');
            $table->string('label');
            $table->string('type');
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->text('default_value')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('meta')->nullable();
            $table->mediumText('description')->nullable();
            $table->mediumText('help_text')->nullable();
            $table->integer('order')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('custom_fields');
    }
};