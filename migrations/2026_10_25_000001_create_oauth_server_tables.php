<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Tables for Fleetbase acting as an OAuth 2.0 / OpenID Connect provider ("Continue with
     * Fleetbase"), on league/oauth2-server. Not to be confused with oauth_identities and
     * oauth_states, which are Fleetbase signing people in WITH Google, Microsoft and others.
     *
     * - oauth_clients: apps registered by an admin. The id is the public client_id.
     * - oauth_grants: one user's consent for one app and (optionally) one organization. Every
     *   code and token points at a grant, so revoking the grant revokes everything under it.
     * - oauth_auth_codes, oauth_access_tokens, oauth_refresh_tokens: league's token records,
     *   each carrying the grant and the organization the user picked on the consent screen.
     *
     * Tokens and codes are never stored in a form that can be replayed: league hands the client
     * an encrypted payload or a signed JWT, and these rows hold only the identifier inside it.
     */
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name');
            $table->text('description')->nullable();
            // bcrypt hash; null for a public client (a native or single-page app with no secret).
            $table->string('secret', 100)->nullable();
            $table->json('redirect_uris');
            $table->json('allowed_scopes');
            $table->string('logo_url')->nullable();
            $table->string('website_url')->nullable();
            $table->string('privacy_url')->nullable();
            $table->string('terms_url')->nullable();
            // Built by Fleetbase (AppHelm). Shown as "Made by Fleetbase" on the consent screen.
            $table->boolean('official')->default(false);
            // Development clients may use http://127.0.0.1 or http://[::1] on any port (RFC 8252).
            $table->boolean('development')->default(false);
            $table->char('created_by_uuid', 36)->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oauth_grants', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->foreignUuid('user_uuid')->references('uuid')->on('users')->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->char('client_id', 36);
            // Null for a sign-in-only grant (openid, profile, email).
            $table->char('company_uuid', 36)->nullable();
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('oauth_clients')->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->index(['user_uuid', 'client_id', 'company_uuid']);
            $table->index('company_uuid');
        });

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->char('grant_uuid', 36)->index();
            $table->char('client_id', 36);
            $table->char('user_uuid', 36);
            $table->char('company_uuid', 36)->nullable();
            $table->json('scopes');
            $table->string('nonce', 255)->nullable();
            // When the user last authenticated to the console; becomes the ID token's auth_time.
            $table->timestamp('auth_time')->nullable();
            $table->boolean('revoked')->default(false);
            $table->dateTime('expires_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->char('grant_uuid', 36)->index();
            $table->char('client_id', 36);
            $table->char('user_uuid', 36)->index();
            $table->char('company_uuid', 36)->nullable();
            // The code this token chain started from, so a replayed code can revoke it (RFC 6749 §4.1.2).
            $table->string('auth_code_id', 100)->nullable()->index();
            $table->json('scopes');
            $table->boolean('revoked')->default(false);
            $table->dateTime('expires_at');
            $table->timestamps();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->string('access_token_id', 100)->index();
            $table->char('grant_uuid', 36)->index();
            $table->boolean('revoked')->default(false);
            $table->dateTime('expires_at');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_grants');
        Schema::dropIfExists('oauth_clients');
    }
};
