<?php

/**
 * Verifies the ScopedToCompanyContext trait is declared in each target model
 * that exists on main. Uses source-file inspection because this test runs in
 * core-api's standalone container where FleetOps/Ledger classes are not
 * autoloadable.
 *
 * Run with:
 *   docker run --rm \
 *     -v ~/fleetbase-project/core-api:/app \
 *     -v ~/fleetbase-project/fleetops:/fleetops \
 *     -v ~/fleetbase-project/ledger:/ledger \
 *     -w /app fleetbase/fleetbase-api:latest \
 *     ./vendor/bin/pest tests/Feature/MultiTenant/ModelsHaveScopedTraitTest.php
 *
 * Follow-up: Shipment, RoutingGuide, RateContract will be added to this list
 * after feat/parcelpath-phase3 merges to main.
 */

$targets = [
    '/fleetops/server/src/Models/Order.php',
    '/ledger/server/src/Models/CarrierInvoice.php',
    '/ledger/server/src/Models/ServiceAgreement.php',
    '/ledger/server/src/Models/PayFile.php',
];

foreach ($targets as $path) {
    test("{$path} uses ScopedToCompanyContext trait", function () use ($path) {
        expect(is_readable($path))->toBeTrue(
            "Expected {$path} to be mounted into the test container. " .
            "Run this test with the fleetops and ledger volume mounts."
        );

        $source = file_get_contents($path);

        // 1. The import statement exists.
        expect($source)->toContain('use Fleetbase\Models\Concerns\ScopedToCompanyContext;');

        // 2. The trait is actually used inside the class body.
        expect($source)->toMatch('/class\s+\w+\s+extends[^{]*\{[\s\S]*use\s+[\w\\\\,\s]*ScopedToCompanyContext/');
    });
}
