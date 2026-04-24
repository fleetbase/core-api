<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Insert a company via query builder (bypasses CompanyObserver) and return
 * its uuid.
 */
function makeCompanyRow(array $overrides = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert(array_merge([
        'uuid' => $uuid,
        'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => 'Co ' . substr($uuid, 0, 4),
        'company_type' => 'organization',
        'is_client' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $uuid;
}

/**
 * Insert a user via query builder and return its uuid.
 */
function makeUserRow(string $companyUuid, array $overrides = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('users')->insert(array_merge([
        'uuid' => $uuid,
        'public_id' => 'u_' . substr($uuid, 0, 8),
        'company_uuid' => $companyUuid,
        'name' => 'User ' . substr($uuid, 0, 4),
        'email' => 'user-' . substr($uuid, 0, 8) . '@example.test',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $uuid;
}

/**
 * Insert a company_users pivot row via query builder.
 */
function makePivot(string $userUuid, string $companyUuid, bool $isDefault = false, string $accessLevel = 'full'): void
{
    DB::table('company_users')->insert([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $userUuid,
        'company_uuid' => $companyUuid,
        'status' => 'active',
        'external' => false,
        'access_level' => $accessLevel,
        'is_default' => $isDefault,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('defaultCompany returns the company linked via pivot is_default', function () {
    $companyA = makeCompanyRow(['name' => 'A']);
    $companyB = makeCompanyRow(['name' => 'B']);
    $userUuid = makeUserRow($companyA); // legacy company = A

    makePivot($userUuid, $companyA, isDefault: false);
    makePivot($userUuid, $companyB, isDefault: true); // pivot-default = B

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $default = $user->defaultCompany();

    expect($default)->not->toBeNull();
    expect($default->uuid)->toBe($companyB); // pivot wins over legacy
});

test('defaultCompany falls back to users.company_uuid when no pivot is_default exists', function () {
    $companyA = makeCompanyRow(['name' => 'Legacy Home']);
    $userUuid = makeUserRow($companyA);

    // Pivot row exists but NOT marked default.
    makePivot($userUuid, $companyA, isDefault: false);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $default = $user->defaultCompany();

    expect($default)->not->toBeNull();
    expect($default->uuid)->toBe($companyA); // fell back to legacy
});

test('defaultCompany returns null when user has no pivots and no legacy company_uuid', function () {
    // users.company_uuid is nullable per the base migration, so we can null it out
    // directly via query builder after insert. No pivot rows + NULL legacy → defaultCompany() must be null.
    $placeholder = makeCompanyRow(['name' => 'Placeholder']);
    $userUuid    = makeUserRow($placeholder);

    // Null the legacy pointer so the company() BelongsTo returns null.
    DB::table('users')->where('uuid', $userUuid)->update(['company_uuid' => null]);

    $user = User::where('uuid', $userUuid)->firstOrFail();

    expect($user->defaultCompany())->toBeNull();
});

test('canAccessCompany returns true only for companies with a pivot row', function () {
    $accessible = makeCompanyRow(['name' => 'Accessible']);
    $forbidden  = makeCompanyRow(['name' => 'Forbidden']);
    $userUuid   = makeUserRow($accessible);

    makePivot($userUuid, $accessible);

    $user = User::where('uuid', $userUuid)->firstOrFail();

    expect($user->canAccessCompany($accessible))->toBeTrue();
    expect($user->canAccessCompany($forbidden))->toBeFalse();
});

test('canAccessCompany does NOT count the legacy users.company_uuid if no pivot exists', function () {
    // Strict accessibility semantics: legacy column alone is not enough.
    $legacyOnly = makeCompanyRow(['name' => 'Legacy Only']);
    $userUuid   = makeUserRow($legacyOnly); // users.company_uuid set, no pivot

    $user = User::where('uuid', $userUuid)->firstOrFail();

    expect($user->canAccessCompany($legacyOnly))->toBeFalse();
});

test('accessibleCompanyUuids includes all distinct pivot companies', function () {
    $a = makeCompanyRow(['name' => 'A']);
    $b = makeCompanyRow(['name' => 'B']);
    $c = makeCompanyRow(['name' => 'C']);
    $userUuid = makeUserRow($a);

    makePivot($userUuid, $a);
    makePivot($userUuid, $b);
    makePivot($userUuid, $c);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $uuids = $user->accessibleCompanyUuids();

    expect($uuids)->toContain($a, $b, $c);
    expect(count($uuids))->toBe(3);
});

test('accessibleCompanyUuids returns no duplicates even if the same company is pivoted twice', function () {
    $a = makeCompanyRow(['name' => 'Dup']);
    $userUuid = makeUserRow($a);

    // Two pivot rows for the same (user, company). Unusual but not forbidden
    // by the current schema (no unique constraint on user_uuid+company_uuid).
    makePivot($userUuid, $a);
    makePivot($userUuid, $a);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $uuids = $user->accessibleCompanyUuids();

    expect(count($uuids))->toBe(1);
    expect($uuids[0])->toBe($a);
});

test('existing User relations preserved: company() BelongsTo and companyUsers() HasMany still work', function () {
    $home = makeCompanyRow(['name' => 'Home']);
    $alt  = makeCompanyRow(['name' => 'Alt']);
    $userUuid = makeUserRow($home);

    makePivot($userUuid, $home);
    makePivot($userUuid, $alt);

    $user = User::where('uuid', $userUuid)->firstOrFail();

    // BelongsTo — legacy single-company pointer unchanged.
    expect($user->company)->not->toBeNull();
    expect($user->company->uuid)->toBe($home);

    // HasMany pivot rows — underlying relation used by all three new helpers.
    $pivotCompanyUuids = $user->companyUsers->pluck('company_uuid')->toArray();
    expect($pivotCompanyUuids)->toContain($home, $alt);
    expect(count($pivotCompanyUuids))->toBe(2);

    // HasManyThrough companies() is still callable (no regression to the relation's
    // existence/type), even though its upstream join definition is a known separate
    // issue outside Task 7's scope. Asserting it doesn't throw is the regression check.
    $companiesRelation = $user->companies();
    expect($companiesRelation)->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasManyThrough::class);
});
