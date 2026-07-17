<?php

use Fleetbase\Support\Utils;

test('utils formats urls headers strings and dates', function () {
    bind_test_container([
        'filesystems.disks.s3.bucket' => 'fleetbase-media',
        'filesystems.disks.s3.region' => 'ap-southeast-1',
        'fleetbase.console.host'      => 'fleetbase.test',
        'fleetbase.console.subdomain' => 'console',
        'fleetbase.console.secure'    => true,
        'fleetbase.console.path'      => '/srv/fleetbase/console/',
    ]);

    expect(Utils::consoleUrl('settings', ['tab' => 'billing']))->toBe('https://console.fleetbase.test/settings?tab=billing')
        ->and(Utils::consolePath('dist/assets'))->toBe('/srv/fleetbase/console/dist/assets')
        ->and(Utils::getDomainFromUrl('https://api.fleetbase.test:8443/v1/orders', true))->toBe('api.fleetbase.test:8443')
        ->and(Utils::getDomainFromUrl('api.fleetbase.test:8000', true))->toBe('api.fleetbase.test:8000')
        ->and(Utils::fromS3('avatars/user.png'))->toBe('https://fleetbase-media.s3-ap-southeast-1.amazonaws.com/avatars/user.png')
        ->and(Utils::assetFromFleetbase('icons/logo.png'))->toBe('https://flb-assets.s3-ap-southeast-1.amazonaws.com/icons/logo.png')
        ->and(Utils::keyHeaders(['Content-Type: application/json']))->toBe(['Content-Type' => ' application/json'])
        ->and(Utils::unkeyHeaders(['Accept' => 'application/json', 'X-Test']))->toBe(['Accept: application/json', 'X-Test'])
        ->and(Utils::stringMatches('order_123', '/^order_/'))->toBeTrue()
        ->and(Utils::stringExtract('Order #123', '/\d+/'))->toBe('123')
        ->and(Utils::toMySqlDatetime('July 17, 2026 12:34:56 (UTC)'))->toBe('2026-07-17 12:34:56')
        ->and(Utils::isDate('2026-07-17'))->toBeTrue()
        ->and(Utils::isDate('not-a-date'))->toBeFalse();
});

test('utils handles boolean json inflection and sql helpers', function () {
    expect(Utils::createObject(['active' => true]))->toEqual((object) ['active' => true])
        ->and(Utils::castBoolean('truthy'))->toBeTrue()
        ->and(Utils::castBoolean('off'))->toBeFalse()
        ->and(Utils::castBoolean(null))->toBeFalse()
        ->and(Utils::isBooleanValue('true'))->toBeTrue()
        ->and(Utils::isBooleanValue('yes'))->toBeFalse()
        ->and(Utils::isTrue('1'))->toBeTrue()
        ->and(Utils::isFalse('0'))->toBeTrue()
        ->and(Utils::isJson('{"ok":true}'))->toBeTrue()
        ->and(Utils::isJson(['not' => 'json']))->toBeFalse()
        ->and(Utils::sqlExceptionString('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (Connection: mysql)'))->toBe('Integrity constraint violation: 1062 Duplicate entry')
        ->and(Utils::pluralize('company'))->toBe('companies')
        ->and(Utils::singularize('companies'))->toBe('company')
        ->and(Utils::tableize('CompanyUser'))->toBe('company_user')
        ->and(Utils::lowercase('FleetBase'))->toBe('fleetbase')
        ->and(Utils::humanize('api_uuid'))->toBe('API UUID')
        ->and(Utils::interpolateQuery('select * from users where id = ? and email = ?', [1, 'ron@example.com']))->toBe('select * from users where id = 1 and email = ron@example.com');
});
