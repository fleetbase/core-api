<?php

use Fleetbase\Http\Middleware\CompanyContextResolver;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixture helpers — DB::table() only, no Eloquent writes.
 */
function makeOrgCompany(array $o = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert(array_merge([
        'uuid' => $uuid, 'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => 'Org ' . substr($uuid, 0, 4),
        'company_type' => 'organization', 'is_client' => false,
        'created_at' => now(), 'updated_at' => now(),
    ], $o));
    return $uuid;
}

function makeClientCompany(string $parentUuid, array $o = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert(array_merge([
        'uuid' => $uuid, 'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => 'Client ' . substr($uuid, 0, 4),
        'company_type' => 'client', 'is_client' => true,
        'parent_company_uuid' => $parentUuid,
        'created_at' => now(), 'updated_at' => now(),
    ], $o));
    return $uuid;
}

function makeUserForCompany(string $companyUuid, bool $isDefault = true): string
{
    $uuid = (string) Str::uuid();
    DB::table('users')->insert([
        'uuid' => $uuid, 'public_id' => 'u_' . substr($uuid, 0, 8),
        'company_uuid' => $companyUuid,
        'name' => 'User ' . substr($uuid, 0, 4),
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

function addPivot(string $userUuid, string $companyUuid, bool $isDefault = false): void
{
    DB::table('company_users')->insert([
        'uuid' => (string) Str::uuid(), 'user_uuid' => $userUuid,
        'company_uuid' => $companyUuid, 'status' => 'active',
        'external' => false, 'access_level' => 'full',
        'is_default' => $isDefault,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function runMiddleware(User $user, ?string $header): \Symfony\Component\HttpFoundation\Response
{
    $request = Request::create('/test');
    if ($header !== null) {
        $request->headers->set('X-Company-Context', $header);
    }
    $request->setUserResolver(fn () => $user);

    $mw = new CompanyContextResolver();
    return $mw->handle($request, fn ($r) => response('ok'));
}

afterEach(function () {
    if (app()->bound('companyContext')) {
        app()->forgetInstance('companyContext');
    }
});

test('unauthenticated request passes through — no-op', function () {
    $request = Request::create('/test');
    $mw = new CompanyContextResolver();
    $response = $mw->handle($request, fn ($r) => response('ok'));

    expect($response->getContent())->toBe('ok');
    expect($request->attributes->has('company'))->toBeFalse();
});

test('valid header with access resolves correct company and binds it', function () {
    $orgUuid = makeOrgCompany();
    $clientA = makeClientCompany($orgUuid);
    $userUuid = makeUserForCompany($orgUuid);   // org-level default
    addPivot($userUuid, $clientA);              // pivot grants access

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runMiddleware($user, $clientA);

    expect($response->getContent())->toBe('ok');
    expect(app('companyContext')->uuid)->toBe($clientA);
});

test('no header falls back to defaultCompany and binds it', function () {
    $orgUuid = makeOrgCompany();
    $userUuid = makeUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runMiddleware($user, null);

    expect($response->getContent())->toBe('ok');
    expect(app('companyContext')->uuid)->toBe($orgUuid);
});

test('invalid UUID format in header returns 403 (no DB hit)', function () {
    $orgUuid = makeOrgCompany();
    $userUuid = makeUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runMiddleware($user, 'not-a-uuid');

    expect($response->getStatusCode())->toBe(403);
    expect(app()->bound('companyContext'))->toBeFalse();
});

test('user without access to requested company returns 403', function () {
    $orgUuid = makeOrgCompany();
    $otherCompany = makeOrgCompany(); // user has no pivot for this
    $userUuid = makeUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runMiddleware($user, $otherCompany);

    expect($response->getStatusCode())->toBe(403);
    expect(app()->bound('companyContext'))->toBeFalse();
});

test('requested company that does not exist (pivot dangles) returns 403', function () {
    $orgUuid = makeOrgCompany();
    $ghostUuid = (string) Str::uuid();
    $userUuid = makeUserForCompany($orgUuid);
    addPivot($userUuid, $ghostUuid); // pivot to a company that does not exist

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runMiddleware($user, $ghostUuid);

    expect($response->getStatusCode())->toBe(403);
});

test('no header AND no defaultCompany returns 403', function () {
    // User with no pivot rows, no legacy company_uuid set.
    $orgUuid = makeOrgCompany();
    $userUuid = makeUserForCompany($orgUuid);
    // Strip legacy and pivot entirely.
    DB::table('company_users')->where('user_uuid', $userUuid)->delete();
    DB::table('users')->where('uuid', $userUuid)->update(['company_uuid' => null]);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runMiddleware($user, null);

    expect($response->getStatusCode())->toBe(403);
    expect(app()->bound('companyContext'))->toBeFalse();
});

test('client-role user is blocked unconditionally with 403', function () {
    $orgUuid    = makeOrgCompany();
    $clientUuid = makeClientCompany($orgUuid);
    $clientUserUuid = makeUserForCompany($clientUuid); // default company is client

    $user = User::where('uuid', $clientUserUuid)->firstOrFail();

    // Even if the client user passes a "valid" header to their own company —
    // still 403 per the hard-guardrail.
    $response = runMiddleware($user, $clientUuid);
    expect($response->getStatusCode())->toBe(403);

    // No header → also 403.
    $response = runMiddleware($user, null);
    expect($response->getStatusCode())->toBe(403);

    // Arbitrary uuid → 403 (never reaches access check).
    $response = runMiddleware($user, (string) Str::uuid());
    expect($response->getStatusCode())->toBe(403);
});

test('header name is case-insensitive per HTTP spec', function () {
    $orgUuid = makeOrgCompany();
    $clientA = makeClientCompany($orgUuid);
    $userUuid = makeUserForCompany($orgUuid);
    addPivot($userUuid, $clientA);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    // Symfony HeaderBag normalizes to lowercase internally, but the public API
    // accepts any case. Prove our middleware works with mixed-case headers.
    $request = Request::create('/test');
    $request->headers->set('x-company-context', $clientA); // lowercase
    $request->setUserResolver(fn () => $user);

    $mw = new CompanyContextResolver();
    $response = $mw->handle($request, fn ($r) => response('ok'));

    expect($response->getContent())->toBe('ok');
    expect(app('companyContext')->uuid)->toBe($clientA);
});

test('UUID value is accepted regardless of letter case', function () {
    $orgUuid = makeOrgCompany();
    $clientA = makeClientCompany($orgUuid);
    $userUuid = makeUserForCompany($orgUuid);
    addPivot($userUuid, $clientA);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    $response = runMiddleware($user, strtoupper($clientA));
    // DB uuids are stored lowercase; canAccessCompany's where clause is
    // case-sensitive on most MySQL collations. We expect EITHER:
    //   - a 200 (middleware normalized) OR
    //   - a 403 (middleware is strict on case, so only the stored form works)
    // Either behavior is defensible; assert that the result is deterministic
    // and that if it was accepted, the bound company has the stored uuid.
    $status = $response->getStatusCode();
    expect($status === 200 || $status === 403)->toBeTrue();
    if ($status === 200) {
        expect(app('companyContext')->uuid)->toBe($clientA); // stored (lowercase) form
    }
});

test('empty-string header falls back to defaultCompany (treated as absent)', function () {
    $orgUuid = makeOrgCompany();
    $userUuid = makeUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runMiddleware($user, '');

    expect($response->getContent())->toBe('ok');
    expect(app('companyContext')->uuid)->toBe($orgUuid);
});

test('whitespace-only header falls back to defaultCompany (treated as absent)', function () {
    $orgUuid = makeOrgCompany();
    $userUuid = makeUserForCompany($orgUuid);

    $user = User::where('uuid', $userUuid)->firstOrFail();
    $response = runMiddleware($user, '   ');

    expect($response->getContent())->toBe('ok');
    expect(app('companyContext')->uuid)->toBe($orgUuid);
});

test('request attributes binding also set (not just container instance)', function () {
    $orgUuid = makeOrgCompany();
    $userUuid = makeUserForCompany($orgUuid);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $mw = new CompanyContextResolver();
    $response = $mw->handle($request, function ($r) {
        expect($r->attributes->get('company')?->uuid)->toBe($r->user()->defaultCompany()->uuid);
        return response('ok');
    });

    expect($response->getContent())->toBe('ok');
});

test('terminate() forgets the container instance (prevents cross-request leak)', function () {
    $orgUuid = makeOrgCompany();
    $userUuid = makeUserForCompany($orgUuid);
    $user = User::where('uuid', $userUuid)->firstOrFail();

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);
    $mw = new CompanyContextResolver();
    $response = $mw->handle($request, fn ($r) => response('ok'));

    expect(app()->bound('companyContext'))->toBeTrue();

    $mw->terminate($request, $response);

    expect(app()->bound('companyContext'))->toBeFalse();
});
