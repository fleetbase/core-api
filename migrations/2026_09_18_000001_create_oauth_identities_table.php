<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Stores the link between a Fleetbase user and an external OAuth/OIDC identity.
     *
     * Authentication keys on (provider, provider_user_id) — never on email — so the unique
     * index below is a security control, not just a data-integrity one: it is what guarantees
     * a provider subject can never be claimed by two Fleetbase accounts.
     *
     * Deliberately NOT soft-deleting. MySQL unique indexes include soft-deleted rows, so a
     * soft-deleted identity would permanently block re-linking that same provider account —
     * which is exactly what a user does right after an accidental unlink. Unlinks are audited
     * through spatie/laravel-activitylog instead.
     */
    public function up(): void
    {
        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->index();
            $table->foreignUuid('user_uuid')->references('uuid')->on('users')->onUpdate('CASCADE')->onDelete('CASCADE');

            $table->string('provider', 40);
            // 191 keeps the composite unique index inside the utf8mb4 767-byte index limit.
            $table->string('provider_user_id', 191);

            // The address the provider reported at link time. Never authoritative for lookup;
            // kept so an admin can see which account an identity belongs to.
            $table->string('provider_email')->nullable();
            // Whether the provider ASSERTED the address as verified. Defaults false: an
            // unknown verification state must never be recorded as verified.
            $table->boolean('email_verified')->default(false);

            $table->json('meta')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id'], 'oauth_identities_provider_subject_unique');
            $table->index(['user_uuid', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');
    }
};
