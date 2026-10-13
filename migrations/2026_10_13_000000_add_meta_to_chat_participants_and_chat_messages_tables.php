<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Free form metadata for chat participants and messages: a participant's
     * `meta.role` (customer, driver, store, system) tells apps who is speaking
     * in an order chat, and a message's `meta.system` / `meta.kind` mark
     * system messages (order updated, address change requested).
     *
     * Every column is guarded so the migration is safe to run against tables
     * that already have it.
     */
    public function up(): void
    {
        foreach (['chat_participants', 'chat_messages'] as $tableName) {
            if (Schema::hasColumn($tableName, 'meta')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->json('meta')->nullable()->after('chat_channel_uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (['chat_participants', 'chat_messages'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'meta')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
