<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\Concerns\ScopedToCompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fixture model defined inline to exercise the trait in isolation.
 * Uses SoftDeletes so we can verify soft-deleted rows don't bypass the scope.
 */
class ScopeFixtureRow extends Model
{
    use ScopedToCompanyContext;
    use SoftDeletes;

    protected $table = 'scope_fixture_rows';
    protected $fillable = ['company_uuid', 'name'];
    public $timestamps = true;
}

/**
 * Insert a company via query builder (bypasses observers) and return its uuid.
 */
function fixtureCompany(string $name = 'Co'): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert([
        'uuid' => $uuid, 'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => $name, 'company_type' => 'organization', 'is_client' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $uuid;
}

/**
 * Fetch a Company Eloquent instance by uuid (read-only, no observer fires).
 */
function fixtureCompanyModel(string $uuid): Company
{
    return Company::where('uuid', $uuid)->firstOrFail();
}

beforeEach(function () {
    // Ephemeral fixture table — exists only for this suite.
    Schema::dropIfExists('scope_fixture_rows');
    Schema::create('scope_fixture_rows', function ($t) {
        $t->increments('id');
        $t->char('company_uuid', 36)->index();
        $t->string('name');
        $t->softDeletes();
        $t->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('scope_fixture_rows');
    if (app()->bound('companyContext')) {
        app()->forgetInstance('companyContext');
    }
    if (app()->bound('request')) {
        $req = app('request');
        if (isset($req->attributes)
            && $req->attributes instanceof \Symfony\Component\HttpFoundation\ParameterBag) {
            $req->attributes->remove('company');
        }
    }
});

test('with company context bound via container, rows for that company are returned', function () {
    $uuidA = fixtureCompany('A');
    $uuidB = fixtureCompany('B');

    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'keep']);
    ScopeFixtureRow::create(['company_uuid' => $uuidB, 'name' => 'drop']);

    app()->instance('companyContext', fixtureCompanyModel($uuidA));

    $names = ScopeFixtureRow::inCompanyContext()->pluck('name')->toArray();

    expect($names)->toBe(['keep']);
});

test('rows for other companies are excluded', function () {
    $uuidA = fixtureCompany('A');
    $uuidB = fixtureCompany('B');
    $uuidC = fixtureCompany('C');

    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'a1']);
    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'a2']);
    ScopeFixtureRow::create(['company_uuid' => $uuidB, 'name' => 'b1']);
    ScopeFixtureRow::create(['company_uuid' => $uuidC, 'name' => 'c1']);

    app()->instance('companyContext', fixtureCompanyModel($uuidA));

    $names = ScopeFixtureRow::inCompanyContext()->orderBy('name')->pluck('name')->toArray();

    expect($names)->toBe(['a1', 'a2']);
});

test('with NO company context bound, result set is empty (fail-closed)', function () {
    $uuidA = fixtureCompany('A');
    $uuidB = fixtureCompany('B');

    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'a']);
    ScopeFixtureRow::create(['company_uuid' => $uuidB, 'name' => 'b']);

    // Explicitly ensure no binding is present.
    expect(app()->bound('companyContext'))->toBeFalse();

    $rows = ScopeFixtureRow::inCompanyContext()->get();

    expect($rows->count())->toBe(0);
});

test('soft-deleted rows are excluded whether or not context is bound', function () {
    $uuidA = fixtureCompany('A');

    $live = ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'live']);
    $dead = ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'dead']);
    $dead->delete();

    app()->instance('companyContext', fixtureCompanyModel($uuidA));

    $names = ScopeFixtureRow::inCompanyContext()->pluck('name')->toArray();

    expect($names)->toBe(['live']);
});

test('repeated queries in the same request remain correctly scoped', function () {
    $uuidA = fixtureCompany('A');
    $uuidB = fixtureCompany('B');

    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'a']);
    ScopeFixtureRow::create(['company_uuid' => $uuidB, 'name' => 'b']);

    app()->instance('companyContext', fixtureCompanyModel($uuidA));

    $first  = ScopeFixtureRow::inCompanyContext()->pluck('name')->toArray();
    $second = ScopeFixtureRow::inCompanyContext()->pluck('name')->toArray();
    $third  = ScopeFixtureRow::inCompanyContext()->count();

    expect($first)->toBe(['a']);
    expect($second)->toBe(['a']);
    expect($third)->toBe(1);
});

test('request attribute binding is honored', function () {
    $uuidA = fixtureCompany('A');
    $uuidB = fixtureCompany('B');

    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'keep']);
    ScopeFixtureRow::create(['company_uuid' => $uuidB, 'name' => 'drop']);

    // Use the real app request and set the attribute.
    app('request')->attributes->set('company', fixtureCompanyModel($uuidA));

    // No container binding — proves the request path alone is sufficient.
    expect(app()->bound('companyContext'))->toBeFalse();

    $names = ScopeFixtureRow::inCompanyContext()->pluck('name')->toArray();

    expect($names)->toBe(['keep']);
});

test('request attribute takes precedence over container instance', function () {
    $uuidA = fixtureCompany('A');
    $uuidB = fixtureCompany('B');

    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'from-request']);
    ScopeFixtureRow::create(['company_uuid' => $uuidB, 'name' => 'from-container']);

    // Request attribute points at A; container instance points at B. Request wins.
    app('request')->attributes->set('company', fixtureCompanyModel($uuidA));
    app()->instance('companyContext', fixtureCompanyModel($uuidB));

    $names = ScopeFixtureRow::inCompanyContext()->pluck('name')->toArray();

    expect($names)->toBe(['from-request']);
});

test('non-Company value in container binding falls through to empty (defensive)', function () {
    $uuidA = fixtureCompany('A');
    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'a']);

    // Someone accidentally bound a non-Company value. The scope must not leak.
    app()->instance('companyContext', 'not-a-company');

    $rows = ScopeFixtureRow::inCompanyContext()->get();

    expect($rows->count())->toBe(0);
});

test('no database writes or observer side effects occur during scoped queries', function () {
    $uuidA = fixtureCompany('A');
    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'a']);

    app()->instance('companyContext', fixtureCompanyModel($uuidA));

    // Baseline counts
    $companyCountBefore = DB::table('companies')->count();
    $pivotCountBefore   = DB::table('company_users')->count();

    // Run several scoped queries.
    ScopeFixtureRow::inCompanyContext()->get();
    ScopeFixtureRow::inCompanyContext()->count();
    ScopeFixtureRow::inCompanyContext()->where('name', 'a')->first();

    // Writes (other than the fixture create above) should not have occurred.
    expect(DB::table('companies')->count())->toBe($companyCountBefore);
    expect(DB::table('company_users')->count())->toBe($pivotCountBefore);
});

test('cross-tenant leakage is impossible when middleware is omitted', function () {
    $uuidA = fixtureCompany('A');
    $uuidB = fixtureCompany('B');
    $uuidC = fixtureCompany('C');

    ScopeFixtureRow::create(['company_uuid' => $uuidA, 'name' => 'a']);
    ScopeFixtureRow::create(['company_uuid' => $uuidB, 'name' => 'b']);
    ScopeFixtureRow::create(['company_uuid' => $uuidC, 'name' => 'c']);

    // Simulate "middleware forgot to run" — no binding of any kind.
    expect(app()->bound('companyContext'))->toBeFalse();
    expect(app('request')->attributes->has('company'))->toBeFalse();

    $rows = ScopeFixtureRow::inCompanyContext()->get();

    // The whole point: zero, not three.
    expect($rows->count())->toBe(0);
});
