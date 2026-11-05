<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * api_credentials.profile: limits what a key may reach, for keys that ship inside a public
     * app. Null means unrestricted, which every existing key keeps. The profiles are in
     * config/api.php.
     */
    public function up(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->string('profile', 50)->nullable()->after('api');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->dropColumn('profile');
        });
    }
};
