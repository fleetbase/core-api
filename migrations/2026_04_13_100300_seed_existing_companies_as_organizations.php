<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        // 1. Every no-parent company becomes an organization.
        //    Idempotent: uses query builder, preserves explicit overrides
        //    (e.g. someone already set company_type='client' stays untouched).
        DB::table('companies')
            ->whereNull('parent_company_uuid')
            ->where(function ($q) {
                $q->where('company_type', '!=', 'organization')
                  ->orWhereNull('company_type');
            })
            ->update([
                'company_type' => 'organization',
                'is_client'    => false,
            ]);

        // 2. Backfill is_default on company_users pivot using the tie-breaker:
        //    the pivot row matching users.company_uuid wins.
        $users = DB::table('users')
            ->whereNotNull('company_uuid')
            ->select('uuid', 'company_uuid')
            ->cursor();

        foreach ($users as $user) {
            $pivot = DB::table('company_users')
                ->where('user_uuid', $user->uuid)
                ->where('company_uuid', $user->company_uuid)
                ->first();

            if ($pivot) {
                // Only update when not already correct — avoids churn on re-run.
                DB::table('company_users')
                    ->where('id', $pivot->id)
                    ->where('is_default', false)
                    ->update(['is_default' => true]);
            } else {
                // No pivot row yet for user+company — insert one, marked default.
                DB::table('company_users')->insert([
                    'uuid'         => (string) Str::uuid(),
                    'user_uuid'    => $user->uuid,
                    'company_uuid' => $user->company_uuid,
                    'status'       => 'active',
                    'is_default'   => true,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            // Demote any OTHER pivot rows for this user that are still marked default.
            DB::table('company_users')
                ->where('user_uuid', $user->uuid)
                ->where('company_uuid', '!=', $user->company_uuid)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }

    public function down(): void
    {
        // No-op: this is a data seed, not a schema change. Reversing it
        // would require restoring the pre-seed state which we don't retain.
    }
};
