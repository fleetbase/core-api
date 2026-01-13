<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('updated_at');
            $table->string('onboarding_completed_by_uuid')->nullable()->after('onboarding_completed_at');
            $table->index('onboarding_completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['onboarding_completed_at']);
            $table->dropColumn(['onboarding_completed_at', 'onboarding_completed_by_uuid']);
        });
    }
};
