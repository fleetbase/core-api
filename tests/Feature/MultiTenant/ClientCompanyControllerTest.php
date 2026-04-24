<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The Company model fires webhook/broadcast side-effects on save via its
// SendsWebhooks trait. The production default broadcaster is `socketcluster`
// (registered by SocketClusterServiceProvider, which is not booted in the
// Testbench bootstrap). Force the null broadcaster for this test file so
// Eloquent save paths don't explode with "Driver [socketcluster] is not
// supported." during create/update/delete.
beforeEach(function () {
    config()->set('broadcasting.default', 'null');
    config()->set('broadcasting.connections.null', ['driver' => 'null']);
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
|
| Mirrors the DB::table() fixture pattern used by the other MultiTenant
| feature tests. No Eloquent writes in the fixtures themselves so the
| controller's Company::create() path is exercised end-to-end in tests
| that specifically cover it.
*/

function ccMakeOrg(array $o = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert(array_merge([
        'uuid'        => $uuid,
        'public_id'   => 'co_' . substr($uuid, 0, 8),
        'name'        => 'Org ' . substr($uuid, 0, 4),
        'company_type' => 'organization',
        'is_client'    => false,
        'created_at'  => now(),
        'updated_at'  => now(),
    ], $o));

    return $uuid;
}

function ccMakeClient(string $parentUuid, array $o = []): string
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

function ccMakeUserForCompany(string $companyUuid, bool $isDefault = true): string
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

// ---------------------------------------------------------------------------
// 1. Listing returns only clients for the resolved org.
// ---------------------------------------------------------------------------
test('org user can list only their own client companies', function () {
    $orgUuid = ccMakeOrg();
    $clientA = ccMakeClient($orgUuid, ['name' => 'Alpha']);
    $clientB = ccMakeClient($orgUuid, ['name' => 'Bravo']);
    $userUuid = ccMakeUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/clients');

    $response->assertStatus(200);
    $uuids = collect($response->json('clients'))->pluck('uuid')->all();
    expect($uuids)->toContain($clientA, $clientB)->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// 2. Listing scopes clients to caller's org — never sees sibling-org clients.
// ---------------------------------------------------------------------------
test('org user cannot see client companies belonging to another org', function () {
    $orgA = ccMakeOrg();
    $orgB = ccMakeOrg();
    $aClient = ccMakeClient($orgA, ['name' => 'A-Client']);
    $bClient = ccMakeClient($orgB, ['name' => 'B-Client']);

    $userUuid = ccMakeUserForCompany($orgA);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/clients');
    $response->assertStatus(200);

    $uuids = collect($response->json('clients'))->pluck('uuid')->all();
    expect($uuids)->toContain($aClient);
    expect($uuids)->not->toContain($bClient);
});

// ---------------------------------------------------------------------------
// 3. Create produces a client under the resolved org.
// ---------------------------------------------------------------------------
test('org user can create a client company under their current org', function () {
    $orgUuid  = ccMakeOrg();
    $userUuid = ccMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/clients', [
            'name'        => 'Acme Client',
            'client_code' => 'ACME-01',
        ]);

    $response->assertStatus(201);
    $client = $response->json('client');
    expect($client['name'])->toBe('Acme Client');
    expect($client['client_code'])->toBe('ACME-01');
    expect($client['parent_company_uuid'])->toBe($orgUuid);
    expect((bool) $client['is_client'])->toBeTrue();
    expect($client['company_type'])->toBe('client');
});

// ---------------------------------------------------------------------------
// 4. Payload cannot redirect parent_company_uuid / company_type / is_client.
// ---------------------------------------------------------------------------
test('created company is attached to the resolved org, not caller-controlled foreign org input', function () {
    $orgUuid   = ccMakeOrg();
    $foreignOrg = ccMakeOrg();
    $userUuid  = ccMakeUserForCompany($orgUuid);
    $user      = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/v1/companies/clients', [
            'name'                => 'Override Attempt',
            'parent_company_uuid' => $foreignOrg,  // MUST be ignored
            'company_type'        => 'organization', // MUST be ignored
            'is_client'           => false,          // MUST be ignored
        ]);

    $response->assertStatus(201);
    $created = $response->json('client');
    expect($created['parent_company_uuid'])->toBe($orgUuid);
    expect($created['company_type'])->toBe('client');
    expect((bool) $created['is_client'])->toBeTrue();

    // Also confirm by DB lookup (belt and braces).
    $row = DB::table('companies')->where('uuid', $created['uuid'])->first();
    expect($row->parent_company_uuid)->toBe($orgUuid);
    expect($row->company_type)->toBe('client');
    expect((bool) $row->is_client)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 5. Show — in-scope client.
// ---------------------------------------------------------------------------
test('org user can show a client company under their org', function () {
    $orgUuid  = ccMakeOrg();
    $client   = ccMakeClient($orgUuid, ['name' => 'Showable']);
    $userUuid = ccMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/clients/' . $client);
    $response->assertStatus(200);
    expect($response->json('client.uuid'))->toBe($client);
    expect($response->json('client.name'))->toBe('Showable');
});

// ---------------------------------------------------------------------------
// 6. Show — cross-tenant target is 404 (not 403 — don't leak existence).
// ---------------------------------------------------------------------------
test('org user cannot show an out-of-scope client company — 404', function () {
    $orgA     = ccMakeOrg();
    $orgB     = ccMakeOrg();
    $bClient  = ccMakeClient($orgB); // belongs to a different org
    $userUuid = ccMakeUserForCompany($orgA);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/clients/' . $bClient);
    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// 7. Update happy-path.
// ---------------------------------------------------------------------------
test('org user can update an in-scope client company', function () {
    $orgUuid  = ccMakeOrg();
    $client   = ccMakeClient($orgUuid, ['name' => 'Before', 'client_code' => 'OLD']);
    $userUuid = ccMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->putJson('/v1/companies/clients/' . $client, [
            'name'        => 'After',
            'client_code' => 'NEW',
        ]);

    $response->assertStatus(200);
    expect($response->json('client.name'))->toBe('After');
    expect($response->json('client.client_code'))->toBe('NEW');

    $row = DB::table('companies')->where('uuid', $client)->first();
    expect($row->name)->toBe('After');
    expect($row->client_code)->toBe('NEW');
});

// ---------------------------------------------------------------------------
// 8. Update — payload CANNOT alter tenancy fields.
// ---------------------------------------------------------------------------
test('update cannot alter protected tenancy fields', function () {
    $orgA      = ccMakeOrg();
    $orgB      = ccMakeOrg();
    $client    = ccMakeClient($orgA, ['name' => 'Locked', 'client_code' => 'X']);
    $userUuid  = ccMakeUserForCompany($orgA);
    $user      = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->putJson('/v1/companies/clients/' . $client, [
            'name'                => 'Locked v2',
            'parent_company_uuid' => $orgB,           // must be ignored
            'company_type'        => 'organization',  // must be ignored
            'is_client'           => false,           // must be ignored
        ]);

    $response->assertStatus(200);

    $row = DB::table('companies')->where('uuid', $client)->first();
    expect($row->parent_company_uuid)->toBe($orgA);
    expect($row->company_type)->toBe('client');
    expect((bool) $row->is_client)->toBeTrue();
    expect($row->name)->toBe('Locked v2'); // whitelisted field did change
});

// ---------------------------------------------------------------------------
// 9. Delete — in-scope.
// ---------------------------------------------------------------------------
test('org user can delete an in-scope client company', function () {
    $orgUuid  = ccMakeOrg();
    $client   = ccMakeClient($orgUuid);
    $userUuid = ccMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->deleteJson('/v1/companies/clients/' . $client);

    $response->assertStatus(204);
    // Company model uses soft deletes — assert the row is soft-deleted
    // (deleted_at populated) OR entirely gone.
    $stillVisible = Company::where('uuid', $client)->exists();
    expect($stillVisible)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 10. Delete — cross-tenant target is 404.
// ---------------------------------------------------------------------------
test('org user cannot delete an out-of-scope client company — 404', function () {
    $orgA      = ccMakeOrg();
    $orgB      = ccMakeOrg();
    $bClient   = ccMakeClient($orgB);
    $userUuid  = ccMakeUserForCompany($orgA);
    $user      = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')
        ->deleteJson('/v1/companies/clients/' . $bClient);

    $response->assertStatus(404);

    // Record must still exist.
    expect(DB::table('companies')->where('uuid', $bClient)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 11. Client-role user is blocked on every verb.
// ---------------------------------------------------------------------------
test('client-role authenticated user gets 403 on all 5 endpoints', function () {
    $orgUuid    = ccMakeOrg();
    $clientCo   = ccMakeClient($orgUuid);
    $userUuid   = ccMakeUserForCompany($clientCo); // default = client company
    $user       = User::where('uuid', $userUuid)->firstOrFail();
    $someUuid   = (string) Str::uuid();

    $calls = [
        ['getJson',    '/v1/companies/clients'],
        ['postJson',   '/v1/companies/clients', ['name' => 'X']],
        ['getJson',    '/v1/companies/clients/' . $someUuid],
        ['putJson',    '/v1/companies/clients/' . $someUuid, ['name' => 'X']],
        ['deleteJson', '/v1/companies/clients/' . $someUuid],
    ];

    foreach ($calls as $call) {
        [$method, $path] = $call;
        $payload = $call[2] ?? [];

        $response = $this->actingAs($user, 'sanctum')->{$method}($path, $payload);
        expect($response->getStatusCode())->toBe(403);
    }
});

// ---------------------------------------------------------------------------
// 12. Missing company context -> 403 (user has no pivot + no legacy column).
// ---------------------------------------------------------------------------
test('missing company context returns 403', function () {
    $orgUuid  = ccMakeOrg();
    $userUuid = ccMakeUserForCompany($orgUuid);
    // Strip every source of default/accessible company.
    DB::table('company_users')->where('user_uuid', $userUuid)->delete();
    DB::table('users')->where('uuid', $userUuid)->update(['company_uuid' => null]);

    $user = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/clients');
    $response->assertStatus(403);
});

// ---------------------------------------------------------------------------
// 13. Malformed UUID in show path -> 404 (no route-model bypass).
// ---------------------------------------------------------------------------
test('route model binding cannot bypass org-boundary — invalid UUID returns 404', function () {
    $orgUuid  = ccMakeOrg();
    $userUuid = ccMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/clients/garbage');
    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// 14. A non-client company under the same org is NOT exposed by this controller.
// ---------------------------------------------------------------------------
test('non-client companies (even under same org) are not exposed by this controller — 404', function () {
    $orgUuid  = ccMakeOrg();
    // Insert a sibling non-client company flagged under the same parent.
    $nonClientUuid = (string) Str::uuid();
    DB::table('companies')->insert([
        'uuid'                => $nonClientUuid,
        'public_id'           => 'co_' . substr($nonClientUuid, 0, 8),
        'name'                => 'Not A Client',
        'company_type'        => 'organization',
        'is_client'           => false,
        'parent_company_uuid' => $orgUuid,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    $userUuid = ccMakeUserForCompany($orgUuid);
    $user     = User::where('uuid', $userUuid)->firstOrFail();

    // Show → 404
    $this->actingAs($user, 'sanctum')
        ->getJson('/v1/companies/clients/' . $nonClientUuid)
        ->assertStatus(404);

    // Index also excludes it.
    $list = $this->actingAs($user, 'sanctum')->getJson('/v1/companies/clients');
    $list->assertStatus(200);
    $uuids = collect($list->json('clients'))->pluck('uuid')->all();
    expect($uuids)->not->toContain($nonClientUuid);
});
