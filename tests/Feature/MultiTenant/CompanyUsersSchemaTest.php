<?php

use Illuminate\Support\Facades\Schema;

test('company_users table has access_level and is_default columns', function () {
    expect(Schema::hasColumn('company_users', 'access_level'))->toBeTrue();
    expect(Schema::hasColumn('company_users', 'is_default'))->toBeTrue();
});

test('is_default defaults to false', function () {
    $col = collect(Schema::getColumns('company_users'))->firstWhere('name', 'is_default');
    expect($col)->not->toBeNull();
    // Drivers report boolean defaults inconsistently: MySQL/MariaDB return "'0'"
    // (literal quotes), SQLite returns "0", Postgres may return "false". Normalize
    // by stripping surrounding single quotes before casting to bool.
    $raw = trim((string) $col['default'], "'");
    expect((bool) $raw)->toBeFalse();
});

test('access_level defaults to full', function () {
    $col = collect(Schema::getColumns('company_users'))->firstWhere('name', 'access_level');
    expect($col['default'])->toContain('full');
});
