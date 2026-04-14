<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('parent_company_uuid', 36)->nullable()->index()->after('uuid');
            $table->enum('company_type', ['platform', 'organization', 'client'])
                ->default('organization')
                ->after('parent_company_uuid');
            $table->boolean('is_client')->default(false)->index()->after('company_type');
            $table->string('client_code', 50)->nullable()->after('is_client');
            $table->json('client_settings')->nullable()->after('client_code');

            $table->foreign('parent_company_uuid')
                ->references('uuid')
                ->on('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['parent_company_uuid']);
            $table->dropIndex(['parent_company_uuid']);
            $table->dropIndex(['is_client']);
            $table->dropColumn([
                'parent_company_uuid',
                'company_type',
                'is_client',
                'client_code',
                'client_settings',
            ]);
        });
    }
};
