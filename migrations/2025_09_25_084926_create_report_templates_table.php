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
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('public_id')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->default('custom');
            $table->json('query_config');
            $table->json('default_parameters')->nullable();
            $table->json('required_parameters')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_system')->default(false);
            $table->uuid('company_uuid')->nullable();
            $table->uuid('created_by_uuid');
            $table->uuid('updated_by_uuid')->nullable();
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['company_uuid', 'category']);
            $table->index(['is_public', 'category']);
            $table->index(['is_system', 'category']);
            $table->index('created_by_uuid');
            $table->index('usage_count');
            $table->index('last_used_at');

            // Foreign key constraints
            $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
            $table->foreign('created_by_uuid')->references('uuid')->on('users')->onDelete('cascade');
            $table->foreign('updated_by_uuid')->references('uuid')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('report_templates');
    }
};
