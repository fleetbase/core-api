<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class AuthControllerSessionCacheFake
{
    public array $forgotten = [];

    public array $rememberCalls = [];

    public array $values = [];

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        $this->rememberCalls[] = [$key, $ttl];

        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;
        unset($this->values[$key]);

        return true;
    }
}

class AuthControllerSessionGuardFake
{
    public int $logoutCalls = 0;

    public function logout(): void
    {
        $this->logoutCalls++;
    }
}

function auth_controller_session_fixtures(): array
{
    $container = bind_test_container([
        'app.timezone' => 'UTC',
    ]);
    $cache     = new AuthControllerSessionCacheFake();
    $guard     = new AuthControllerSessionGuardFake();

    $container->instance('cache', $cache);
    $container->instance('auth', $guard);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('auth');
    session()->flush();

    return [$cache, $guard];
}

function auth_controller_session_request(?User $user = null, string $token = 'session-token'): Request
{
    $request = Request::create('/int/v1/auth/session', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    ]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function auth_controller_session_user(): User
{
    $user = new User();
    $user->setRawAttributes([
        'uuid'              => '11111111-1111-4111-8111-111111111111',
        'type'              => 'admin',
        'email_verified_at' => '2026-07-18 10:00:00',
        'updated_at'        => Carbon::parse('2026-07-18 12:30:00', 'UTC'),
    ], true);

    return $user;
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    Facade::clearResolvedInstances();
});

test('session returns authenticated user metadata with cache validators', function () {
    [$cache] = auth_controller_session_fixtures();
    $user    = auth_controller_session_user();
    $request = auth_controller_session_request($user, 'token-123');
    session(['impersonator' => 'admin-user']);

    Carbon::setTestNow(Carbon::parse('2026-07-18 13:00:00', 'UTC'));

    $response = (new AuthController())->session($request);
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload)->toMatchArray([
            'token'        => 'token-123',
            'user'         => '11111111-1111-4111-8111-111111111111',
            'verified'     => true,
            'type'         => 'admin',
            'impersonator' => 'admin-user',
        ])
        ->and($payload)->toHaveKey('last_modified')
        ->and($cache->rememberCalls)->toHaveCount(1)
        ->and($cache->rememberCalls[0][0])->toBe('session_validation_token-123')
        ->and($cache->rememberCalls[0][1]->equalTo(Carbon::parse('2026-07-18 13:05:00', 'UTC')))->toBeTrue()
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('Cache-Control'))->toContain('must-revalidate')
        ->and($response->headers->get('X-Cache-Hit'))->toBe('false')
        ->and($response->getEtag())->toBe('"' . sha1(json_encode($cache->values['session_validation_token-123'])) . '"')
        ->and($response->getLastModified()->format('Y-m-d H:i:s'))->toBe('2026-07-18 12:30:00');
});

test('session returns expired response when request has no authenticated user', function () {
    [$cache] = auth_controller_session_fixtures();

    $response = (new AuthController())->session(auth_controller_session_request(null, 'expired-token'));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true))->toBe([
            'errors'  => ['Session has expired.'],
            'restore' => false,
        ])
        ->and($cache->rememberCalls)->toHaveCount(1)
        ->and($cache->rememberCalls[0][0])->toBe('session_validation_expired-token')
        ->and($cache->values['session_validation_expired-token'])->toBeNull();
});

test('logout clears cached session validation and delegates to auth guard', function () {
    [$cache, $guard]                               = auth_controller_session_fixtures();
    $cache->values['session_validation_token-123'] = ['user' => '11111111-1111-4111-8111-111111111111'];

    $response = (new AuthController())->logout(auth_controller_session_request(null, 'token-123'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['Goodbye'])
        ->and($cache->forgotten)->toBe(['session_validation_token-123'])
        ->and($cache->values)->not->toHaveKey('session_validation_token-123')
        ->and($guard->logoutCalls)->toBe(1);
});
