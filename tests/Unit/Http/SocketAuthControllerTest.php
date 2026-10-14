<?php

use Fleetbase\Http\Controllers\SocketAuthController;
use Fleetbase\Http\Middleware\VerifySocketSignature;
use Fleetbase\Models\User;
use Fleetbase\Support\SocketCluster\ChannelAuthorizer;
use Fleetbase\Support\SocketCluster\CoreChannelResolvers;
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Fleetbase\Support\SocketCluster\SocketSignature;
use Fleetbase\Support\SocketCluster\SocketToken;
use Fleetbase\Tests\Fixtures\Support\SocketAuthFixtures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

function socket_auth_controller_registry(): SocketChannelRegistry
{
    $registry = new SocketChannelRegistry();
    CoreChannelResolvers::register($registry);

    app()->instance(SocketChannelRegistry::class, $registry);
    app()->instance(ChannelAuthorizer::class, new ChannelAuthorizer($registry));

    return $registry;
}

function socket_auth_controller_request(string $uri, array $server = [], ?string $content = null): Request
{
    return Request::create($uri, 'POST', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $server), $content);
}

function socket_auth_controller_principal(JsonResponse $response): ?SocketPrincipal
{
    return SocketToken::verify($response->getData(true)['token']);
}

afterEach(function () {
    SocketAuthFixtures::reset();
});

test('every token route answers 404 while socket authentication is not configured', function () {
    SocketAuthFixtures::container(null);

    $controller = new SocketAuthController();
    $request    = socket_auth_controller_request('/int/v1/socket/token');

    foreach ([$controller->token($request), $controller->apiToken($request), $controller->systemToken($request)] as $response) {
        expect($response->getStatusCode())->toBe(404)
            ->and($response->getData(true))->toBe(['error' => 'Not Found']);
    }
});

test('console sessions receive a user token for their company and environment', function () {
    SocketAuthFixtures::database();

    $controller      = new SocketAuthController();
    $unauthenticated = $controller->token(socket_auth_controller_request('/int/v1/socket/token'));

    $request = socket_auth_controller_request('/int/v1/socket/token');
    $request->setUserResolver(fn () => User::query()->find('user-a1'));
    $response = $controller->token($request);

    $sandboxRequest = socket_auth_controller_request('/int/v1/socket/token', ['HTTP_ACCESS_CONSOLE_SANDBOX' => 'true']);
    $sandboxRequest->setUserResolver(fn () => User::query()->find('user-a1'));
    $sandbox = socket_auth_controller_principal($controller->token($sandboxRequest));

    $principal = socket_auth_controller_principal($response);

    expect($unauthenticated->getStatusCode())->toBe(401)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toHaveKeys(['token', 'expires_in', 'expires_at'])
        ->and($response->getData(true)['expires_in'])->toBe(900)
        ->and($principal->kind)->toBe('user')
        ->and($principal->sub)->toBe('user-a1')
        ->and($principal->cid)->toBe('company-a')
        ->and($principal->cpid)->toBe('company_aaa')
        ->and($principal->env)->toBe('live')
        ->and($sandbox->env)->toBe('test');
});

test('api clients receive an api, user or registered principal token', function () {
    SocketAuthFixtures::database();

    $registry   = socket_auth_controller_registry();
    $controller = new SocketAuthController();
    $userToken  = socket_auth_controller_request('/v1/socket/token', ['HTTP_AUTHORIZATION' => 'Bearer plain-token-a1']);
    $user       = socket_auth_controller_principal($controller->apiToken($userToken));

    $registry->registerPrincipalResolver(function (Request $request, User $user) {
        return new SocketPrincipal(kind: 'driver', sub: 'driver-1', cid: $user->company_uuid, ids: ['driver-1', $user->uuid]);
    });
    $driver = socket_auth_controller_principal($controller->apiToken($userToken));

    session(['api_credential' => 'cred-a']);
    $credential = socket_auth_controller_principal($controller->apiToken(socket_auth_controller_request('/v1/socket/token', ['HTTP_AUTHORIZATION' => 'Bearer flb_live_a'])));
    session()->flush();

    $anonymous = $controller->apiToken(socket_auth_controller_request('/v1/socket/token'));

    expect($user->kind)->toBe('user')
        ->and($user->sub)->toBe('user-a1')
        ->and($user->cid)->toBe('company-a')
        ->and($driver->kind)->toBe('driver')
        ->and($driver->ids)->toBe(['driver-1', 'user-a1'])
        ->and($credential->kind)->toBe('api')
        ->and($credential->sub)->toBe('cred-a')
        ->and($credential->env)->toBe('live')
        ->and($anonymous->getStatusCode())->toBe(401);
});

