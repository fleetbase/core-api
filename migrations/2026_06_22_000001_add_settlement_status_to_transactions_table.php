<?php

use Fleetbase\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'settlement_status')) {
                $table->string('settlement_status', 32)
                    ->default(Transaction::SETTLEMENT_STATUS_UNPAID)
                    ->after('status')
                    ->index('transactions_settlement_status_index');
            }
        });

        DB::table('transactions')
            ->whereNull('settlement_status')
            ->update(['settlement_status' => Transaction::SETTLEMENT_STATUS_UNPAID]);

        DB::table('transactions')
            ->where('status', 'completed')
            ->update(['status' => Transaction::STATUS_SUCCESS]);

        DB::table('transactions')
            ->where('status', 'paid')
            ->update([
                'status'            => Transaction::STATUS_SUCCESS,
                'settlement_status' => Transaction::SETTLEMENT_STATUS_PAID,
                'settled_at'        => DB::raw('COALESCE(settled_at, updated_at, created_at)'),
            ]);

        DB::table('transactions')
            ->whereIn('type', [
                Transaction::TYPE_INVOICE_PAYMENT,
                Transaction::TYPE_WALLET_DEPOSIT,
                Transaction::TYPE_WALLET_WITHDRAWAL,
                Transaction::TYPE_WALLET_TRANSFER_IN,
                Transaction::TYPE_WALLET_TRANSFER_OUT,
                'deposit',
                'withdrawal',
                'transfer_in',
                'transfer_out',
            ])
            ->where('status', Transaction::STATUS_SUCCESS)
            ->where('settlement_status', Transaction::SETTLEMENT_STATUS_UNPAID)
            ->update([
                'settlement_status' => Transaction::SETTLEMENT_STATUS_PAID,
                'settled_at'        => DB::raw('COALESCE(settled_at, updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'settlement_status')) {
                $table->dropIndex('transactions_settlement_status_index');
                $table->dropColumn('settlement_status');
            }
        });
    }
};
