<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->enum('access_level', ['full', 'read_only', 'financial', 'operations'])
                ->default('full')
                ->after('external');
            $table->boolean('is_default')->default(false)->index()->after('access_level');
        });
    }

    public function down(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropIndex(['is_default']);
            $table->dropColumn(['access_level', 'is_default']);
        });
    }
};
