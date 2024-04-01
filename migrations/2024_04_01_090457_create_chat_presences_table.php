<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_presences', function (Blueprint $table) {
            $table->uuid('chat_channel_uuid')->nullable()->index();
            $table->string('user_uuid')->nullable()->default('')->index();
            $table->string('last_seen_at')->nullable();
            $table->string('is_online')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_presences');
    }
};
