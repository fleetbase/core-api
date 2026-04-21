<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'scheduled' to the schedule_items.status ENUM and update the default.
 *
 * The original ENUM was: ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show']
 * We add 'scheduled' as the canonical status for future materialised shifts and
 * change the column default from 'pending' to 'scheduled'.
 *
 * 'pending' is retained for backwards compatibility (e.g. manually-created items
 * that have not yet been confirmed).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE `schedule_items`
            MODIFY COLUMN `status`
                ENUM('pending','scheduled','confirmed','in_progress','completed','cancelled','no_show')
                NOT NULL
                DEFAULT 'scheduled'
        ");
    }

    public function down(): void
    {
        // Revert any 'scheduled' rows back to 'pending' before shrinking the ENUM
        DB::statement("UPDATE `schedule_items` SET `status` = 'pending' WHERE `status` = 'scheduled'");

        DB::statement("
            ALTER TABLE `schedule_items`
            MODIFY COLUMN `status`
                ENUM('pending','confirmed','in_progress','completed','cancelled','no_show')
                NOT NULL
                DEFAULT 'pending'
        ");
    }
};
