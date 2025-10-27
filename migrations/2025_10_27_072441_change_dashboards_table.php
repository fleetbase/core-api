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
        Schema::table('dashboards', function (Blueprint $table) {
            $table->json('meta')->after('is_default')->nullable();
            $table->json('options')->after('is_default')->nullable();
            $table->json('tags')->after('is_default')->nullable();
            $table->string('extension')->after('is_default')->default('core')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropColumn(['extension', 'meta', 'options', 'tags']);
        });
    }
};
