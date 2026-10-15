<?php

use Fleetbase\Models\Setting;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Fleetbase\Support\SocketCluster\SocketSettings;
use Fleetbase\Support\SocketCluster\SocketToken;
use Fleetbase\Tests\Fixtures\Support\SocketAuthFixtures;
use Fleetbase\Tests\Fixtures\Support\SocketSettingsFixtures;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function socket_settings_env(): array
{
    return [
        'broadcasting.connections.socketcluster.auth_enabled'   => false,
        'broadcasting.connections.socketcluster.auth_mode'      => ' LOG ',
        'broadcasting.connections.socketcluster.token_ttl'      => 600,
        'broadcasting.connections.socketcluster.socket_options' => '{"origins":"https://console.example.test:*"}',
        'broadcasting.connections.socketcluster.options'        => [
            'secure'  => false,
            'host'    => 'socket.test',
            'port'    => 8000,
            'path'    => '/socketcluster/',
            'query'   => [],
            'headers' => ['Origin' => 'https://console.example.test'],
        ],
    ];
}

function socket_settings_client_headers(SocketClusterService $service): array
{
    return (fn () => $this->options)->call($service->getClient())['headers'] ?? [];
}

afterEach(function () {
    SocketSettingsFixtures::reset();
});

test('effective socket settings come from the environment until an administrator stores them', function () {
    SocketSettingsFixtures::setUp(SocketAuthFixtures::KEY, socket_settings_env());

    expect(SocketSettings::effective())->toBe([
        'enabled'          => false,
        'mode'             => 'log',
        'token_ttl'        => 600,
        'origins'          => 'https://console.example.test:*',
        'publisher_origin' => 'https://console.example.test',
        'key_configured'   => true,
        'sources'          => [
            'enabled'          => 'env',
            'mode'             => 'env',
            'token_ttl'        => 'env',
            'origins'          => 'env',
            'publisher_origin' => 'env',
        ],
    ])->and(SocketSettings::updatedAt())->toBeNull();

    $change = SocketSettings::store([
        'enabled'          => true,
        'mode'             => 'enforce',
        'token_ttl'        => '120',
        'origins'          => ' https://ops.example.test:* http://localhost:* ',
        'publisher_origin' => 'https://ops.example.test/',
    ]);

    expect(SocketSettings::effective())->toBe([
        'enabled'          => true,
        'mode'             => 'enforce',
        'token_ttl'        => 120,
        'origins'          => 'https://ops.example.test:* http://localhost:*',
        'publisher_origin' => 'https://ops.example.test',
        'key_configured'   => true,
        'sources'          => [
            'enabled'          => 'setting',
            'mode'             => 'setting',
            'token_ttl'        => 'setting',
            'origins'          => 'setting',
            'publisher_origin' => 'setting',
        ],
    ])
        ->and($change['before'])->toBe(['enabled' => null, 'mode' => null, 'token_ttl' => null, 'origins' => null, 'publisher_origin' => null])
        ->and($change['after']['token_ttl'])->toBe(120)
        ->and(Setting::where('key', 'system.socket.mode')->value('value'))->toBe('enforce')
        ->and(SocketSettings::updatedAt())->toBe(Carbon::createFromTimestampUTC(SocketAuthFixtures::NOW)->toIso8601ZuluString());

    // A null clears one field back to the environment and leaves the rest as they are.
    $cleared = SocketSettings::store(['mode' => null, 'token_ttl' => null]);
    $after   = SocketSettings::effective();

    expect($cleared['after'])->toBe(['enabled' => true, 'mode' => null, 'token_ttl' => null, 'origins' => 'https://ops.example.test:* http://localhost:*', 'publisher_origin' => 'https://ops.example.test'])
        ->and($after['mode'])->toBe('log')
        ->and($after['token_ttl'])->toBe(600)
        ->and($after['enabled'])->toBeTrue()
        ->and($after['sources'])->toBe(['enabled' => 'setting', 'mode' => 'env', 'token_ttl' => 'env', 'origins' => 'setting', 'publisher_origin' => 'setting'])
        ->and(Setting::where('key', 'system.socket.mode')->exists())->toBeFalse();
});

