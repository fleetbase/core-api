<?php

use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['broadcasting.default' => 'null']);
});

function makeOrgContext(): array
{
    $orgUuid = (string) Str::uuid();
    DB::table('companies')->insert([
        'uuid' => $orgUuid, 'public_id' => 'co_' . substr($orgUuid, 0, 8),
        'name' => 'Org ' . substr($orgUuid, 0, 4),
        'company_type' => 'organization', 'is_client' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $userUuid = (string) Str::uuid();
    DB::table('users')->insert([
        'uuid' => $userUuid, 'public_id' => 'u_' . substr($userUuid, 0, 8),
        'company_uuid' => $orgUuid, 'name' => 'Op',
        'email' => 'op-' . substr($userUuid, 0, 8) . '@x.test',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('company_users')->insert([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $userUuid, 'company_uuid' => $orgUuid,
        'status' => 'active', 'external' => false, 'access_level' => 'full', 'is_default' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$orgUuid, User::where('uuid', $userUuid)->firstOrFail()];
}

function makeClientUnderParent(string $parentUuid): array
{
    $clientUuid = (string) Str::uuid();
    DB::table('companies')->insert([
        'uuid' => $clientUuid, 'public_id' => 'co_' . substr($clientUuid, 0, 8),
        'name' => 'Client',
        'company_type' => 'client', 'is_client' => true,
        'parent_company_uuid' => $parentUuid,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $userUuid = (string) Str::uuid();
    DB::table('users')->insert([
        'uuid' => $userUuid, 'public_id' => 'u_' . substr($userUuid, 0, 8),
        'company_uuid' => $clientUuid, 'name' => 'Client User',
        'email' => 'cu-' . substr($userUuid, 0, 8) . '@x.test',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('company_users')->insert([
        'uuid' => (string) Str::uuid(),
        'user_uuid' => $userUuid, 'company_uuid' => $clientUuid,
        'status' => 'active', 'external' => false, 'access_level' => 'full', 'is_default' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$clientUuid, User::where('uuid', $userUuid)->firstOrFail()];
}

test('GET /current returns resolved settings (defaults when nothing stored)', function () {
    [$orgUuid, $user] = makeOrgContext();

    $this->actingAs($user, 'sanctum');
    $response = $this->getJson('/v1/company-settings/current');

    $response->assertOk();
    $response->assertJsonPath('settings.billing.default_currency', 'USD');
    $response->assertJsonPath('settings.tendering.default_expiration_hours', 4);
});

test('GET /current reflects client override on top of parent inheritance', function () {
    [$parentUuid, $parentUser] = makeOrgContext();
    [$clientUuid, $clientUser] = makeClientUnderParent($parentUuid);

    Setting::configure("company.{$parentUuid}.billing.default_payment_terms_days", 60);
    Setting::configure("company.{$clientUuid}.billing.default_payment_terms_days", 15);

    $this->actingAs($clientUser, 'sanctum');
    $response = $this->getJson('/v1/company-settings/current');

    $response->assertOk();
    $response->assertJsonPath('settings.billing.default_payment_terms_days', 15);
});

test('GET /current falls back to parent when client has no own value', function () {
    [$parentUuid, $parentUser] = makeOrgContext();
    [$clientUuid, $clientUser] = makeClientUnderParent($parentUuid);

    Setting::configure("company.{$parentUuid}.billing.default_payment_terms_days", 60);

    $this->actingAs($clientUser, 'sanctum');
    $response = $this->getJson('/v1/company-settings/current');

    $response->assertOk();
    $response->assertJsonPath('settings.billing.default_payment_terms_days', 60);
});

test('PUT /current updates only the active company and round-trips', function () {
    [$orgUuid, $user] = makeOrgContext();

    $this->actingAs($user, 'sanctum');
    $this->putJson('/v1/company-settings/current', [
        'settings' => [
            'billing.default_payment_terms_days' => 45,
            'audit.auto_audit_on_receive'        => false,
        ],
    ])->assertOk();

    $fresh = $this->getJson('/v1/company-settings/current')->json();
    expect($fresh['settings']['billing']['default_payment_terms_days'])->toBe(45);
    expect($fresh['settings']['audit']['auto_audit_on_receive'])->toBeFalse();
    expect($fresh['settings']['billing']['default_currency'])->toBe('USD');  // preserved default
});

test('PUT /current does NOT write to parent under any circumstance', function () {
    [$parentUuid, $parentUser] = makeOrgContext();
    [$clientUuid, $clientUser] = makeClientUnderParent($parentUuid);

    $this->actingAs($clientUser, 'sanctum');
    $this->putJson('/v1/company-settings/current', [
        'settings' => ['billing.default_payment_terms_days' => 99],
    ])->assertOk();

    expect(Setting::lookup("company.{$parentUuid}.billing.default_payment_terms_days", null))->toBeNull();
    expect(Setting::lookup("company.{$clientUuid}.billing.default_payment_terms_days", null))->toBe(99);
});

test('PATCH /current behaves identically to PUT /current', function () {
    [$orgUuid, $user] = makeOrgContext();

    $this->actingAs($user, 'sanctum');
    $this->patchJson('/v1/company-settings/current', [
        'settings' => ['billing.invoice_number_prefix' => 'PATCH'],
    ])->assertOk();

    $fresh = $this->getJson('/v1/company-settings/current')->json();
    expect($fresh['settings']['billing']['invoice_number_prefix'])->toBe('PATCH');
});

test('settings are strictly tenant-scoped — org A never sees org B writes', function () {
    [$orgA, $userA] = makeOrgContext();
    [$orgB, $userB] = makeOrgContext();

    $this->actingAs($userA, 'sanctum');
    $this->putJson('/v1/company-settings/current', [
        'settings' => ['billing.invoice_number_prefix' => 'ACME'],
    ])->assertOk();

    $this->actingAs($userB, 'sanctum');
    $response = $this->getJson('/v1/company-settings/current')->json();
    expect($response['settings']['billing']['invoice_number_prefix'])->toBe('INV');
});

test('unauthenticated request returns 401', function () {
    $this->getJson('/v1/company-settings/current')->assertStatus(401);
});

test('PUT with no settings key returns 422', function () {
    [$orgUuid, $user] = makeOrgContext();
    $this->actingAs($user, 'sanctum');

    $this->putJson('/v1/company-settings/current', [])->assertStatus(422);
});

test('PUT with non-array settings value returns 422', function () {
    [$orgUuid, $user] = makeOrgContext();
    $this->actingAs($user, 'sanctum');

    $this->putJson('/v1/company-settings/current', ['settings' => 'not-an-array'])
        ->assertStatus(422);
});

test('PUT with indexed array under settings returns 422', function () {
    [$orgUuid, $user] = makeOrgContext();
    $this->actingAs($user, 'sanctum');

    $this->putJson('/v1/company-settings/current', ['settings' => ['a', 'b', 'c']])
        ->assertStatus(422);
});

test('PUT with numeric (non-string) key in settings is rejected', function () {
    [$orgUuid, $user] = makeOrgContext();
    $this->actingAs($user, 'sanctum');

    $this->putJson('/v1/company-settings/current', [
        'settings' => [
            'billing.default_currency' => 'EUR',
            0 => 'junk',
        ],
    ])->assertStatus(422);
});
