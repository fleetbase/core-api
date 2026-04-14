<?php

use Fleetbase\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Insert a company via query builder to bypass CompanyObserver for fixture setup,
 * then return a fresh Eloquent instance. This keeps tests focused on model
 * BEHAVIOR (relationships, scopes) rather than creation side effects.
 */
function makeCompany(array $attributes = []): Company
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert(array_merge([
        'uuid' => $uuid,
        'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => 'Fixture Co ' . substr($uuid, 0, 4),
        'company_type' => 'organization',
        'is_client' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    return Company::where('uuid', $uuid)->firstOrFail();
}

test('parent company has many client companies, and clients know their parent', function () {
    $parent = makeCompany(['company_type' => 'organization']);
    $child = makeCompany([
        'company_type' => 'client',
        'is_client' => true,
        'parent_company_uuid' => $parent->uuid,
    ]);

    expect($parent->clientCompanies->pluck('uuid')->toArray())->toContain($child->uuid);
    expect($child->parentCompany->uuid)->toBe($parent->uuid);
});

test('isClient and isOrganization predicates', function () {
    $org = makeCompany(['company_type' => 'organization']);
    $client = makeCompany(['company_type' => 'client', 'is_client' => true]);

    expect($org->isOrganization())->toBeTrue();
    expect($org->isClient())->toBeFalse();
    expect($client->isClient())->toBeTrue();
    expect($client->isOrganization())->toBeFalse();
});

test('isClient returns true when either column signals client state', function () {
    // Predicate should tolerate either signal (is_client column OR company_type='client')
    $onlyFlag = new Company(['company_type' => 'organization', 'is_client' => true]);
    $onlyType = new Company(['company_type' => 'client', 'is_client' => false]);
    $neither  = new Company(['company_type' => 'organization', 'is_client' => false]);

    expect($onlyFlag->isClient())->toBeTrue();
    expect($onlyType->isClient())->toBeTrue();
    expect($neither->isClient())->toBeFalse();
});

test('getAccessibleCompanyUuids returns self plus children for organization', function () {
    $parent = makeCompany(['company_type' => 'organization']);
    $childA = makeCompany(['company_type' => 'client', 'is_client' => true, 'parent_company_uuid' => $parent->uuid]);
    $childB = makeCompany(['company_type' => 'client', 'is_client' => true, 'parent_company_uuid' => $parent->uuid]);

    $uuids = $parent->getAccessibleCompanyUuids();

    expect($uuids)->toContain($parent->uuid, $childA->uuid, $childB->uuid);
    expect(count($uuids))->toBe(3);
});

test('getAccessibleCompanyUuids for a client returns only self', function () {
    $parent = makeCompany(['company_type' => 'organization']);
    $client = makeCompany(['company_type' => 'client', 'is_client' => true, 'parent_company_uuid' => $parent->uuid]);

    expect($client->getAccessibleCompanyUuids())->toBe([$client->uuid]);
});

test('getAccessibleCompanyUuids for a lone organization with no children returns only self', function () {
    $org = makeCompany(['company_type' => 'organization']);

    expect($org->getAccessibleCompanyUuids())->toBe([$org->uuid]);
});

test('scopeClients and scopeOrganizations filter correctly', function () {
    $orgCount    = Company::organizations()->count();
    $clientCount = Company::clients()->count();

    makeCompany(['company_type' => 'organization']);
    makeCompany(['company_type' => 'client', 'is_client' => true]);
    makeCompany(['company_type' => 'client', 'is_client' => true]);

    expect(Company::organizations()->count())->toBe($orgCount + 1);
    expect(Company::clients()->count())->toBe($clientCount + 2);
});

test('client_settings is castable to array when JSON is stored', function () {
    $company = makeCompany([
        'company_type' => 'client',
        'is_client' => true,
        'client_settings' => json_encode(['foo' => 'bar', 'nested' => ['baz' => 1]]),
    ]);

    expect($company->client_settings)->toBeArray();
    expect($company->client_settings['foo'])->toBe('bar');
    expect($company->client_settings['nested']['baz'])->toBe(1);
});
