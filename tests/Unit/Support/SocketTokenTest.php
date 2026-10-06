<?php

use Fleetbase\Models\ApiCredential;
use Fleetbase\Support\SocketCluster\ChannelDecision;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Fleetbase\Support\SocketCluster\SocketSignature;
use Fleetbase\Support\SocketCluster\SocketToken;
use Fleetbase\Tests\Fixtures\Support\SocketAuthFixtures;
use Illuminate\Support\Carbon;

/**
 * Stands in for FleetOps' TrackingScope value object, which core-api does not depend on.
 */
class SocketTokenTrackingScope
{
    public function __construct(
        public string $order_uuid,
        public string $customer_type,
        public string $customer_uuid,
        public ?string $company_uuid = null,
    ) {
    }
}

afterEach(function () {
    SocketAuthFixtures::reset();
});

test('socket tokens are disabled without a configured key of at least 32 bytes', function () {
    SocketAuthFixtures::container(null);

    expect(SocketToken::key())->toBeNull()
        ->and(SocketToken::enabled())->toBeFalse();

    config(['broadcasting.connections.socketcluster.auth_key' => 'too-short-for-hs256']);

    expect(SocketToken::key())->toBeNull()
        ->and(SocketToken::enabled())->toBeFalse();

    config(['broadcasting.connections.socketcluster.auth_key' => SocketAuthFixtures::KEY]);

    expect(SocketToken::key())->toBe(SocketAuthFixtures::KEY)
        ->and(SocketToken::enabled())->toBeTrue();
});

test('issuing a socket token requires the feature to be configured', function () {
    SocketAuthFixtures::container(null);

    SocketToken::issue(SocketPrincipal::system());
})->throws(RuntimeException::class, 'Socket authentication is not configured.');

