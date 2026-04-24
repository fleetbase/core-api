<?php

use Fleetbase\Http\Middleware\CompanyContextSelfResolver;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Fixture helpers — same pattern as Phase 1 Task 8 tests, adapted for brevity.

function seedOrgCompany(): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert([
        'uuid' => $uuid, 'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => 'Org', 'company_type' => 'organization', 'is_client' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $uuid;
}

function seedClientCompany(string $parentUuid): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert([
        'uuid' => $uuid, 'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => 'Client', 'company_type' => 'client', 'is_client' => true,
        'parent_company_uuid' => $parentUuid,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $uuid;
}

function seedUserForCompany(string $companyUuid, bool $isDefault = true): string
{
    $uuid = (string) Str::uuid();
    DB::table('users')->insert([
        'uuid' => $uuid, 'public_id' => 'u_' . substr($uuid, 0, 8),
        'company_uuid' => $companyUuid, 'name' => 'U',
        'email' => 'u-' . substr($uuid, 0, 8) . '@x.test',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_users')->insert([
        'uuid' => (string) Str::uuid(), 'user_uuid' => $uuid,
        'company_uuid' => $companyUuid, 'status' => 'active',
        'external' => false, 'access_level' => 'full',
        'is_default' => $isDefault,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $uuid;
}

function grantPivot(string $userUuid, string $companyUuid): void
{
    DB::table('company_users')->insert([
        'uuid' => (string) Str::uuid(), 'user_uuid' => $userUuid,
        'company_uuid' => $companyUuid, 'status' => 'active',
        'external' => false, 'access_level' => 'full', 'is_default' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function runSelfResolver(User $user, ?string $header): \Symfony\Component\HttpFoundation\Response
{
    $request = Request::create('/test');
    if ($header !== null) {
        $request->headers->set('X-Company-Context', $header);
    }
    $request->setUserResolver(fn () => $user);

    return (new CompanyContextSelfResolver())->handle($request, fn ($r) => response('ok'));
}

afterEach(function () {
    if (app()->bound('companyContext')) {
        app()->forgetInstance('companyContext');
    }
});

test('unauthenticated request passes through', function () {
    $request = Request::create('/test');
    $response = (new CompanyContextSelfResolver())->handle($request, fn ($r) => response('ok'));
    expect($response->getContent())->toBe('ok');
});

test('client user without header resolves to own client company (no hard-block)', function () {
    $parent = seedOrgCompany();
    $client = seedClientCompany($parent);
    $userUuid = seedUserForCompany($client);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runSelfResolver($user, null);

    expect($response->getContent())->toBe('ok');
    expect(app('companyContext')->uuid)->toBe($client);
});

test('client user CANNOT target an unauthorized company via header', function () {
    $parent = seedOrgCompany();
    $client = seedClientCompany($parent);
    $sibling = seedClientCompany($parent);
    $userUuid = seedUserForCompany($client);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runSelfResolver($user, $sibling);

    expect($response->getStatusCode())->toBe(403);
});

test('org user with header targeting an accessible client resolves to that client', function () {
    $orgUuid = seedOrgCompany();
    $client = seedClientCompany($orgUuid);
    $userUuid = seedUserForCompany($orgUuid);
    grantPivot($userUuid, $client);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runSelfResolver($user, $client);

    expect($response->getContent())->toBe('ok');
    expect(app('companyContext')->uuid)->toBe($client);
});

test('org user with header targeting an inaccessible company returns 403', function () {
    $orgUuid = seedOrgCompany();
    $unrelated = seedOrgCompany();
    $userUuid = seedUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runSelfResolver($user, $unrelated);

    expect($response->getStatusCode())->toBe(403);
});

test('invalid UUID header returns 403 (no DB hit)', function () {
    $orgUuid = seedOrgCompany();
    $userUuid = seedUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runSelfResolver($user, 'garbage-not-a-uuid');

    expect($response->getStatusCode())->toBe(403);
});

test('valid UUID pointing to a non-existent company returns 403 (dangling pivot)', function () {
    $orgUuid = seedOrgCompany();
    $ghost = (string) Str::uuid();
    $userUuid = seedUserForCompany($orgUuid);
    grantPivot($userUuid, $ghost);  // pivot exists but company does not

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runSelfResolver($user, $ghost);

    expect($response->getStatusCode())->toBe(403);
});

test('empty header falls back to defaultCompany()', function () {
    $orgUuid = seedOrgCompany();
    $userUuid = seedUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runSelfResolver($user, '');

    expect($response->getContent())->toBe('ok');
    expect(app('companyContext')->uuid)->toBe($orgUuid);
});

test('user with no pivot rows and no legacy company_uuid returns 403', function () {
    $orgUuid = seedOrgCompany();
    $userUuid = seedUserForCompany($orgUuid);
    DB::table('company_users')->where('user_uuid', $userUuid)->delete();
    DB::table('users')->where('uuid', $userUuid)->update(['company_uuid' => null]);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runSelfResolver($user, null);

    expect($response->getStatusCode())->toBe(403);
});
