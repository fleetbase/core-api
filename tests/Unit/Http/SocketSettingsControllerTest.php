<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Controllers\SocketAuthController;
use Fleetbase\Http\Middleware\VerifySocketSignature;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\Support\SocketCluster\SocketSettings;
use Fleetbase\Support\SocketCluster\SocketSignature;
use Fleetbase\Tests\Fixtures\Support\SocketAuthFixtures;
use Fleetbase\Tests\Fixtures\Support\SocketSettingsFixtures;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function socket_settings_admin_request(string $method = 'GET', array $input = []): AdminRequest
{
    $request = AdminRequest::create('/int/v1/settings/socket', $method, $input);
    $request->setUserResolver(fn () => SocketAuthFixtures::user(['uuid' => 'admin-1', 'type' => 'admin']));

    return $request;
}

function socket_settings_stats_fake(): void
{
    Http::swap(new HttpFactory());
    Http::fake(['*' => Http::response(['connections' => 2, 'config' => ['source' => 'api']], 200)]);
}

/**
 * Every value a response could leak the auth key through: the key and each derived key.
 */
function socket_settings_secrets(): array
{
    return array_merge([SocketAuthFixtures::KEY], array_map(
        fn (string $purpose) => hash_hmac('sha256', 'fleetbase-socket:' . $purpose, SocketAuthFixtures::KEY),
        [SocketSignature::PUBLISH, SocketSignature::AUTHORIZE, SocketSignature::TRACKING]
    ));
}

function socket_settings_expect_no_secrets(JsonResponse $response): void
{
    foreach (socket_settings_secrets() as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
}

function socket_settings_config_request(?string $timestamp, ?string $signature): Request
{
    return Request::create('/int/v1/socket/config', 'GET', [], [], [], array_filter([
        'HTTP_X_FLEETBASE_TIMESTAMP' => $timestamp,
        'HTTP_X_FLEETBASE_SIGNATURE' => $signature,
    ]));
}

afterEach(function () {
    SocketSettingsFixtures::reset();
});

test('the socket settings endpoints are for system administrators only', function () {
    foreach (['getSocketSettings', 'saveSocketSettings'] as $method) {
        $parameter = (new ReflectionMethod(SettingController::class, $method))->getParameters()[0];

        expect($parameter->getType()->getName())->toBe(AdminRequest::class);
    }

    $admin = AdminRequest::create('/int/v1/settings/socket');
    $admin->setUserResolver(fn () => SocketAuthFixtures::user(['type' => 'admin']));
    $user = AdminRequest::create('/int/v1/settings/socket');
    $user->setUserResolver(fn () => SocketAuthFixtures::user(['type' => 'user']));

    expect($admin->authorize())->toBeTrue()
        ->and($user->authorize())->toBeFalse()
        ->and((new AdminRequest())->authorize())->toBeFalse();
});

test('get socket settings returns the effective settings and the socket server status', function () {
    SocketSettingsFixtures::setUp();
    socket_settings_stats_fake();

    $response = (new SettingController())->getSocketSettings(socket_settings_admin_request());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'settings' => [
                'enabled'          => true,
                'mode'             => null,
                'token_ttl'        => 900,
                'origins'          => null,
                'publisher_origin' => null,
                'key_configured'   => true,
                'sources'          => [
                    'enabled'          => 'env',
                    'mode'             => 'env',
                    'token_ttl'        => 'env',
                    'origins'          => 'env',
                    'publisher_origin' => 'env',
                ],
            ],
            'status'       => ['connections' => 2, 'config' => ['source' => 'api']],
            'status_error' => null,
        ]);

    socket_settings_expect_no_secrets($response);
});

