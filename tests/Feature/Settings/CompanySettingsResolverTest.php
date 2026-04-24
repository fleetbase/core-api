<?php

use Fleetbase\Models\Setting;
use Fleetbase\Support\CompanySettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function makeOrg(): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert([
        'uuid' => $uuid, 'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => 'Org ' . substr($uuid, 0, 4),
        'company_type' => 'organization', 'is_client' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $uuid;
}

function makeClient(string $parentUuid): string
{
    $uuid = (string) Str::uuid();
    DB::table('companies')->insert([
        'uuid' => $uuid, 'public_id' => 'co_' . substr($uuid, 0, 8),
        'name' => 'Client ' . substr($uuid, 0, 4),
        'company_type' => 'client', 'is_client' => true,
        'parent_company_uuid' => $parentUuid,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $uuid;
}

test('resolver returns default when no stored setting exists', function () {
    $org = makeOrg();
    $resolver = CompanySettingsResolver::forCompany($org);

    expect($resolver->get('billing.default_payment_terms_days'))->toBe(30);
    expect($resolver->get('tendering.default_expiration_hours'))->toBe(4);
    expect($resolver->get('audit.default_tolerance_percent'))->toBe(2.0);
});

test('resolver returns stored company value over default', function () {
    $org = makeOrg();
    Setting::configure("company.{$org}.billing.default_payment_terms_days", 45);

    $resolver = CompanySettingsResolver::forCompany($org);
    expect($resolver->get('billing.default_payment_terms_days'))->toBe(45);
});

test('client company inherits parent values when unset', function () {
    $parent = makeOrg();
    $client = makeClient($parent);
    Setting::configure("company.{$parent}.billing.default_payment_terms_days", 60);

    $resolver = CompanySettingsResolver::forCompany($client);
    expect($resolver->get('billing.default_payment_terms_days'))->toBe(60);
});

test('client company override wins over parent value', function () {
    $parent = makeOrg();
    $client = makeClient($parent);
    Setting::configure("company.{$parent}.billing.default_payment_terms_days", 60);
    Setting::configure("company.{$client}.billing.default_payment_terms_days", 15);

    $resolver = CompanySettingsResolver::forCompany($client);
    expect($resolver->get('billing.default_payment_terms_days'))->toBe(15);
});

test('set() persists via Setting::configure with company-prefixed key', function () {
    $org = makeOrg();
    $resolver = CompanySettingsResolver::forCompany($org);
    $resolver->set('billing.invoice_number_prefix', 'INVX');

    expect(Setting::lookup("company.{$org}.billing.invoice_number_prefix", null))->toBe('INVX');
});

test('all() returns merged settings with inheritance and defaults', function () {
    $parent = makeOrg();
    $client = makeClient($parent);
    Setting::configure("company.{$parent}.billing.default_payment_terms_days", 60);
    Setting::configure("company.{$client}.tendering.default_expiration_hours", 8);

    $all = CompanySettingsResolver::forCompany($client)->all();

    expect($all['billing']['default_payment_terms_days'])->toBe(60);   // inherited
    expect($all['tendering']['default_expiration_hours'])->toBe(8);     // override
    expect($all['audit']['default_tolerance_percent'])->toBe(2.0);      // default
});

test('defaults() returns the full default tree', function () {
    $defaults = CompanySettingsResolver::defaults();
    expect($defaults)->toHaveKeys(['billing', 'tendering', 'documents', 'pay_files', 'fuel', 'audit']);
    expect($defaults['billing']['default_currency'])->toBe('USD');
});
