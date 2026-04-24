<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The Company model fires webhook/broadcast side-effects on save via its
// SendsWebhooks trait. Force the null broadcaster so Eloquent save paths
// don't explode with "Driver [socketcluster] is not supported." during
// observer-firing fixtures.
beforeEach(function () {
    config()->set('broadcasting.default', 'null');
    config()->set('broadcasting.connections.null', ['driver' => 'null']);
});

/*
|--------------------------------------------------------------------------
| Fixtures (DB::table inserts, mirroring ClientCompanyControllerTest style)
|--------------------------------------------------------------------------
*/

function ctxMakeOrg(array $o = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert(array_merge([
        'uuid'         => $uuid,
        'public_id'    => 'co_' . substr($uuid, 0, 8),
        'name'         => 'Org ' . substr($uuid, 0, 4),
        'company_type' => 'organization',
        'is_client'    => false,
        'created_at'   => now(),
        'updated_at'   => now(),
    ], $o));

    return $uuid;
}

function ctxMakeClient(string $parentUuid, array $o = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert(array_merge([
        'uuid'                => $uuid,
        'public_id'           => 'co_' . substr($uuid, 0, 8),
        'name'                => 'Client ' . substr($uuid, 0, 4),
        'company_type'        => 'client',
        'is_client'           => true,
        'parent_company_uuid' => $parentUuid,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $o));

    return $uuid;
}

function ctxMakeUserForCompany(string $companyUuid, bool $isDefault = true): string
{
    $uuid = (string) Str::uuid();
    DB::table('users')->insert([
        'uuid'         => $uuid,
        'public_id'    => 'u_' . substr($uuid, 0, 8),
        'company_uuid' => $companyUuid,
        'name'         => 'User ' . substr($uuid, 0, 4),
        'email'        => 'u-' . substr($uuid, 0, 8) . '@x.test',
        'password'     => 'x',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    DB::table('company_users')->insert([
        'uuid'         => (string) Str::uuid(),
        'user_uuid'    => $uuid,
        'company_uuid' => $companyUuid,
        'status'       => 'active',
        'external'     => false,
        'access_level' => 'full',
        'is_default'   => $isDefault,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    return $uuid;
}

function ctxAddPivot(string $userUuid, string $companyUuid): void
{
    DB::table('company_users')->insert([
        'uuid'         => (string) Str::uuid(),
        'user_uuid'    => $userUuid,
        'company_uuid' => $companyUuid,
        'status'       => 'active',
        'external'     => false,
        'access_level' => 'full',
        'is_default'   => false,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

// ---------------------------------------------------------------------------
// 1. current-context returns the resolved company when X-Company-Context is sent.
// ---------------------------------------------------------------------------
test('current-context returns the resolved company when X-Company-Context header is sent', function () {
    $orgUuid  = ctxMakeOrg(['name' => 'Acme Org']);
    $userUuid = ctxMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->withHeaders(['X-Company-Context' => $orgUuid])
        ->getJson('/v1/companies/current-context');

    $response->assertStatus(200);
    expect($response->json('company.uuid'))->toBe($orgUuid);
    expect($response->json('company.name'))->toBe('Acme Org');
    expect($response->json('company.company_type'))->toBe('organization');
});

// ---------------------------------------------------------------------------
// 2. current-context reflects middleware resolution — target != default.
// ---------------------------------------------------------------------------
test('current-context reflects middleware resolution (no extra DB lookup)', function () {
    // User has two orgs via pivot. Default is orgA. Send X-Company-Context=orgB.
    // current-context MUST return orgB (the middleware-resolved value), NOT orgA.
    $orgA     = ctxMakeOrg(['name' => 'Alpha']);
    $orgB     = ctxMakeOrg(['name' => 'Bravo']);
    $userUuid = ctxMakeUserForCompany($orgA); // default = orgA
    ctxAddPivot($userUuid, $orgB);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->withHeaders(['X-Company-Context' => $orgB])
        ->getJson('/v1/companies/current-context');

    $response->assertStatus(200);
    expect($response->json('company.uuid'))->toBe($orgB); // not orgA
    expect($response->json('company.name'))->toBe('Bravo');
});

// ---------------------------------------------------------------------------
// 3. switch-context with valid accessible UUID returns the company shape.
// ---------------------------------------------------------------------------
test('switch-context accepts valid UUID with access and returns the company shape', function () {
    $orgA     = ctxMakeOrg(['name' => 'Alpha']);
    $orgB     = ctxMakeOrg(['name' => 'Bravo']);
    $userUuid = ctxMakeUserForCompany($orgA);
    ctxAddPivot($userUuid, $orgB);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $orgB]);

    $response->assertStatus(200);
    expect($response->json('company.uuid'))->toBe($orgB);
    expect($response->json('company.name'))->toBe('Bravo');
    expect($response->json('company.company_type'))->toBe('organization');
});

// ---------------------------------------------------------------------------
// 4. switch-context returns 403 for malformed UUID.
// ---------------------------------------------------------------------------
test('switch-context returns 403 for malformed UUID', function () {
    $orgUuid  = ctxMakeOrg();
    $userUuid = ctxMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => 'not-a-uuid']);

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('Access denied to this company context');
});

// ---------------------------------------------------------------------------
// 5. switch-context returns 403 for valid UUID with no pivot access.
// ---------------------------------------------------------------------------
test('switch-context returns 403 for valid UUID with no pivot access', function () {
    $orgA     = ctxMakeOrg();
    $orgB     = ctxMakeOrg(); // real company, but user has no pivot
    $userUuid = ctxMakeUserForCompany($orgA);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $orgB]);

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('Access denied to this company context');
});

// ---------------------------------------------------------------------------
// 6. switch-context returns 403 for dangling pivot (no company row).
// ---------------------------------------------------------------------------
test('switch-context returns 403 for valid UUID, valid pivot, but non-existent company (dangling pivot)', function () {
    $orgA        = ctxMakeOrg();
    $userUuid    = ctxMakeUserForCompany($orgA);
    $danglingUuid = (string) Str::uuid();
    // Dangling pivot: the user has pivot access, but the company row doesn't exist.
    DB::table('company_users')->insert([
        'uuid'         => (string) Str::uuid(),
        'user_uuid'    => $userUuid,
        'company_uuid' => $danglingUuid,
        'status'       => 'active',
        'external'     => false,
        'access_level' => 'full',
        'is_default'   => false,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $danglingUuid]);

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('Access denied to this company context');
});

// ---------------------------------------------------------------------------
// 7. client-role user gets 403 on current-context.
// ---------------------------------------------------------------------------
test('client-role user gets 403 on current-context', function () {
    $orgUuid  = ctxMakeOrg();
    $client   = ctxMakeClient($orgUuid);
    $userUuid = ctxMakeUserForCompany($client); // default = client company
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/current-context');

    $response->assertStatus(403);
});

// ---------------------------------------------------------------------------
// 8. client-role user gets 403 on switch-context (payload not validated).
// ---------------------------------------------------------------------------
test('client-role user gets 403 on switch-context', function () {
    $orgUuid  = ctxMakeOrg();
    $client   = ctxMakeClient($orgUuid);
    $userUuid = ctxMakeUserForCompany($client); // default = client company
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    // Any payload — malformed or well-formed — must return 403 because the
    // caller is client-role. The middleware blocks it before the controller.
    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $orgUuid]);

    $response->assertStatus(403);
});