test('platform callers receive a short lived system token', function () {
    SocketAuthFixtures::container();

    $response  = (new SocketAuthController())->systemToken(socket_auth_controller_request('/v1/socket/token'));
    $principal = socket_auth_controller_principal($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['expires_in'])->toBe(300)
        ->and($principal->isSystem())->toBeTrue();
});

test('the authorize endpoint re-verifies the token and answers with a cacheable decision', function () {
    SocketAuthFixtures::database();
    socket_auth_controller_registry();

    $controller = new SocketAuthController();
    $token      = SocketToken::issue(SocketPrincipal::forUser(User::query()->find('user-a1')))['token'];
    $ask        = function (array $body) use ($controller) {
        return $controller->authorizeChannel(socket_auth_controller_request('/int/v1/socket/authorize', [], json_encode($body)))->getData(true);
    };

    expect($ask(['token' => $token, 'channel' => 'chat.chat_aaa']))->toBe(['allow' => true, 'ttl' => 300, 'reason' => 'resolver'])
        ->and($ask(['token' => $token, 'channel' => 'chat.chat_bbb']))->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'forbidden'])
        ->and($ask(['token' => $token . 'x', 'channel' => 'chat.chat_aaa']))->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'invalid_token'])
        ->and($ask(['token' => null, 'channel' => 'company.company_aaa']))->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'no_token'])
        ->and($ask(['token' => $token, 'channel' => ['not', 'a', 'string']]))->toBe(['allow' => false, 'ttl' => 30, 'reason' => 'invalid_channel']);
});

test('the authorize endpoint only admits requests signed with the authorize key', function () {
    SocketAuthFixtures::container();

    $middleware = new VerifySocketSignature();
    $body       = '{"token":null,"channel":"fleetbase.install"}';
    $now        = (string) SocketAuthFixtures::NOW;
    $stale      = (string) (SocketAuthFixtures::NOW - 120);
    $next       = function () {
        return new JsonResponse(['passed' => true]);
    };
    $send = function (?string $timestamp, ?string $signature) use ($middleware, $body, $next) {
        $headers = array_filter([
            'HTTP_X_FLEETBASE_TIMESTAMP' => $timestamp,
            'HTTP_X_FLEETBASE_SIGNATURE' => $signature,
        ]);

        return $middleware->handle(socket_auth_controller_request('/int/v1/socket/authorize', $headers, $body), $next);
    };

    $signature = SocketSignature::sign(SocketSignature::AUTHORIZE, $now, $body);
    $good      = $send($now, $signature);
    $rejected  = [
        $send($now, SocketSignature::sign(SocketSignature::PUBLISH, $now, $body)),
        $send($stale, SocketSignature::sign(SocketSignature::AUTHORIZE, $stale, $body)),
        $send($now, str_repeat('0', 64)),
        $send(null, null),
    ];

    expect($good->getStatusCode())->toBe(200)
        ->and($good->getData(true))->toBe(['passed' => true]);

    foreach ($rejected as $response) {
        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toBe(['error' => 'invalid_signature']);
    }

    config(['broadcasting.connections.socketcluster.auth_key' => null]);

    expect($send($now, $signature)->getStatusCode())->toBe(404);
});