test('environment fallbacks tolerate blank, invalid and array forms', function () {
    SocketSettingsFixtures::setUp(null, [
        'broadcasting.connections.socketcluster.auth_enabled'   => 'true',
        'broadcasting.connections.socketcluster.auth_mode'      => 'strict',
        'broadcasting.connections.socketcluster.token_ttl'      => 0,
        'broadcasting.connections.socketcluster.socket_options' => 'not json',
    ]);

    expect(SocketSettings::effective())->toMatchArray([
        'enabled'          => true,
        'mode'             => null,
        'token_ttl'        => SocketToken::DEFAULT_TTL,
        'origins'          => null,
        'publisher_origin' => null,
        'key_configured'   => false,
    ]);

    config(['broadcasting.connections.socketcluster.socket_options' => ['origins' => 'http://localhost:*']]);

    expect(SocketSettings::defaults()['origins'])->toBe('http://localhost:*');

    config(['broadcasting.connections.socketcluster.socket_options' => '{"origins":"   "}']);

    expect(SocketSettings::defaults()['origins'])->toBeNull();
});

test('stored values that are no longer valid fall back to the environment', function () {
    SocketSettingsFixtures::setUp(SocketAuthFixtures::KEY, socket_settings_env());

    Setting::configure('system.socket.enabled', 'perhaps');
    Setting::configure('system.socket.mode', 'bogus');
    Setting::configure('system.socket.token_ttl', 10);
    Setting::configure('system.socket.origins', '   ');
    Setting::configure('system.socket.publisher_origin', ['not' => 'a string']);

    expect(SocketSettings::effective()['sources'])->toBe([
        'enabled'          => 'env',
        'mode'             => 'env',
        'token_ttl'        => 'env',
        'origins'          => 'env',
        'publisher_origin' => 'env',
    ])
        ->and(SocketSettings::normalize(['token_ttl' => 4000, 'enabled' => 'false', 'mode' => ' Enforce ']))->toBe([
            'enabled'          => false,
            'mode'             => 'enforce',
            'token_ttl'        => null,
            'origins'          => null,
            'publisher_origin' => null,
        ]);
});

test('stored socket settings are cached and the cache is busted on save', function () {
    SocketSettingsFixtures::setUp(SocketAuthFixtures::KEY, socket_settings_env());

    expect(SocketSettings::effective()['mode'])->toBe('log')
        ->and(Cache::get(SocketSettings::CACHE_KEY))->toBe([]);

    // Written behind the resolver's back: the cached read does not see it yet.
    Setting::configure('system.socket.mode', 'off');

    expect(SocketSettings::effective()['mode'])->toBe('log');

    SocketSettings::store(['token_ttl' => 300]);

    expect(SocketSettings::effective()['mode'])->toBe('off')
        ->and(SocketSettings::effective()['token_ttl'])->toBe(300)
        ->and(Cache::get(SocketSettings::CACHE_KEY))->toHaveKeys(['system.socket.mode', 'system.socket.token_ttl', 'system.socket.updated_at'])
        ->and(SocketSettings::CACHE_TTL)->toBe(60);
});

test('only the documented fields can be stored, never the auth key', function () {
    SocketSettingsFixtures::setUp();

    SocketSettings::store(['auth_key' => SocketAuthFixtures::OTHER_KEY, 'key' => SocketAuthFixtures::OTHER_KEY, 'mode' => 'log']);

    expect(Setting::query()->orderBy('key')->pluck('key')->all())->toBe(['system.socket.mode', 'system.socket.updated_at'])
        ->and(json_encode(SocketSettings::effective()))->not->toContain(SocketAuthFixtures::KEY)
        ->and(json_encode(SocketSettings::effective()))->not->toContain(SocketAuthFixtures::OTHER_KEY)
        ->and(SocketToken::key())->toBe(SocketAuthFixtures::KEY);
});

test('an unreachable settings table falls back to the environment silently', function () {
    $capsule = SocketSettingsFixtures::setUp(SocketAuthFixtures::KEY, socket_settings_env());
    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('settings');

    expect(SocketSettings::stored())->toBe([])
        ->and(SocketSettings::effective()['sources']['mode'])->toBe('env')
        ->and(SocketSettings::updatedAt())->toBeNull();
});

