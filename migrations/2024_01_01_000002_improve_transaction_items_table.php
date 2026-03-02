<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Improve the transaction_items table to align with the robust transaction
 * primitive design.
 *
 * Changes:
 *  - Add public_id (HasPublicId support)
 *  - Fix amount column type: string → INT NOT NULL DEFAULT 0
 *  - Add quantity (INT DEFAULT 1)
 *  - Add unit_price (INT DEFAULT 0, cents)
 *  - Add tax_rate (DECIMAL(5,2) DEFAULT 0.00)
 *  - Add tax_amount (INT DEFAULT 0, cents)
 *  - Add description (longer text alternative to details)
 *  - Add sort_order (for ordered line item display)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            // Add public_id for HasPublicId trait support
            $table->string('public_id', 191)->nullable()->unique()->after('uuid');

            // Add quantity and unit price for proper line-item accounting
            $table->integer('quantity')->default(1)->after('transaction_uuid');
            $table->integer('unit_price')->default(0)->after('quantity');

            // Add tax columns
            $table->decimal('tax_rate', 5, 2)->default(0.00)->after('currency');
            $table->integer('tax_amount')->default(0)->after('tax_rate');

            // Add description as a longer alternative to details (TEXT vs VARCHAR)
            $table->text('description')->nullable()->after('details');

            // Add sort order for ordered display of line items
            $table->unsignedSmallInteger('sort_order')->default(0)->after('description');
        });

        // Fix amount column: string → integer
        // First copy to a temp column, then drop and re-add as integer
        DB::statement('ALTER TABLE transaction_items MODIFY COLUMN amount BIGINT NOT NULL DEFAULT 0');

        // Backfill unit_price = amount for existing records (single-unit assumption)
        DB::statement('UPDATE transaction_items SET unit_price = amount WHERE unit_price = 0 AND amount > 0');
    }

    public function down(): void
    {
        // Revert amount back to string (original type)
        DB::statement('ALTER TABLE transaction_items MODIFY COLUMN amount VARCHAR(191) NULL');

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn([
                'public_id',
                'quantity',
                'unit_price',
                'tax_rate',
                'tax_amount',
                'description',
                'sort_order',
            ]);
        });
    }
};
