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
        Schema::create('report_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('report_uuid')->nullable();
            $table->uuid('user_uuid');
            $table->uuid('company_uuid');
            $table->string('action'); // execute, export, create, update, delete, schedule
            $table->string('status'); // success, failed, pending
            $table->json('query_config')->nullable();
            $table->json('metadata')->nullable(); // Additional context data
            $table->text('error_message')->nullable();
            $table->integer('execution_time')->nullable();
            $table->integer('row_count')->nullable();
            $table->string('export_format')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['company_uuid', 'action']);
            $table->index(['user_uuid', 'action']);
            $table->index(['report_uuid', 'action']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');

            // Foreign key constraints
            $table->foreign('report_uuid')->references('uuid')->on('reports')->onDelete('set null');
            $table->foreign('user_uuid')->references('uuid')->on('users')->onDelete('cascade');
            $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('report_audit_logs');
    }
};
