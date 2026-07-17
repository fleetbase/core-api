<?php

use Fleetbase\Support\SocketCluster\SocketClusterBroadcaster;
use Fleetbase\Support\SocketCluster\SocketClusterHandshake;
use Fleetbase\Support\SocketCluster\SocketClusterMessage;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Illuminate\Broadcasting\Channel;
use WebSocket\Message\Text;

class SocketClusterServiceProbe extends SocketClusterService
{
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function exposeGetOptions($options): array
    {
        return $this->getOptions($options);
    }

    public function exposeParseOptions($options): string
    {
        return $this->parseOptions($options);
    }
}

class RecordingSocketClusterService extends SocketClusterService
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

function decode_socket_cluster_payload(string $payload): array
{
    return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
}

it('creates publish payloads for string and laravel channels', function () {
    $payload = SocketClusterMessage::createSocketClusterPayload(['id' => 123], 'orders.created', 44);
    $channelPayload = SocketClusterMessage::createSocketClusterPayload(['status' => 'ready'], new Channel('dispatch'), 45);

    expect(decode_socket_cluster_payload($payload))->toBe([
        'event' => '#publish',
        'data'  => [
            'channel' => 'orders.created',
            'data'    => ['id' => 123],
        ],
        'cid' => 44,
    ])->and(decode_socket_cluster_payload($channelPayload))->toBe([
        'event' => '#publish',
        'data'  => [
            'channel' => 'dispatch',
            'data'    => ['status' => 'ready'],
        ],
        'cid' => 45,
    ]);
});

it('omits the channel key for channel-less publish payloads', function () {
    $payload = SocketClusterMessage::createSocketClusterPayload(['ok' => true], '', 7);

    expect(decode_socket_cluster_payload($payload))->toBe([
        'event' => '#publish',
        'data'  => [
            'data' => ['ok' => true],
        ],
        'cid' => 7,
    ]);
});

it('creates handshake payloads without publish data', function () {
    $payload = SocketClusterMessage::createSocketClusterHandshake(98);
    $handshake = new SocketClusterHandshake(99);

    expect(decode_socket_cluster_payload($payload))->toBe([
        'event' => '#handshake',
        'data'  => [],
        'cid'   => 98,
    ])->and($handshake->getOpcode())->toBe('text')
        ->and(decode_socket_cluster_payload($handshake->getContent()))->toBe([
            'event' => '#handshake',
            'data'  => [],
            'cid'   => 99,
        ]);
});

it('stores message state and exposes a concrete text websocket message', function () {
    $message = new SocketClusterMessage('activity', ['count' => 2], 51);
    $text = SocketClusterMessage::create('activity', ['count' => 3]);

    expect($message->channel)->toBe('activity')
        ->and($message->data)->toBe(['count' => 2])
        ->and($message->cid)->toBe(51)
        ->and($message->getOpcode())->toBe('text')
        ->and(decode_socket_cluster_payload($message->getContent()))->toBe([
            'event' => '#publish',
            'data'  => [
                'channel' => 'activity',
                'data'    => ['count' => 2],
            ],
            'cid' => 51,
        ])
        ->and($text)->toBeInstanceOf(Text::class)
        ->and($text->getOpcode())->toBe('text')
        ->and(decode_socket_cluster_payload($text->getContent()))->toBe([
            'event' => '#publish',
            'data'  => [
                'channel' => 'activity',
                'data'    => ['count' => 3],
            ],
            'cid' => 1,
        ]);
});

it('merges configured options and normalizes socket cluster uris', function () {
    bind_test_container([
        'broadcasting.connections.socketcluster.options' => [
            'host'    => ' configured.test ',
            'port'    => 8000,
            'path'    => '/socketcluster/',
            'query'   => ['token' => 'abc'],
            'timeout' => 5,
        ],
    ]);

    $probe = new SocketClusterServiceProbe([
        'nested' => ['trimmed' => ' value '],
    ]);

    expect($probe->exposeGetOptions(['secure' => true, 'host' => 'override.test']))->toMatchArray([
        'secure' => true,
        'host'   => 'override.test',
        'port'   => 8000,
        'path'   => '/socketcluster/',
    ])->and($probe->exposeGetOptions('https://fleetbase.test:443/realtime?tenant=acme'))->toMatchArray([
        'scheme' => 'https',
        'host'   => 'fleetbase.test',
        'port'   => 443,
        'path'   => '/realtime',
        'query'  => 'tenant=acme',
    ])->and($probe->exposeParseOptions([
        'scheme' => 'https',
        'host'   => '/socket.test/',
        'port'   => 443,
        'path'   => '/ws/',
        'query'  => 'tenant=acme&token=xyz',
    ]))->toBe('wss://socket.test:443/ws/?tenant=acme&token=xyz')
        ->and($probe->exposeParseOptions([
            'secure' => false,
            'host'   => 'socket.test',
            'query'  => ['tenant' => 'acme'],
        ]))->toBe('ws://socket.test/?tenant=acme')
        ->and($probe->getOption('nested.trimmed'))->toBe('value')
        ->and($probe->getOption('missing', 'fallback'))->toBe('fallback');
});

it('broadcasts payloads to every channel through the socket cluster service', function () {
    $service = new RecordingSocketClusterService();
    $broadcaster = new SocketClusterBroadcaster($service);

    $broadcaster->broadcast(['company.1', 'company.2'], 'IgnoredEventName', ['message' => 'updated']);

    expect($service->sentMessages)->toBe([
        ['company.1', ['message' => 'updated']],
        ['company.2', ['message' => 'updated']],
    ])->and($broadcaster->auth(null))->toBeNull()
        ->and($broadcaster->validAuthenticationResponse(null, true))->toBeNull();
});
