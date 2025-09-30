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
        Schema::create('report_cache', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('report_uuid');
            $table->string('cache_key')->unique();
            $table->longText('cached_data');
            $table->json('metadata')->nullable();
            $table->integer('row_count')->default(0);
            $table->integer('execution_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('report_uuid');
            $table->index('cache_key');
            $table->index('expires_at');

            // Foreign key constraint
            $table->foreign('report_uuid')->references('uuid')->on('reports')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('report_cache');
    }
};
