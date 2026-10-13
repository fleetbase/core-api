<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Verification codes get real, indexed columns for what used to live only in `meta`:
     * the tenant that asked for the code, the identity it was filed under, the channel it
     * went out on, where the request came from, the ledger row of the SMS that carried it,
     * and when it was used. `identity` is backfilled from `meta->identity` for recent rows
     * so lookups can move off the JSON path at once.
     */
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->char('company_uuid', 36)->nullable()->index()->after('subject_type');
            $table->string('identity', 191)->nullable()->after('company_uuid');
            $table->string('channel', 16)->nullable()->after('identity');
            $table->string('ip_address', 45)->nullable()->after('channel');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
            $table->char('sms_send_uuid', 36)->nullable()->index()->after('user_agent');
            $table->dateTime('consumed_at')->nullable()->after('expires_at');

            $table->index(['for', 'identity', 'created_at']);
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::table('verification_codes')
                ->whereNull('identity')
                ->where('created_at', '>=', now()->subDays(30))
                ->update(['identity' => DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.identity'))")]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->dropIndex(['for', 'identity', 'created_at']);
            $table->dropColumn(['company_uuid', 'identity', 'channel', 'ip_address', 'user_agent', 'sms_send_uuid', 'consumed_at']);
        });
    }
};