test('issued tokens carry the contract header and claims and verify back to the principal', function () {
    SocketAuthFixtures::container();

    $minted = SocketToken::issue(new SocketPrincipal(
        kind: 'user',
        sub: 'user-a1',
        cid: 'company-a',
        cpid: 'company_aaa',
        ids: ['user-a1', 'user_a1'],
        adm: true
    ));
    $payload  = SocketAuthFixtures::payload($minted['token']);
    $verified = SocketToken::verify($minted['token']);

    expect(SocketAuthFixtures::header($minted['token']))->toEqual(['alg' => 'HS256', 'typ' => 'JWT'])
        ->and($minted['expires_in'])->toBe(900)
        ->and($minted['expires_at'])->toBe((new DateTimeImmutable('@' . (SocketAuthFixtures::NOW + 900)))->format(DATE_ATOM))
        ->and($payload)->toMatchArray([
            'iss'  => 'fleetbase-api',
            'aud'  => 'fleetbase-socket',
            'iat'  => SocketAuthFixtures::NOW,
            'nbf'  => SocketAuthFixtures::NOW,
            'exp'  => SocketAuthFixtures::NOW + 900,
            'sub'  => 'user-a1',
            'kind' => 'user',
            'cid'  => 'company-a',
            'cpid' => 'company_aaa',
            'env'  => 'live',
            'ids'  => ['user-a1', 'user_a1'],
            'adm'  => true,
        ])
        ->and($payload)->not->toHaveKey('scp')
        ->and($payload)->not->toHaveKey('sid')
        ->and($payload['jti'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($verified)->toBeInstanceOf(SocketPrincipal::class)
        ->and($verified->kind)->toBe('user')
        ->and($verified->sub)->toBe('user-a1')
        ->and($verified->cid)->toBe('company-a')
        ->and($verified->cpid)->toBe('company_aaa')
        ->and($verified->ids)->toBe(['user-a1', 'user_a1'])
        ->and($verified->adm)->toBeTrue()
        ->and($verified->scp)->toBeNull()
        ->and($verified->jti)->toBe($payload['jti'])
        ->and($verified->exp)->toBe(SocketAuthFixtures::NOW + 900);
});

test('token lifetimes follow the configured ttl per kind and stay within the socket server limit', function () {
    SocketAuthFixtures::container();

    expect(SocketToken::defaultTtl('user'))->toBe(900)
        ->and(SocketToken::defaultTtl('tracking'))->toBe(1800)
        ->and(SocketToken::defaultTtl('system'))->toBe(300)
        ->and(SocketToken::issue(SocketPrincipal::system())['expires_in'])->toBe(300)
        ->and(SocketToken::issue(SocketPrincipal::system(), 99999)['expires_in'])->toBe(3600)
        ->and(SocketToken::issue(SocketPrincipal::system(), 0)['expires_in'])->toBe(1);

    config(['broadcasting.connections.socketcluster.token_ttl' => 120]);

    expect(SocketToken::defaultTtl('api'))->toBe(120);

    config(['broadcasting.connections.socketcluster.token_ttl' => 0]);

    expect(SocketToken::defaultTtl('driver'))->toBe(900);
});

test('verification rejects tokens that are not ours or no longer valid', function (array $header, array $claims, ?string $key) {
    SocketAuthFixtures::container();

    expect(SocketToken::verify(SocketAuthFixtures::jwt($header, $claims, $key)))->toBeNull();
})->with([
    'wrong key'          => [['alg' => 'HS256', 'typ' => 'JWT'], SocketAuthFixtures::claims(), SocketAuthFixtures::OTHER_KEY],
    'alg none'           => [['alg' => 'none', 'typ' => 'JWT'], SocketAuthFixtures::claims(), null],
    'asymmetric alg'     => [['alg' => 'RS256', 'typ' => 'JWT'], SocketAuthFixtures::claims(), SocketAuthFixtures::KEY],
    'missing audience'   => [['alg' => 'HS256', 'typ' => 'JWT'], array_diff_key(SocketAuthFixtures::claims(), ['aud' => true]), SocketAuthFixtures::KEY],
    'wrong audience'     => [['alg' => 'HS256', 'typ' => 'JWT'], SocketAuthFixtures::claims(['aud' => 'someone-else']), SocketAuthFixtures::KEY],
    'wrong issuer'       => [['alg' => 'HS256', 'typ' => 'JWT'], SocketAuthFixtures::claims(['iss' => 'someone-else']), SocketAuthFixtures::KEY],
    'missing expiry'     => [['alg' => 'HS256', 'typ' => 'JWT'], array_diff_key(SocketAuthFixtures::claims(), ['exp' => true]), SocketAuthFixtures::KEY],
    'expired'            => [['alg' => 'HS256', 'typ' => 'JWT'], SocketAuthFixtures::claims(['exp' => SocketAuthFixtures::NOW - 1]), SocketAuthFixtures::KEY],
    'not yet valid'      => [['alg' => 'HS256', 'typ' => 'JWT'], SocketAuthFixtures::claims(['nbf' => SocketAuthFixtures::NOW + 60]), SocketAuthFixtures::KEY],
    'unknown kind claim' => [['alg' => 'HS256', 'typ' => 'JWT'], SocketAuthFixtures::claims(['kind' => 'robot']), SocketAuthFixtures::KEY],
]);

test('verification accepts a well formed token and rejects garbage or a disabled feature', function () {
    SocketAuthFixtures::container();

    $token = SocketAuthFixtures::jwt(['alg' => 'HS256', 'typ' => 'JWT'], SocketAuthFixtures::claims(), SocketAuthFixtures::KEY);

    expect(SocketToken::verify($token))->toBeInstanceOf(SocketPrincipal::class)
        ->and(SocketToken::verify($token)->jti)->toBe('jti-handmade')
        ->and(SocketToken::verify(''))->toBeNull()
        ->and(SocketToken::verify('not-a-jwt'))->toBeNull();

    config(['broadcasting.connections.socketcluster.auth_key' => null]);

    expect(SocketToken::verify($token))->toBeNull();
});

test('verification rejects an issued token once it has expired', function () {
    SocketAuthFixtures::container();

    $token = SocketToken::issue(SocketPrincipal::system(), 60)['token'];

    expect(SocketToken::verify($token))->toBeInstanceOf(SocketPrincipal::class);

    Carbon::setTestNow(Carbon::createFromTimestampUTC(SocketAuthFixtures::NOW + 61));

    expect(SocketToken::verify($token))->toBeNull();
});

test('system tokens are short lived and carry no company', function () {
    SocketAuthFixtures::container();

    $token     = SocketToken::system();
    $principal = SocketToken::verify($token);

    expect($principal->kind)->toBe('system')
        ->and($principal->isSystem())->toBeTrue()
        ->and($principal->cid)->toBeNull()
        ->and($principal->exp)->toBe(SocketAuthFixtures::NOW + 300)
        ->and(SocketAuthFixtures::payload($token))->not->toHaveKey('cid')
        ->and(SocketAuthFixtures::payload($token))->not->toHaveKey('cpid');
});

test('base32 encoding follows rfc 4648 in lowercase without padding', function () {
    expect(SocketToken::base32('f'))->toBe('my')
        ->and(SocketToken::base32('foobar'))->toBe('mzxw6ytboi');
});

test('tracking ids are the truncated base32 hmac of the tracking scope under the tracking key', function () {
    SocketAuthFixtures::container();

    // Computed independently: base32(HMAC-SHA256(hex(HMAC-SHA256(KEY, "fleetbase-socket:tracking")), msg))[:26].
    expect(SocketToken::trackingId('order-a', 'contact', 'contact-1'))->toBe('r4pb2bnb4fi2ekuheuv2qonlpp')
        ->and(SocketToken::trackingId('order-a', 'contact', 'contact-2'))->not->toBe('r4pb2bnb4fi2ekuheuv2qonlpp')
        ->and(SocketToken::trackingId('order-a', 'vendor', 'contact-1'))->toMatch('/^[a-z2-7]{26}$/');
});

test('tracking tokens may only follow their own tracking channel', function () {
    SocketAuthFixtures::container();

    $minted    = SocketToken::forTracking(new SocketTokenTrackingScope('order-a', 'contact', 'contact-1', 'company-a'));
    $principal = SocketToken::verify($minted['token']);

    expect($minted['expires_in'])->toBe(1800)
        ->and($principal->kind)->toBe('tracking')
        ->and($principal->sub)->toBe('r4pb2bnb4fi2ekuheuv2qonlpp')
        ->and($principal->cid)->toBe('company-a')
        ->and($principal->scp)->toBe(['tracking.r4pb2bnb4fi2ekuheuv2qonlpp']);
});

test('tracking tokens take the company from the order when the scope does not carry it', function () {
    SocketAuthFixtures::database();

    $known   = SocketToken::verify(SocketToken::forTracking(new SocketTokenTrackingScope('order-a', 'contact', 'contact-1'))['token']);
    $unknown = SocketToken::verify(SocketToken::forTracking(new SocketTokenTrackingScope('order-missing', 'contact', 'contact-1'))['token']);

    expect($known->cid)->toBe('company-a')
        ->and($unknown->cid)->toBeNull()
        ->and($unknown->scp)->toHaveCount(1);
});

test('socket request signatures use per-purpose keys derived from the auth key', function () {
    SocketAuthFixtures::container();

    $publishKey = hash_hmac('sha256', 'fleetbase-socket:publish', SocketAuthFixtures::KEY);
    $timestamp  = (string) SocketAuthFixtures::NOW;
    $body       = '{"channels":["order.x"],"data":{}}';
    $signature  = hash_hmac('sha256', $timestamp . '.' . $body, $publishKey);
    $stale      = (string) (SocketAuthFixtures::NOW - 61);
    $edge       = (string) (SocketAuthFixtures::NOW - 60);
    $future     = (string) (SocketAuthFixtures::NOW + 61);

    expect(SocketSignature::deriveKey(SocketSignature::PUBLISH))->toBe($publishKey)
        ->and(SocketSignature::deriveKey(SocketSignature::AUTHORIZE))->toBe(hash_hmac('sha256', 'fleetbase-socket:authorize', SocketAuthFixtures::KEY))
        ->and(SocketSignature::headers(SocketSignature::PUBLISH, $body))->toBe([
            'X-Fleetbase-Timestamp' => $timestamp,
            'X-Fleetbase-Signature' => $signature,
        ])
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, $timestamp, $signature, $body))->toBeTrue()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, $timestamp, strtoupper($signature), $body))->toBeTrue()
        ->and(SocketSignature::verify(SocketSignature::AUTHORIZE, $timestamp, $signature, $body))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, $timestamp, $signature, $body . ' '))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, null, $signature, $body))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, 'yesterday', $signature, $body))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, $timestamp, null, $body))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, $timestamp, '', $body))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, $stale, SocketSignature::sign(SocketSignature::PUBLISH, $stale, $body), $body))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, $future, SocketSignature::sign(SocketSignature::PUBLISH, $future, $body), $body))->toBeFalse()
        ->and(SocketSignature::verify(SocketSignature::PUBLISH, $edge, SocketSignature::sign(SocketSignature::PUBLISH, $edge, $body), $body))->toBeTrue();

    config(['broadcasting.connections.socketcluster.auth_key' => null]);

    expect(SocketSignature::verify(SocketSignature::PUBLISH, $timestamp, $signature, $body))->toBeFalse();
});

