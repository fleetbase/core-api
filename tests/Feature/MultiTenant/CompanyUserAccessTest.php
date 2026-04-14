<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixture helper: insert a bare company + user pair via query builder,
 * bypassing Eloquent events.
 */
function makeCompanyUserPair(): array
{
    $companyUuid = (string) Str::uuid();
    $userUuid    = (string) Str::uuid();

    DB::table('companies')->insert([
        'uuid' => $companyUuid,
        'public_id' => 'co_' . substr($companyUuid, 0, 8),
        'name' => 'Pair Co ' . substr($companyUuid, 0, 4),
        'company_type' => 'organization',
        'is_client' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('users')->insert([
        'uuid' => $userUuid,
        'public_id' => 'u_' . substr($userUuid, 0, 8),
        'company_uuid' => $companyUuid,
        'name' => 'Pair User',
        'email' => 'pair-' . substr($userUuid, 0, 8) . '@example.test',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$companyUuid, $userUuid];
}

test('access_level and is_default are mass-assignable via create()', function () {
    [$companyUuid, $userUuid] = makeCompanyUserPair();

    $pivot = CompanyUser::create([
        'user_uuid'    => $userUuid,
        'company_uuid' => $companyUuid,
        'access_level' => 'financial',
        'is_default'   => true,
    ]);

    expect($pivot->access_level)->toBe('financial');
    expect($pivot->is_default)->toBeTrue();
    expect($pivot->user_uuid)->toBe($userUuid);
    expect($pivot->company_uuid)->toBe($companyUuid);
});

test('is_default is cast to a real PHP boolean, not int or string', function () {
    [$companyUuid, $userUuid] = makeCompanyUserPair();

    // Insert via query builder so we control the raw storage shape,
    // then read through Eloquent and verify the cast.
    $pivotUuid = (string) Str::uuid();
    DB::table('company_users')->insert([
        'uuid'         => $pivotUuid,
        'user_uuid'    => $userUuid,
        'company_uuid' => $companyUuid,
        'status'       => 'active',
        'external'     => false,
        'access_level' => 'operations',
        'is_default'   => 1,  // raw integer in DB
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $pivot = CompanyUser::where('uuid', $pivotUuid)->firstOrFail();

    expect($pivot->is_default)->toBeTrue();
    expect($pivot->is_default)->toBeBool();  // real bool, not (bool)1 surrogate
    expect(gettype($pivot->is_default))->toBe('boolean');
});

test('is_default false cast returns real boolean false', function () {
    [$companyUuid, $userUuid] = makeCompanyUserPair();

    $pivotUuid = (string) Str::uuid();
    DB::table('company_users')->insert([
        'uuid'         => $pivotUuid,
        'user_uuid'    => $userUuid,
        'company_uuid' => $companyUuid,
        'status'       => 'active',
        'external'     => false,
        'access_level' => 'full',
        'is_default'   => 0,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $pivot = CompanyUser::where('uuid', $pivotUuid)->firstOrFail();

    expect($pivot->is_default)->toBeFalse();
    expect($pivot->is_default)->toBeBool();
});

test('existing pivot behavior preserved: user and company relations still work', function () {
    [$companyUuid, $userUuid] = makeCompanyUserPair();

    $pivot = CompanyUser::create([
        'user_uuid'    => $userUuid,
        'company_uuid' => $companyUuid,
    ]);

    expect($pivot->user)->not->toBeNull();
    expect($pivot->user->uuid)->toBe($userUuid);
    expect($pivot->company)->not->toBeNull();
    expect($pivot->company->uuid)->toBe($companyUuid);
});

test('existing pivot behavior preserved: external and status still work with defaults', function () {
    [$companyUuid, $userUuid] = makeCompanyUserPair();

    $pivot = CompanyUser::create([
        'user_uuid'    => $userUuid,
        'company_uuid' => $companyUuid,
    ]);

    // status has a mutator defaulting to 'active'; external has a DB default of false.
    $fresh = $pivot->fresh();
    expect($fresh->status)->toBe('active');
    expect($fresh->external)->toBeFalse();
});

test('access_level defaults to full when not provided', function () {
    [$companyUuid, $userUuid] = makeCompanyUserPair();

    $pivot = CompanyUser::create([
        'user_uuid'    => $userUuid,
        'company_uuid' => $companyUuid,
    ]);

    expect($pivot->fresh()->access_level)->toBe('full');
});

test('is_default defaults to false when not provided', function () {
    [$companyUuid, $userUuid] = makeCompanyUserPair();

    $pivot = CompanyUser::create([
        'user_uuid'    => $userUuid,
        'company_uuid' => $companyUuid,
    ]);

    expect($pivot->fresh()->is_default)->toBeFalse();
});
