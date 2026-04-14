<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fleetbase's base Model mixes in ClearsHttpCache, which registers
 * HttpCacheObserver on every save event. That observer resolves the
 * `responsecache` container binding from Spatie's ResponseCacheServiceProvider,
 * which is not booted in the core-api Testbench bootstrap. Bind a no-op
 * stand-in so CompanyUser::create() can fire its created event.
 *
 * This is test-only infrastructure and does NOT change the CompanyUser
 * model or production observer behavior.
 */
beforeEach(function () {
    app()->singleton('responsecache', function () {
        return new class {
            public function clear(array $tags = []): self
            {
                return $this;
            }

            public function __call($name, $arguments)
            {
                return $this;
            }
        };
    });

    // The User model hardcodes `protected $connection = 'mysql'`,
    // but the test suite runs against in-memory sqlite. Make the `mysql`
    // connection name resolve to the same active sqlite PDO instance so
    // Eloquent relation traversal (user/company) works without a real
    // MySQL server. Implemented by reaching into the connection manager
    // and aliasing 'mysql' to the already-booted default sqlite connection.
    $default = \Illuminate\Support\Facades\DB::connection();
    $manager = app('db');
    $ref     = new \ReflectionProperty($manager, 'connections');
    $ref->setAccessible(true);
    $connections           = $ref->getValue($manager);
    $connections['mysql']  = $default;
    $ref->setValue($manager, $connections);
});

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