test('deriving a signing key requires the feature to be configured', function () {
    SocketAuthFixtures::container(null);

    SocketSignature::deriveKey(SocketSignature::PUBLISH);
})->throws(RuntimeException::class, 'Socket authentication is not configured.');

test('socket principals reject unknown kinds, empty subjects and unknown environments', function (array $arguments) {
    new SocketPrincipal(...$arguments);
})->throws(InvalidArgumentException::class)->with([
    'unknown kind'        => [['kind' => 'robot', 'sub' => 'someone']],
    'empty subject'       => [['kind' => 'user', 'sub' => '']],
    'unknown environment' => [['kind' => 'user', 'sub' => 'someone', 'env' => 'staging']],
]);

test('principals rebuild from claims and serialize back without unset claims', function () {
    SocketAuthFixtures::container();

    $principal = SocketPrincipal::fromClaims([
        'kind' => 'customer',
        'sub'  => 'contact-1',
        'cid'  => 'company-a',
        'cpid' => '',
        'env'  => 'test',
        'ids'  => ['contact-1', '', 7, 'contact_1'],
        'adm'  => 0,
        'scp'  => 'storefront.store_1',
        'sid'  => 'store-1',
        'jti'  => 'jti-1',
        'exp'  => new DateTimeImmutable('@' . (SocketAuthFixtures::NOW + 30)),
    ]);
    $minimal = SocketPrincipal::fromClaims(['kind' => 'api', 'sub' => 'cred-a']);
    $changed = $principal->with(['env' => 'live', 'sid' => null]);

    expect($principal->cpid)->toBeNull()
        ->and($principal->ids)->toBe(['contact-1', 'contact_1'])
        ->and($principal->adm)->toBeFalse()
        ->and($principal->scp)->toBe(['storefront.store_1'])
        ->and($principal->exp)->toBe(SocketAuthFixtures::NOW + 30)
        ->and($principal->secondsRemaining())->toBe(30)
        ->and($principal->isCompanyScoped())->toBeFalse()
        ->and($principal->isSystem())->toBeFalse()
        ->and($principal->owns('contact_1'))->toBeTrue()
        ->and($principal->owns('contact-2'))->toBeFalse()
        ->and($principal->owns(''))->toBeFalse()
        ->and($principal->toClaims())->toBe([
            'kind' => 'customer',
            'sub'  => 'contact-1',
            'cid'  => 'company-a',
            'env'  => 'test',
            'ids'  => ['contact-1', 'contact_1'],
            'adm'  => false,
            'scp'  => ['storefront.store_1'],
            'sid'  => 'store-1',
            'jti'  => 'jti-1',
            'exp'  => SocketAuthFixtures::NOW + 30,
        ])
        ->and($minimal->env)->toBe('live')
        ->and($minimal->ids)->toBe([])
        ->and($minimal->scp)->toBeNull()
        ->and($minimal->exp)->toBeNull()
        ->and($minimal->secondsRemaining())->toBeNull()
        ->and($minimal->isCompanyScoped())->toBeTrue()
        ->and(SocketPrincipal::fromClaims(['kind' => 'api', 'sub' => 'cred-a', 'exp' => (string) (SocketAuthFixtures::NOW + 5)])->exp)->toBe(SocketAuthFixtures::NOW + 5)
        ->and($changed->kind)->toBe('customer')
        ->and($changed->env)->toBe('live')
        ->and($changed->sid)->toBeNull()
        ->and(SocketPrincipal::system()->toClaims())->toBe([
            'kind' => 'system',
            'sub'  => 'system',
            'env'  => 'live',
            'ids'  => [],
            'adm'  => false,
        ]);
});

