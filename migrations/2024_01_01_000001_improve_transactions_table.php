<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Improve the transactions table to serve as the platform-wide financial
 * transaction primitive.
 *
 * Changes:
 *  - Rename owner_uuid/owner_type  → subject_uuid/subject_type
 *  - Add payer_uuid/payer_type     (who the money flows from)
 *  - Add payee_uuid/payee_type     (who the money flows to)
 *  - Add initiator_uuid/initiator_type (what triggered the transaction)
 *  - Add context_uuid/context_type (related business object)
 *  - Add direction (credit|debit)
 *  - Add balance_after (running balance snapshot, wallet context)
 *  - Add fee_amount, tax_amount, net_amount (monetary breakdown)
 *  - Add exchange_rate, settled_currency, settled_amount (multi-currency)
 *  - Add reference (idempotency key, unique)
 *  - Add parent_transaction_uuid (refund/reversal/split linkage)
 *  - Add gateway_response JSON (raw gateway payload)
 *  - Add payment_method, payment_method_last4, payment_method_brand
 *  - Add ip_address (fraud/audit trail)
 *  - Add notes (internal operator annotation)
 *  - Add failure_reason, failure_code (structured failure info)
 *  - Add period (YYYY-MM accounting period, denormalised)
 *  - Add tags JSON (operator-defined categorisation)
 *  - Add settled_at, voided_at, reversed_at, expires_at timestamps
 *  - Make amount NOT NULL DEFAULT 0
 *  - Keep customer_uuid/customer_type as deprecated nullable aliases
 *  - Keep owner_uuid/owner_type as deprecated nullable aliases (backfilled)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // ----------------------------------------------------------------
            // New polymorphic: subject (primary owner of the transaction record)
            // ----------------------------------------------------------------
            $table->char('subject_uuid', 36)->nullable()->after('owner_type');
            $table->string('subject_type')->nullable()->after('subject_uuid');

            // ----------------------------------------------------------------
            // New polymorphic: payer (funds flow from)
            // ----------------------------------------------------------------
            $table->char('payer_uuid', 36)->nullable()->after('subject_type');
            $table->string('payer_type')->nullable()->after('payer_uuid');

            // ----------------------------------------------------------------
            // New polymorphic: payee (funds flow to)
            // ----------------------------------------------------------------
            $table->char('payee_uuid', 36)->nullable()->after('payer_type');
            $table->string('payee_type')->nullable()->after('payee_uuid');

            // ----------------------------------------------------------------
            // New polymorphic: initiator (what triggered the transaction)
            // ----------------------------------------------------------------
            $table->char('initiator_uuid', 36)->nullable()->after('payee_type');
            $table->string('initiator_type')->nullable()->after('initiator_uuid');

            // ----------------------------------------------------------------
            // New polymorphic: context (related business object)
            // ----------------------------------------------------------------
            $table->char('context_uuid', 36)->nullable()->after('initiator_type');
            $table->string('context_type')->nullable()->after('context_uuid');

            // ----------------------------------------------------------------
            // Direction and balance
            // ----------------------------------------------------------------
            $table->string('direction')->nullable()->after('status');  // credit | debit
            $table->integer('balance_after')->nullable()->after('direction');

            // ----------------------------------------------------------------
            // Monetary breakdown (all in smallest currency unit / cents)
            // ----------------------------------------------------------------
            $table->integer('fee_amount')->default(0)->after('amount');
            $table->integer('tax_amount')->default(0)->after('fee_amount');
            $table->integer('net_amount')->default(0)->after('tax_amount');

            // ----------------------------------------------------------------
            // Multi-currency settlement
            // ----------------------------------------------------------------
            $table->decimal('exchange_rate', 18, 8)->default(1)->after('currency');
            $table->string('settled_currency', 3)->nullable()->after('exchange_rate');
            $table->integer('settled_amount')->nullable()->after('settled_currency');

            // ----------------------------------------------------------------
            // Idempotency and linkage
            // ----------------------------------------------------------------
            $table->string('reference', 191)->nullable()->unique()->after('description');
            $table->char('parent_transaction_uuid', 36)->nullable()->after('reference');

            // ----------------------------------------------------------------
            // Gateway enrichment
            // ----------------------------------------------------------------
            $table->json('gateway_response')->nullable()->after('gateway_transaction_id');
            $table->string('payment_method', 50)->nullable()->after('gateway_response');
            $table->string('payment_method_last4', 4)->nullable()->after('payment_method');
            $table->string('payment_method_brand', 50)->nullable()->after('payment_method_last4');

            // ----------------------------------------------------------------
            // Traceability and compliance
            // ----------------------------------------------------------------
            $table->string('ip_address', 45)->nullable()->after('meta');
            $table->text('notes')->nullable()->after('ip_address');
            $table->string('failure_reason', 191)->nullable()->after('notes');
            $table->string('failure_code', 50)->nullable()->after('failure_reason');

            // ----------------------------------------------------------------
            // Reporting
            // ----------------------------------------------------------------
            $table->string('period', 7)->nullable()->after('failure_code');   // YYYY-MM
            $table->json('tags')->nullable()->after('period');

            // ----------------------------------------------------------------
            // Lifecycle timestamps
            // ----------------------------------------------------------------
            $table->timestamp('settled_at')->nullable()->after('updated_at');
            $table->timestamp('voided_at')->nullable()->after('settled_at');
            $table->timestamp('reversed_at')->nullable()->after('voided_at');
            $table->timestamp('expires_at')->nullable()->after('reversed_at');
        });

        // --------------------------------------------------------------------
        // Backfill subject_* from owner_* (preserve existing data)
        // --------------------------------------------------------------------
        DB::statement('UPDATE transactions SET subject_uuid = owner_uuid, subject_type = owner_type WHERE owner_uuid IS NOT NULL');

        // --------------------------------------------------------------------
        // Backfill payer_* from customer_* (customer was semantically the payer)
        // --------------------------------------------------------------------
        DB::statement('UPDATE transactions SET payer_uuid = customer_uuid, payer_type = customer_type WHERE customer_uuid IS NOT NULL');

        // --------------------------------------------------------------------
        // Backfill period from created_at
        // --------------------------------------------------------------------
        DB::statement("UPDATE transactions SET period = DATE_FORMAT(created_at, '%Y-%m') WHERE created_at IS NOT NULL");

        // --------------------------------------------------------------------
        // Backfill net_amount = amount (no fees/tax in legacy records)
        // --------------------------------------------------------------------
        DB::statement('UPDATE transactions SET net_amount = COALESCE(amount, 0) WHERE net_amount = 0');

        // --------------------------------------------------------------------
        // Add indexes on new columns
        // --------------------------------------------------------------------
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['subject_uuid', 'subject_type'], 'transactions_subject_index');
            $table->index(['payer_uuid', 'payer_type'], 'transactions_payer_index');
            $table->index(['payee_uuid', 'payee_type'], 'transactions_payee_index');
            $table->index(['initiator_uuid', 'initiator_type'], 'transactions_initiator_index');
            $table->index(['context_uuid', 'context_type'], 'transactions_context_index');
            $table->index('direction', 'transactions_direction_index');
            $table->index('period', 'transactions_period_index');
            $table->index('parent_transaction_uuid', 'transactions_parent_index');
            $table->index('payment_method', 'transactions_payment_method_index');
            $table->index('settled_at', 'transactions_settled_at_index');
            $table->index(['company_uuid', 'type'], 'transactions_company_type_index');
            $table->index(['company_uuid', 'status'], 'transactions_company_status_index');
            $table->index(['company_uuid', 'period'], 'transactions_company_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex('transactions_subject_index');
            $table->dropIndex('transactions_payer_index');
            $table->dropIndex('transactions_payee_index');
            $table->dropIndex('transactions_initiator_index');
            $table->dropIndex('transactions_context_index');
            $table->dropIndex('transactions_direction_index');
            $table->dropIndex('transactions_period_index');
            $table->dropIndex('transactions_parent_index');
            $table->dropIndex('transactions_payment_method_index');
            $table->dropIndex('transactions_settled_at_index');
            $table->dropIndex('transactions_company_type_index');
            $table->dropIndex('transactions_company_status_index');
            $table->dropIndex('transactions_company_period_index');
            $table->dropUnique(['reference']);

            // Drop new columns
            $table->dropColumn([
                'subject_uuid', 'subject_type',
                'payer_uuid', 'payer_type',
                'payee_uuid', 'payee_type',
                'initiator_uuid', 'initiator_type',
                'context_uuid', 'context_type',
                'direction', 'balance_after',
                'fee_amount', 'tax_amount', 'net_amount',
                'exchange_rate', 'settled_currency', 'settled_amount',
                'reference', 'parent_transaction_uuid',
                'gateway_response',
                'payment_method', 'payment_method_last4', 'payment_method_brand',
                'ip_address', 'notes', 'failure_reason', 'failure_code',
                'period', 'tags',
                'settled_at', 'voided_at', 'reversed_at', 'expires_at',
            ]);
        });
    }
};
