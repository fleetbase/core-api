<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helper: invoke the seed migration's up() directly against the current DB.
 * Used to test idempotency by running up() a second time.
 */
function runSeedMigrationUp(): void
{
    $path = realpath(__DIR__ . '/../../../migrations/2026_04_13_100300_seed_existing_companies_as_organizations.php');
    expect($path)->not->toBeFalse();
    $migration = require $path;
    $migration->up();
}

test('every no-parent company is set to company_type organization after seed', function () {
    // RefreshDatabase has already run all migrations including the seed.
    $violators = DB::table('companies')
        ->whereNull('parent_company_uuid')
        ->where(function ($q) {
            $q->where('company_type', '!=', 'organization')
              ->orWhereNull('company_type');
        })
        ->count();

    expect($violators)->toBe(0);
});

test('every user with a company_uuid has exactly one is_default pivot row', function () {
    // Seed a test fixture: user with a matching company_users pivot.
    $userUuid    = (string) Str::uuid();
    $companyUuid = (string) Str::uuid();

    DB::table('companies')->insert([
        'uuid' => $companyUuid,
        'public_id' => 'co_' . substr($companyUuid, 0, 8),
        'name' => 'Fixture Co',
        'company_type' => 'organization',
        'is_client' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('users')->insert([
        'uuid' => $userUuid,
        'public_id' => 'u_' . substr($userUuid, 0, 8),
        'company_uuid' => $companyUuid,
        'name' => 'Fixture User',
        'email' => 'fixture-' . substr($userUuid, 0, 8) . '@example.test',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('company_users')->insert([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $userUuid,
        'company_uuid' => $companyUuid,
        'status' => 'active',
        'is_default' => false,  // will be flipped by re-running up()
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runSeedMigrationUp();

    $defaults = DB::table('company_users')
        ->where('user_uuid', $userUuid)
        ->where('is_default', true)
        ->count();

    expect($defaults)->toBe(1);
});

test('re-running up() is idempotent — no state change, no duplicate rows', function () {
    $userUuid    = (string) Str::uuid();
    $companyUuid = (string) Str::uuid();

    DB::table('companies')->insert([
        'uuid' => $companyUuid,
        'public_id' => 'co_' . substr($companyUuid, 0, 8),
        'name' => 'Idempotent Co',
        'company_type' => 'organization',
        'is_client' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('users')->insert([
        'uuid' => $userUuid,
        'public_id' => 'u_' . substr($userUuid, 0, 8),
        'company_uuid' => $companyUuid,
        'name' => 'Idempotent User',
        'email' => 'idem-' . substr($userUuid, 0, 8) . '@example.test',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // First run creates the pivot.
    runSeedMigrationUp();
    $afterFirst = DB::table('company_users')
        ->where('user_uuid', $userUuid)
        ->get()
        ->toArray();
    expect(count($afterFirst))->toBe(1);
    expect((bool) $afterFirst[0]->is_default)->toBeTrue();

    // Second run must be a no-op.
    runSeedMigrationUp();
    $afterSecond = DB::table('company_users')
        ->where('user_uuid', $userUuid)
        ->get()
        ->toArray();
    expect(count($afterSecond))->toBe(1); // no duplicate insert
    expect($afterSecond[0]->id)->toBe($afterFirst[0]->id); // same row
    expect((bool) $afterSecond[0]->is_default)->toBeTrue();
});

test('no user ever has more than one is_default pivot row after seed', function () {
    $userUuid = (string) Str::uuid();
    $companyA = (string) Str::uuid();
    $companyB = (string) Str::uuid();

    foreach ([$companyA, $companyB] as $companyUuid) {
        DB::table('companies')->insert([
            'uuid' => $companyUuid,
            'public_id' => 'co_' . substr($companyUuid, 0, 8),
            'name' => 'Multi Co ' . substr($companyUuid, 0, 4),
            'company_type' => 'organization',
            'is_client' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    DB::table('users')->insert([
        'uuid' => $userUuid,
        'public_id' => 'u_' . substr($userUuid, 0, 8),
        'company_uuid' => $companyA,
        'name' => 'Multi User',
        'email' => 'multi-' . substr($userUuid, 0, 8) . '@example.test',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Give user pivot rows for BOTH companies, both marked default (bad state).
    DB::table('company_users')->insert([
        ['uuid' => (string) Str::uuid(), 'user_uuid' => $userUuid, 'company_uuid' => $companyA, 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => (string) Str::uuid(), 'user_uuid' => $userUuid, 'company_uuid' => $companyB, 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    runSeedMigrationUp();

    $defaults = DB::table('company_users')
        ->where('user_uuid', $userUuid)
        ->where('is_default', true)
        ->get();

    expect($defaults->count())->toBe(1);
    expect($defaults->first()->company_uuid)->toBe($companyA); // default matches users.company_uuid
});