test('user principals take the company from the argument, then the session, then the user', function () {
    SocketAuthFixtures::database();

    $admin    = SocketAuthFixtures::user(['type' => 'admin']);
    $explicit = SocketPrincipal::forUser($admin, 'company-b');

    session(['company' => 'company-b']);
    $fromSession = SocketPrincipal::forUser(SocketAuthFixtures::user());
    session()->flush();

    $fromUser = SocketPrincipal::forUser(SocketAuthFixtures::user());
    $unknown  = SocketPrincipal::forUser(SocketAuthFixtures::user(['company_uuid' => 'company-missing', 'public_id' => null]));
    $none     = SocketPrincipal::forUser(SocketAuthFixtures::user(['company_uuid' => null]));

    expect($explicit->kind)->toBe('user')
        ->and($explicit->sub)->toBe('user-a1')
        ->and($explicit->cid)->toBe('company-b')
        ->and($explicit->cpid)->toBe('company_bbb')
        ->and($explicit->env)->toBe('live')
        ->and($explicit->ids)->toBe(['user-a1', 'user_a1'])
        ->and($explicit->adm)->toBeTrue()
        ->and($fromSession->cid)->toBe('company-b')
        ->and($fromSession->adm)->toBeFalse()
        ->and($fromUser->cid)->toBe('company-a')
        ->and($fromUser->cpid)->toBe('company_aaa')
        ->and($unknown->cid)->toBe('company-missing')
        ->and($unknown->cpid)->toBeNull()
        ->and($unknown->ids)->toBe(['user-a1'])
        ->and($none->cid)->toBeNull()
        ->and($none->cpid)->toBeNull();
});

