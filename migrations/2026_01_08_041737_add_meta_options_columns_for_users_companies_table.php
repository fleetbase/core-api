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
        // Add options column to users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'options')) {
                $table->json('options')->nullable()->after('meta');
            }
        });

        // Add meta column to companies table
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'meta')) {
                $table->json('meta')->nullable()->after('options');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove options column from users table
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'options')) {
                $table->dropColumn('options');
            }
        });

        // Remove meta column from companies table
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'meta')) {
                $table->dropColumn('meta');
            }
        });
    }
};
