<?php

use Illuminate\Support\Facades\Schema;

test('companies table has parent_company_uuid, company_type, is_client, client_code, client_settings columns', function () {
    expect(Schema::hasColumn('companies', 'parent_company_uuid'))->toBeTrue();
    expect(Schema::hasColumn('companies', 'company_type'))->toBeTrue();
    expect(Schema::hasColumn('companies', 'is_client'))->toBeTrue();
    expect(Schema::hasColumn('companies', 'client_code'))->toBeTrue();
    expect(Schema::hasColumn('companies', 'client_settings'))->toBeTrue();
});

test('parent_company_uuid is nullable and indexed', function () {
    $col = collect(Schema::getColumns('companies'))->firstWhere('name', 'parent_company_uuid');
    expect($col)->not->toBeNull();
    expect($col['nullable'])->toBeTrue();
});

test('company_type defaults to organization', function () {
    $col = collect(Schema::getColumns('companies'))->firstWhere('name', 'company_type');
    expect($col['default'])->toContain('organization');
});