test('api credential principals act in the credential environment', function () {
    SocketAuthFixtures::database();

    $live = SocketPrincipal::forApiCredential(ApiCredential::query()->find('cred-a'));
    $test = SocketPrincipal::forApiCredential(ApiCredential::on('sandbox')->find('cred-a-test'));

    expect($live->kind)->toBe('api')
        ->and($live->sub)->toBe('cred-a')
        ->and($live->cid)->toBe('company-a')
        ->and($live->cpid)->toBe('company_aaa')
        ->and($live->env)->toBe('live')
        ->and($live->ids)->toBe(['cred-a'])
        ->and($test->sub)->toBe('cred-a-test')
        ->and($test->env)->toBe('test')
        ->and($test->cpid)->toBe('company_aaa');
});

test('channel decisions serialize, rebuild from cache and cap their lifetime', function () {
    $allowed = ChannelDecision::allowed('self');

    expect($allowed->toArray())->toBe(['allow' => true, 'ttl' => 300, 'reason' => 'self'])
        ->and(ChannelDecision::denied('forbidden')->toArray())->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'forbidden'])
        ->and($allowed->capTtl(42)->ttl)->toBe(42)
        ->and($allowed->capTtl(0)->ttl)->toBe(1)
        ->and($allowed->capTtl(900)->ttl)->toBe(300)
        ->and(ChannelDecision::fromArray(['allow' => true, 'ttl' => 12, 'reason' => 'cached'])->toArray())->toBe(['allow' => true, 'ttl' => 12, 'reason' => 'cached'])
        ->and(ChannelDecision::fromArray([])->toArray())->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'denied']);
});
