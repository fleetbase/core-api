<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Short-lived, single-use tokens for the three legs of the OAuth flow:
     *
     *   authorization       — CSRF state + PKCE verifier, issued at /redirect, consumed at /callback
     *   handoff             — carries the resolved profile from /callback to /exchange
     *   registration_intent — proves a verified provider identity to the signup endpoints
     *
     * This is a table rather than a cache entry for a correctness reason, not a stylistic one:
     * api/.env.example ships CACHE_DRIVER=file, so with more than one app node the /redirect leg
     * and the /callback leg would not share a store and the flow would break outright. Laravel's
     * FileStore also has no atomic add(), so single-use could not be enforced. A table gives
     * cross-node correctness and atomic single-use on every driver.
     *
     * Only the sha256 of each token is stored — the same property Sanctum relies on, so a
     * database dump yields no usable tokens.
     */
    public function up(): void
    {
        Schema::create('oauth_states', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->index();

            $table->string('purpose', 24)->index();
            $table->char('token_hash', 64)->unique();

            $table->string('provider', 40)->nullable()->index();
            $table->string('intent', 16)->nullable();

            // Set for link flows (the already-authenticated user) and once a registration
            // intent has been redeemed. Null for anonymous login/signup authorization rows.
            $table->foreignUuid('user_uuid')->nullable()->references('uuid')->on('users')->onUpdate('CASCADE')->onDelete('CASCADE');

            // Encrypted JSON: PKCE verifier, return path, resolved provider profile.
            $table->text('payload')->nullable();

            // HMAC of the originating IP. Never the raw address.
            $table->char('ip_hash', 64)->nullable();

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_states');
    }
};
