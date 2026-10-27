<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * - oauth_clients.software_id and registration_key: clients that registered themselves at
     *   POST /oauth/register (RFC 7591), one per install of a piece of software such as Fleetbase
     *   for WooCommerce. The key is a hash of the software and its redirect URI, so registering
     *   again finds the same client.
     * - api_credentials.oauth_client_id and oauth_installation: the app that created the key
     *   through the api-key:create scope, and the installation it was made for when one client
     *   serves many (one Shopify app, many shops). Asking again returns the same key, and revoking
     *   the app's last grant in the organization expires them.
     */
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->string('software_id', 100)->nullable()->after('development')->index();
            $table->char('registration_key', 64)->nullable()->after('software_id')->unique();
        });

        Schema::table('api_credentials', function (Blueprint $table) {
            $table->char('oauth_client_id', 36)->nullable()->after('api')->index();
            $table->string('oauth_installation', 191)->nullable()->after('oauth_client_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->dropIndex(['oauth_installation']);
            $table->dropIndex(['oauth_client_id']);
            $table->dropColumn(['oauth_installation', 'oauth_client_id']);
        });

        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropUnique(['registration_key']);
            $table->dropIndex(['software_id']);
            $table->dropColumn(['software_id', 'registration_key']);
        });
    }
};
