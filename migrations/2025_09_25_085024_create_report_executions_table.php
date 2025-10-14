<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('report_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('report_uuid');
            $table->uuid('user_uuid')->nullable();
            $table->uuid('company_uuid')->nullable();
            $table->decimal('execution_time', 10, 2)->nullable()->comment('Execution time in milliseconds');
            $table->integer('result_count')->default(0);
            $table->json('query_config')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('report_uuid');
            $table->index('user_uuid');
            $table->index('status');
            $table->index('started_at');
            $table->index(['report_uuid', 'status']);

            // Foreign key constraints
            $table->foreign('report_uuid')->references('uuid')->on('reports')->onDelete('cascade');
            $table->foreign('user_uuid')->references('uuid')->on('users')->onDelete('set null');
            $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_executions');
    }
};
