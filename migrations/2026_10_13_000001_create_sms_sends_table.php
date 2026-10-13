<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * The SMS send ledger: one row per send the guard allowed and per send it refused,
     * with the tenant, the purpose, where the request came from and how the provider
     * answered. It is what lets an incident be traced from the database in one query,
     * and what the breaker's new-country and usage views read.
     */
    public function up(): void
    {
        Schema::create('sms_sends', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->char('company_uuid', 36)->nullable()->index();
            $table->string('tenant_type', 32)->nullable();
            $table->char('tenant_uuid', 36)->nullable()->index();
            $table->string('purpose', 64)->index();
            $table->string('source', 32);
            $table->string('destination', 32)->nullable();
            $table->string('destination_hash', 64)->nullable()->index();
            $table->char('destination_country', 3)->nullable()->index();
            $table->string('destination_type', 32)->nullable();
            $table->string('status', 16)->index();
            $table->string('block_reason', 64)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->text('error')->nullable();
            $table->string('subject_type')->nullable();
            $table->char('subject_uuid', 36)->nullable();
            $table->char('verification_code_uuid', 36)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 512)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['company_uuid', 'created_at']);
            $table->index(['tenant_uuid', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_sends');
    }
};
