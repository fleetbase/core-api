<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\AdminRequest;
use Illuminate\Support\Facades\Facade;

function setting_controller_external_probe_fixtures(array $config = []): void
{
    bind_test_container(array_merge([
        'twilio.twilio.connections.twilio'        => [
            'sid'   => 'existing-sid',
            'token' => 'existing-token',
            'from'  => '+15555550100',
        ],
        'broadcasting.connections.socketcluster.options' => [
            'secure'  => false,
            'host'    => '127.0.0.1',
            'port'    => 9,
            'path'    => '',
            'query'   => [],
            'timeout' => 1,
        ],
    ], $config));

    Facade::clearResolvedInstances();
}

function setting_controller_external_probe_request(array $input = []): AdminRequest
{
    return AdminRequest::create('/int/v1/settings/external-probe', 'POST', $input);
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('test twilio config requires a phone number before mutating runtime config', function () {
    setting_controller_external_probe_fixtures();

    $response = (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'override-sid',
        'token' => 'override-token',
        'from'  => '+15555550999',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'No test phone number provided!',
        ])
        ->and(config('twilio.twilio.connections.twilio'))->toBe([
            'sid'   => 'existing-sid',
            'token' => 'existing-token',
            'from'  => '+15555550100',
        ]);
});

test('test twilio config applies request credentials before returning provider errors', function () {
    setting_controller_external_probe_fixtures();

    $response = (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'override-sid',
        'token' => 'override-token',
        'from'  => '+15555550999',
        'phone' => '+15555550123',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('error')
        ->and($response->getData(true)['message'])->toBe('Target class [twilio] does not exist.')
        ->and(config('twilio.twilio.connections.twilio'))->toBe([
            'sid'   => 'override-sid',
            'token' => 'override-token',
            'from'  => '+15555550999',
        ]);
});

test('test socketcluster returns stable json when the configured socket cannot send', function () {
    setting_controller_external_probe_fixtures();

    $response = (new SettingController())->testSocketcluster(setting_controller_external_probe_request([
        'channel' => 'settings-probe',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'   => 'error',
            'message'  => 'Socket broadcasted message successfully.',
            'channel'  => 'settings-probe',
            'response' => null,
        ]);
});
