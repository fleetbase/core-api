<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Each webhook endpoint gets its own signing secret (stored encrypted), so receivers can
     * verify deliveries with a value they were given instead of the API credential's secret.
     * Existing endpoints get a secret the first time one is needed.
     */
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->text('secret')->nullable()->after('version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn('secret');
        });
    }
};
