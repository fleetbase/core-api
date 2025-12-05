<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPerformanceIndexes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Optimize company_users queries
        Schema::table('company_users', function (Blueprint $table) {
            if (!$this->indexExists('company_users', 'company_users_user_uuid_index')) {
                $table->index('user_uuid');
            }
            if (!$this->indexExists('company_users', 'company_users_company_uuid_index')) {
                $table->index('company_uuid');
            }
            if (!$this->indexExists('company_users', 'company_users_deleted_at_index')) {
                $table->index('deleted_at');
            }
            // Composite index for common query pattern
            if (!$this->indexExists('company_users', 'company_users_user_company_idx')) {
                $table->index(['user_uuid', 'company_uuid', 'deleted_at'], 'company_users_user_company_idx');
            }
        });

        // Optimize companies queries
        Schema::table('companies', function (Blueprint $table) {
            if (!$this->indexExists('companies', 'companies_owner_uuid_index')) {
                $table->index('owner_uuid');
            }
        });

        // Optimize users queries
        Schema::table('users', function (Blueprint $table) {
            if (!$this->indexExists('users', 'users_email_index')) {
                $table->index('email');
            }
            if (!$this->indexExists('users', 'users_phone_index')) {
                $table->index('phone');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropIndex(['user_uuid']);
            $table->dropIndex(['company_uuid']);
            $table->dropIndex(['deleted_at']);
            $table->dropIndex('company_users_user_company_idx');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['owner_uuid']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropIndex(['phone']);
        });
    }

    /**
     * Check if an index exists on a table.
     *
     * @param string $table
     * @param string $index
     *
     * @return bool
     */
    protected function indexExists($table, $index)
    {
        try {
            $indexes = Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes($table);

            return isset($indexes[$index]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
