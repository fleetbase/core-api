<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * One row per database per backup run, so an administrator can see what ran, how big it
     * was, how long it took and why it failed — the old command wrote nothing anywhere and
     * reported success while uploading empty dumps.
     */
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('connection_name', 64);
            $table->string('database', 128);
            $table->string('status', 16)->index();
            $table->string('trigger', 16);
            $table->string('disk', 64);
            $table->string('path', 512)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            $table->index(['disk', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