// ---------------------------------------------------------------------------
// 9. switch-context does NOT mutate any state.
// ---------------------------------------------------------------------------
test('switch-context does NOT mutate any state', function () {
    $orgA     = ctxMakeOrg(['name' => 'Alpha']);
    $orgB     = ctxMakeOrg(['name' => 'Bravo']);
    $userUuid = ctxMakeUserForCompany($orgA); // default = orgA
    ctxAddPivot($userUuid, $orgB);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    // Call switch-context with orgB as target, NO X-Company-Context header.
    $switchResponse = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $orgB]);

    $switchResponse->assertStatus(200);
    expect($switchResponse->json('company.uuid'))->toBe($orgB);

    // Subsequent request WITHOUT X-Company-Context must still resolve to orgA
    // (the user's default). If switch-context had mutated state (session, DB,
    // or default company), this would return orgB.
    $currentResponse = $this->actingAs($user, 'sanctum')
        ->getJson('/v1/companies/current-context');

    $currentResponse->assertStatus(200);
    expect($currentResponse->json('company.uuid'))->toBe($orgA); // unchanged
    expect($currentResponse->json('company.name'))->toBe('Alpha');

    // Belt-and-braces: the pivot default row is untouched.
    $defaultPivot = DB::table('company_users')
        ->where('user_uuid', $userUuid)
        ->where('is_default', true)
        ->first();
    expect($defaultPivot->company_uuid)->toBe($orgA);
});

// ---------------------------------------------------------------------------
// 10. response shape contains only safe fields — no internal/client data.
// ---------------------------------------------------------------------------
test('response shape contains only safe fields (uuid, name, company_type) — no client_settings or internal data', function () {
    $orgUuid  = ctxMakeOrg(['name' => 'Clean Org']);
    // Populate some extra fields that should NOT be echoed back.
    DB::table('companies')->where('uuid', $orgUuid)->update([
        'stripe_id' => 'cus_secret_should_not_leak',
        'phone'     => '+1-555-SECRET',
    ]);
    $userUuid = ctxMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/current-context');
    $response->assertStatus(200);

    $company = $response->json('company');
    expect(array_keys($company))->toEqualCanonicalizing(['uuid', 'name', 'company_type']);
    expect($company)->not->toHaveKey('client_settings');
    expect($company)->not->toHaveKey('stripe_id');
    expect($company)->not->toHaveKey('phone');
});

// ---------------------------------------------------------------------------
// 11. repeated switch-context calls return identical responses (deterministic).
// ---------------------------------------------------------------------------
test('repeated switch-context calls return identical responses (deterministic)', function () {
    $orgA     = ctxMakeOrg(['name' => 'Alpha']);
    $orgB     = ctxMakeOrg(['name' => 'Bravo']);
    $userUuid = ctxMakeUserForCompany($orgA);
    ctxAddPivot($userUuid, $orgB);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    $first  = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $orgB]);
    $second = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $orgB]);
    $third  = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $orgB]);

    $first->assertStatus(200);
    $second->assertStatus(200);
    $third->assertStatus(200);

    expect($first->json())->toEqual($second->json());
    expect($second->json())->toEqual($third->json());
});

// ---------------------------------------------------------------------------
// 12. switch-context does not leak existence — same 403 for "no pivot" and "non-existent".
// ---------------------------------------------------------------------------
test('switch-context does not leak existence of cross-tenant companies — same 403 shape for "no pivot" and "non-existent"', function () {
    $orgA     = ctxMakeOrg();
    $orgB     = ctxMakeOrg(); // real, but no pivot for the user
    $userUuid = ctxMakeUserForCompany($orgA);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $nonExistentUuid = (string) Str::uuid();

    $noPivot = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $orgB]);
    $nonexistent = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/switch-context', ['company_uuid' => $nonExistentUuid]);

    expect($noPivot->getStatusCode())->toBe(403);
    expect($nonexistent->getStatusCode())->toBe(403);

    // Bodies are byte-identical — no information leakage differentiating the two.
    expect($noPivot->json())->toEqual($nonexistent->json());
});
