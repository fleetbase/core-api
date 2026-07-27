<?php

/**
 * Regression tests for production behavior changes bundled into the coverage PR (audit H5).
 *
 * These lock the intended behavior of changes that were shipped alongside the test-coverage
 * work so that a later revert/regression is caught. Each corresponds to a diff called out in
 * the audit.
 */

use Fleetbase\Support\Utils;

afterEach(function () {
    // Restore process env mutated by these tests.
    putenv('MAIL_FROM_ADDRESS');
    putenv('CONSOLE_HOST');
});

test('getDefaultMailFromAddress resolves from the process environment via getenv', function () {
    // The PR switched env('MAIL_FROM_ADDRESS') -> getenv('MAIL_FROM_ADDRESS'); this locks that
    // getenv-backed resolution so the value is read from the process environment.
    putenv('MAIL_FROM_ADDRESS=ops@fleetbase.test');

    expect(Utils::getDefaultMailFromAddress())->toBe('ops@fleetbase.test');
});

test('getDefaultMailFromAddress falls back to the provided default when the env var is absent', function () {
    putenv('MAIL_FROM_ADDRESS');
    putenv('CONSOLE_HOST');

    expect(Utils::getDefaultMailFromAddress('fallback@fleetbase.test'))->toBe('fallback@fleetbase.test');
});