test('socket token switch, ttl and publisher origin follow the effective settings', function () {
    SocketSettingsFixtures::setUp(SocketAuthFixtures::KEY, socket_settings_env());

    expect(SocketToken::switchedOn())->toBeFalse()
        ->and(SocketToken::enabled())->toBeFalse()
        ->and(SocketClusterService::publishesOverHttp())->toBeFalse()
        ->and(SocketToken::defaultTtl('user'))->toBe(600)
        ->and(socket_settings_client_headers(new SocketClusterService()))->toBe(['Origin' => 'https://console.example.test']);

    SocketSettings::store(['enabled' => true, 'token_ttl' => 120, 'publisher_origin' => 'https://ops.example.test']);

    expect(SocketToken::switchedOn())->toBeTrue()
        ->and(SocketToken::enabled())->toBeTrue()
        ->and(SocketClusterService::publishesOverHttp())->toBeTrue()
        ->and(SocketToken::defaultTtl('api'))->toBe(120)
        ->and(SocketToken::defaultTtl('tracking'))->toBe(SocketToken::TRACKING_TTL)
        ->and(SocketToken::issue(new SocketPrincipal(kind: 'api', sub: 'cred-a'))['expires_in'])->toBe(120)
        ->and(socket_settings_client_headers(new SocketClusterService()))->toBe(['Origin' => 'https://ops.example.test'])
        // Options passed in still take precedence over the configured origin.
        ->and(socket_settings_client_headers(new SocketClusterService(['headers' => ['Origin' => 'https://explicit.example.test']])))->toBe(['Origin' => 'https://explicit.example.test']);

    // The stored switch also turns socket auth off while the environment has it on.
    config(['broadcasting.connections.socketcluster.auth_enabled' => true]);
    SocketSettings::store(['enabled' => false]);

    expect(SocketToken::enabled())->toBeFalse();

    // Without any origin at all the handshake sends none.
    config(['broadcasting.connections.socketcluster.options.headers' => []]);
    SocketSettings::store(['publisher_origin' => null]);

    expect(socket_settings_client_headers(new SocketClusterService()))->toBe([]);
});

test('socket server stats are fetched with a signed request and a two second timeout', function () {
    SocketSettingsFixtures::setUp();
    Http::fake(['*' => Http::response(['connections' => 3, 'counters' => ['denied' => 1]], 200)]);

    $publishKey = hash_hmac('sha256', 'fleetbase-socket:publish', SocketAuthFixtures::KEY);

    expect(SocketClusterService::stats())->toBe(['status' => ['connections' => 3, 'counters' => ['denied' => 1]], 'error' => null])
        ->and(SocketClusterService::internalUrl('/stats'))->toBe('http://socket.test:8001/stats');

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) use ($publishKey) {
        $timestamp = $request->header('X-Fleetbase-Timestamp')[0];

        return $request->url() === 'http://socket.test:8001/stats'
            && $request->method() === 'GET'
            && $timestamp === (string) SocketAuthFixtures::NOW
            && $request->header('X-Fleetbase-Signature')[0] === hash_hmac('sha256', $timestamp . '.', $publishKey);
    });
});

test('socket server stats report failures, timeouts and a missing key as a short reason', function () {
    SocketSettingsFixtures::setUp();

    $statsFor = function ($fake) {
        Http::swap(new HttpFactory());
        Http::fake($fake);

        return SocketClusterService::stats();
    };

    expect($statsFor(['*' => Http::response('unavailable', 503)]))->toBe(['status' => null, 'error' => 'The socket server answered with HTTP status 503.'])
        ->and($statsFor(['*' => Http::response('not json', 200)]))->toBe(['status' => null, 'error' => 'The socket server returned an invalid status response.'])
        ->and($statsFor(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 2000 milliseconds');
        }))->toBe(['status' => null, 'error' => 'The socket server could not be reached or did not answer within 2 seconds.'])
        ->and($statsFor(function () {
            throw new RuntimeException('unexpected');
        }))->toBe(['status' => null, 'error' => 'The socket server status request failed.']);

    config(['broadcasting.connections.socketcluster.auth_key' => null]);
    Http::swap(new HttpFactory());
    Http::fake();

    expect(SocketClusterService::stats())->toBe(['status' => null, 'error' => 'SOCKETCLUSTER_AUTH_KEY is not configured.']);

    Http::assertNothingSent();
});
