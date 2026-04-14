<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the Fleetbase Testbench-backed TestCase and RefreshDatabase trait to
| feature tests that need a booted Laravel application and a fresh database
| per test. Scoped to `Feature/MultiTenant` (and any future subdirectories
| that require the same) so we don't disturb the bare `tests/Feature.php`
| placeholder, which only does `expect(true)->toBeTrue()` and must not be
| wrapped in a DB-refreshing lifecycle.
|
*/

uses(
    \Fleetbase\Tests\TestCase::class,
    \Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature/MultiTenant');