test('get socket settings reports an unreachable socket server and a missing key', function () {
    SocketSettingsFixtures::setUp();
    Http::fake(['*' => Http::response('bad gateway', 502)]);

    $unreachable = (new SettingController())->getSocketSettings(socket_settings_admin_request())->getData(true);

    config(['broadcasting.connections.socketcluster.auth_key' => null]);

    $keyless = (new SettingController())->getSocketSettings(socket_settings_admin_request())->getData(true);

    expect($unreachable['status'])->toBeNull()
        ->and($unreachable['status_error'])->toBe('The socket server answered with HTTP status 502.')
        ->and($keyless['settings']['key_configured'])->toBeFalse()
        ->and($keyless['status'])->toBeNull()
        ->and($keyless['status_error'])->toBe('SOCKETCLUSTER_AUTH_KEY is not configured.');
});

test('save socket settings stores a subset, clears nulls, logs the change and answers like get', function () {
    SocketSettingsFixtures::setUp(SocketAuthFixtures::KEY, ['broadcasting.connections.socketcluster.auth_enabled' => false]);
    socket_settings_stats_fake();
    SocketSettings::store(['origins' => 'http://localhost:*']);

    $response = (new SettingController())->saveSocketSettings(socket_settings_admin_request('POST', [
        'enabled'          => true,
        'mode'             => 'log',
        'token_ttl'        => 300,
        'origins'          => null,
        'publisher_origin' => 'https://console.example.test/',
    ]));
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($payload))->toBe(['settings', 'status', 'status_error'])
        ->and($payload['settings']['enabled'])->toBeTrue()
        ->and($payload['settings']['mode'])->toBe('log')
        ->and($payload['settings']['token_ttl'])->toBe(300)
        ->and($payload['settings']['origins'])->toBeNull()
        ->and($payload['settings']['publisher_origin'])->toBe('https://console.example.test')
        ->and($payload['settings']['sources'])->toBe([
            'enabled'          => 'setting',
            'mode'             => 'setting',
            'token_ttl'        => 'setting',
            'origins'          => 'env',
            'publisher_origin' => 'setting',
        ])
        ->and($payload['status'])->toBe(['connections' => 2, 'config' => ['source' => 'api']])
        ->and(Setting::where('key', 'system.socket.origins')->exists())->toBeFalse()
        ->and(TestActivityLogger::$logged)->toBe([[
            'log'         => 'settings',
            'description' => 'Socket settings updated',
            'causer'      => 'admin-1',
            'event'       => 'socket_settings_updated',
            'properties'  => [
                'fields' => ['enabled', 'mode', 'token_ttl', 'origins', 'publisher_origin'],
                'old'    => ['enabled' => null, 'mode' => null, 'token_ttl' => null, 'origins' => 'http://localhost:*', 'publisher_origin' => null],
                'new'    => ['enabled' => true, 'mode' => 'log', 'token_ttl' => 300, 'origins' => null, 'publisher_origin' => 'https://console.example.test'],
            ],
        ]]);

    socket_settings_expect_no_secrets($response);

    // Fields left out of a save keep their stored values.
    $partial = (new SettingController())->saveSocketSettings(socket_settings_admin_request('POST', ['enabled' => null]))->getData(true);

    expect($partial['settings']['enabled'])->toBeFalse()
        ->and($partial['settings']['sources']['enabled'])->toBe('env')
        ->and($partial['settings']['mode'])->toBe('log')
        ->and(TestActivityLogger::$logged[1]['properties']['fields'])->toBe(['enabled']);
});

test('socket authentication cannot be switched on without a configured key', function () {
    SocketSettingsFixtures::setUp(null);
    socket_settings_stats_fake();

    $refused = (new SettingController())->saveSocketSettings(socket_settings_admin_request('POST', ['enabled' => true, 'mode' => 'enforce']));

    expect($refused->getStatusCode())->toBe(422)
        ->and($refused->getData(true)['code'])->toBe('key_missing')
        ->and(Setting::query()->count())->toBe(0)
        ->and(TestActivityLogger::$logged)->toBe([]);

    $switchedOff = (new SettingController())->saveSocketSettings(socket_settings_admin_request('POST', ['enabled' => false, 'mode' => 'off']));

    expect($switchedOff->getStatusCode())->toBe(200)
        ->and($switchedOff->getData(true)['settings']['enabled'])->toBeFalse()
        ->and($switchedOff->getData(true)['settings']['mode'])->toBe('off');
});

