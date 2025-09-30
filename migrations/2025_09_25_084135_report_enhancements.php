<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('public_id')->unique()->after('uuid');
            $table->string('description')->nullable()->after('title');
            $table->string('status')->nullable()->after('body');
            $table->json('tags')->nullable()->after('body');
            $table->json('meta')->nullable()->after('body');
            $table->json('options')->nullable()->after('body');
            $table->json('query_config')->nullable()->after('body');
            $table->json('result_columns')->nullable()->after('query_config');
            $table->timestamp('last_executed_at')->nullable()->after('result_columns');
            $table->integer('execution_time')->nullable()->comment('Execution time in milliseconds')->after('last_executed_at');
            $table->integer('row_count')->nullable()->after('execution_time');
            $table->boolean('is_scheduled')->default(false)->after('row_count');
            $table->json('schedule_config')->nullable()->after('is_scheduled');
            $table->json('export_formats')->nullable()->after('schedule_config');
            $table->boolean('is_generated')->default(false)->after('export_formats');
            $table->index(['company_uuid', 'is_scheduled']);
            $table->index('last_executed_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('reports', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['company_uuid', 'is_scheduled']);
            $table->dropIndex(['last_executed_at']);

            // Drop columns
            $table->dropColumn([
                'public_id',
                'description',
                'status',
                'meta',
                'tags',
                'options',
                'query_config',
                'result_columns',
                'last_executed_at',
                'execution_time',
                'row_count',
                'is_scheduled',
                'schedule_config',
                'export_formats',
                'is_generated',
            ]);
        });
    }
};
