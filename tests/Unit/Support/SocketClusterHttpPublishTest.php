<?php

use Fleetbase\Support\SocketCluster\SocketClusterBroadcaster;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Fleetbase\Tests\Fixtures\Support\SocketAuthFixtures;
use Illuminate\Broadcasting\Channel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

class SocketClusterHttpPublishWebsocketRecorder extends SocketClusterService
{
    public array $sentMessages = [];

    public function __construct()
    {
    }

    public function send($channel, array $data = []): bool
    {
        $this->sentMessages[] = [$channel, $data];

        return true;
    }
}

function socket_cluster_http_service(): SocketClusterService
{
    return new SocketClusterService(['secure' => false, 'host' => 'socket.test', 'port' => 8000, 'path' => '/socketcluster/']);
}

afterEach(function () {
    SocketAuthFixtures::reset();
});

test('broadcasts go out as one signed publish request without empty-suffix channels', function () {
    SocketAuthFixtures::container();
    Http::fake(['*' => Http::response(['published' => 2], 202)]);

    $service = socket_cluster_http_service();
    (new SocketClusterBroadcaster($service))->broadcast(['order.order_1', 'company.', 'api.', 'order.order_1', new Channel('company.company_aaa'), ''], 'order.updated', ['id' => 'order_1']);

    $publishKey = hash_hmac('sha256', 'fleetbase-socket:publish', SocketAuthFixtures::KEY);

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) use ($publishKey) {
        $timestamp = $request->header('X-Fleetbase-Timestamp')[0];

        return $request->url() === 'http://socket.test:8001/publish'
            && $request->method() === 'POST'
            && $request->body() === '{"channels":["order.order_1","company.company_aaa"],"data":{"id":"order_1"}}'
            && $timestamp === (string) SocketAuthFixtures::NOW
            && $request->header('X-Fleetbase-Signature')[0] === hash_hmac('sha256', $timestamp . '.' . $request->body(), $publishKey);
    });

    expect($service->response())->toBe('{"published":2}')
        ->and($service->error())->toBeNull();
});

test('the static publish api and single sends use the signed endpoint when configured', function () {
    SocketAuthFixtures::container(SocketAuthFixtures::KEY, [
        'broadcasting.connections.socketcluster.publish_url' => 'http://socket.test:8001/',
    ]);
    Http::fake(['*' => Http::response(['published' => 1], 202)]);

    expect(SocketClusterService::publish('company.company_aaa', ['event' => 'updated']))->toBeTrue()
        ->and(socket_cluster_http_service()->send('chat.chat_aaa'))->toBeTrue()
        ->and(socket_cluster_http_service()->send('company.'))->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn (HttpRequest $request) => $request->body() === '{"channels":["company.company_aaa"],"data":{"event":"updated"}}');
    Http::assertSent(fn (HttpRequest $request) => $request->body() === '{"channels":["chat.chat_aaa"],"data":{}}');
});

test('failed signed publishes report the error without throwing', function () {
    SocketAuthFixtures::container();
    Http::fake(['*' => Http::response('unauthorized', 401)]);

    $rejected = socket_cluster_http_service();

    expect($rejected->sendMany(['order.order_1'], ['id' => 'order_1']))->toBeFalse()
        ->and($rejected->error())->toBe('Socket publish failed with HTTP status 401.')
        ->and($rejected->response())->toBe('unauthorized');

    // A fresh factory: stubs accumulate, so the 401 stub above would otherwise still match first.
    Http::swap(new HttpFactory());
    Http::fake(function () {
        throw new RuntimeException('socket server unreachable');
    });

    $unreachable = socket_cluster_http_service();

    expect($unreachable->sendMany(['order.order_1']))->toBeFalse()
        ->and($unreachable->error())->not->toBeNull();
});

test('without an auth key broadcasts keep using the websocket publisher per channel', function () {
    SocketAuthFixtures::container(null);
    Http::fake();

    $service = new SocketClusterHttpPublishWebsocketRecorder();
    (new SocketClusterBroadcaster($service))->broadcast(['company.company_aaa', 'api.', 'user.user_a1'], 'user.updated', ['id' => 'user_a1']);

    expect($service->sentMessages)->toBe([
        ['company.company_aaa', ['id' => 'user_a1']],
        ['user.user_a1', ['id' => 'user_a1']],
    ])
        ->and($service->sendMany(['company.', ' ']))->toBeTrue()
        ->and(SocketClusterService::publishesOverHttp())->toBeFalse();

    Http::assertNothingSent();
});

test('publish urls come from config with a fallback to the socket host internal port', function () {
    SocketAuthFixtures::container();

    expect(SocketClusterService::publishUrl())->toBe('http://socket.test:8001/publish')
        ->and(SocketClusterService::filterChannels(['a.b', new Channel('c.d'), 'a.b', 'e.', '  ', 'f g', str_repeat('h', 256)]))->toBe(['a.b', 'c.d']);

    config(['broadcasting.connections.socketcluster.publish_url' => '']);

    expect(SocketClusterService::publishUrl())->toBe('http://socket.test:8001/publish');

    config([
        'broadcasting.connections.socketcluster.publish_url'  => null,
        'broadcasting.connections.socketcluster.options.host' => 'realtime.internal',
    ]);

    expect(SocketClusterService::publishUrl())->toBe('http://realtime.internal:8001/publish');
});