test('save socket settings rejects invalid values', function (array $input) {
    SocketSettingsFixtures::setUp();

    expect(fn () => (new SettingController())->saveSocketSettings(socket_settings_admin_request('POST', $input)))
        ->toThrow(ValidationException::class);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'non boolean switch'       => [['enabled' => 'maybe']],
    'unknown mode'             => [['mode' => 'strict']],
    'ttl below the minimum'    => [['token_ttl' => 59]],
    'ttl above the maximum'    => [['token_ttl' => 3601]],
    'non numeric ttl'          => [['token_ttl' => 'soon']],
    'origins with a tab'       => [['origins' => "https://a.example.test:*\thttps://b.example.test:*"]],
    'origins as a list'        => [['origins' => ['https://a.example.test:*']]],
    'origin without a scheme'  => [['publisher_origin' => 'console.example.test']],
    'origin with a path'       => [['publisher_origin' => 'https://console.example.test/app']],
    'origin with another type' => [['publisher_origin' => 'ftp://console.example.test']],
]);

test('the signed config endpoint answers the effective settings while the switch is off', function () {
    SocketSettingsFixtures::setUp(SocketAuthFixtures::KEY, ['broadcasting.connections.socketcluster.auth_enabled' => false]);

    $middleware = new VerifySocketSignature();
    $controller = new SocketAuthController();
    $now        = (string) SocketAuthFixtures::NOW;
    $stale      = (string) (SocketAuthFixtures::NOW - 120);
    $next       = fn (Request $request) => $controller->config($request);
    $send       = fn (?string $timestamp, ?string $signature) => $middleware->handle(socket_settings_config_request($timestamp, $signature), $next, VerifySocketSignature::KEY_ONLY);

    $before = $send($now, SocketSignature::sign(SocketSignature::AUTHORIZE, $now, ''));

    expect($before->getStatusCode())->toBe(200)
        ->and($before->getData(true))->toBe(['enabled' => false, 'mode' => null, 'origins' => null, 'updated_at' => null]);

    SocketSettings::store(['enabled' => true, 'mode' => 'log', 'origins' => 'https://console.example.test:*', 'token_ttl' => 300]);

    $after = $send($now, SocketSignature::sign(SocketSignature::AUTHORIZE, $now, ''));

    expect($after->getData(true))->toBe([
        'enabled'    => true,
        'mode'       => 'log',
        'origins'    => 'https://console.example.test:*',
        'updated_at' => Carbon::createFromTimestampUTC(SocketAuthFixtures::NOW)->toIso8601ZuluString(),
    ]);

    socket_settings_expect_no_secrets($after);

    SocketSettings::store(['enabled' => false]);

    $rejected = [
        $send($stale, SocketSignature::sign(SocketSignature::AUTHORIZE, $stale, '')),
        $send($now, SocketSignature::sign(SocketSignature::PUBLISH, $now, '')),
        $send($now, str_repeat('0', 64)),
        $send(null, null),
    ];

    foreach ($rejected as $response) {
        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toBe(['error' => 'invalid_signature']);
    }

    // The authorize endpoint's default still needs the switch on.
    $authorize = $middleware->handle(socket_settings_config_request($now, SocketSignature::sign(SocketSignature::AUTHORIZE, $now, '')), $next);

    expect($authorize->getStatusCode())->toBe(404)
        ->and(SocketSignature::verify(SocketSignature::AUTHORIZE, $now, SocketSignature::sign(SocketSignature::AUTHORIZE, $now, ''), ''))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::AUTHORIZE, $now, SocketSignature::sign(SocketSignature::AUTHORIZE, $now, ''), '', false))->toBeTrue();

    // Without a key there is nothing to verify the request with.
    config(['broadcasting.connections.socketcluster.auth_key' => null]);

    expect($send($now, str_repeat('0', 64))->getStatusCode())->toBe(404)
        ->and(SocketSignature::verify(SocketSignature::AUTHORIZE, $now, str_repeat('0', 64), '', false))->toBeFalse();
});
