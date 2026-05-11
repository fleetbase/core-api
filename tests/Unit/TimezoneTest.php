<?php

use Fleetbase\Support\Timezone;

test('first valid timezone preserves request precedence order', function () {
    $timezone = Timezone::firstValid([
        'America/New_York',
        'Asia/Singapore',
    ]);

    expect($timezone)->toBe('America/New_York');
});

test('first valid timezone accepts nested whois timezone candidates', function () {
    $timezone = Timezone::firstValid([
        null,
        'Europe/London',
        'Asia/Singapore',
    ]);

    expect($timezone)->toBe('Europe/London');
});

test('first valid timezone ignores invalid values', function () {
    $timezone = Timezone::firstValid([
        'Not/A_Timezone',
        'Also/Invalid',
    ]);

    expect($timezone)->toBeNull();
});

test('first valid timezone does not default to application timezone', function () {
    $timezone = Timezone::firstValid([
        null,
        '',
    ]);

    expect($timezone)->toBeNull();
});
