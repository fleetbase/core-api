<?php

use Fleetbase\Support\SocketCluster\SocketClusterBroadcaster;
use Fleetbase\Support\SocketCluster\SocketClusterHandshake;
use Fleetbase\Support\SocketCluster\SocketClusterMessage;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Illuminate\Broadcasting\Channel;
use WebSocket\Client;
use WebSocket\ConnectionException;
use WebSocket\Message\Text;
use WebSocket\TimeoutException;

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

class SocketClusterClientFake extends Client
{
    public array $sent = [];
    public int $closed = 0;

    public function __construct(private array $receives = [], private ?Throwable $sendException = null, private ?Throwable $receiveException = null)
    {
    }

    public function send($payload, string $opcode = 'text', ?bool $masked = null): void
    {
        if ($this->sendException) {
            throw $this->sendException;
        }

        $this->sent[] = $payload;
    }

    public function receive()
    {
        if ($this->receiveException) {
            throw $this->receiveException;
        }

        return array_shift($this->receives) ?? null;
    }

    public function close(int $status = 1000, string $message = 'ttfn'): void
    {
        $this->closed++;
    }
}

class SocketClusterServiceHarness extends SocketClusterService
{
    public function __construct(Client $client, array $options = [])
    {
        $this->client  = $client;
        $this->options = $options;
        $this->uri     = 'ws://socket.test/';
    }

    public function handshakeError(): ?string
    {
        return $this->handshakeError;
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

class StaticRecordingSocketClusterService extends SocketClusterService
{
    public static ?self $lastInstance = null;

    public array $sentMessages = [];

    public function __construct(public array|string $capturedOptions = [])
    {
    }

    public function send($channel, array $data = []): bool
    {
        $this->sentMessages[] = [$channel, $data];

        return true;
    }

    public static function instance($options = []): SocketClusterService
    {
        return static::$lastInstance = new static($options);
    }
}

function decode_socket_cluster_payload(string $payload): array
{
    return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
}

it('creates publish payloads for string and laravel channels', function () {
    $payload        = SocketClusterMessage::createSocketClusterPayload(['id' => 123], 'orders.created', 44);
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
    $payload   = SocketClusterMessage::createSocketClusterHandshake(98);
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
    $text    = SocketClusterMessage::create('activity', ['count' => 3]);

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
        ->and($probe->getOption('nested'))->toBe(['trimmed' => ' value '])
        ->and($probe->getOption('nested.trimmed'))->toBe('value')
        ->and($probe->getOption('missing', 'fallback'))->toBe('fallback');
});

it('creates socket cluster service instances and publishes through the static service contract', function () {
    $probe = SocketClusterServiceProbe::instance(['host' => 'socket.test']);

    expect($probe)->toBeInstanceOf(SocketClusterServiceProbe::class);

    expect(StaticRecordingSocketClusterService::publish('company.1', ['event' => 'updated'], ['host' => 'socket.test']))->toBeTrue()
        ->and(StaticRecordingSocketClusterService::$lastInstance)->toBeInstanceOf(StaticRecordingSocketClusterService::class)
        ->and(StaticRecordingSocketClusterService::$lastInstance->capturedOptions)->toBe(['host' => 'socket.test'])
        ->and(StaticRecordingSocketClusterService::$lastInstance->sentMessages)->toBe([
            ['company.1', ['event' => 'updated']],
        ]);
});

it('constructs socket cluster clients from normalized connection options without connecting immediately', function () {
    $service = new SocketClusterService([
        'secure' => false,
        'host'   => 'socket.test',
        'path'   => '/socketcluster/',
        'query'  => ['tenant' => 'acme'],
    ]);

    expect($service->getUri())->toBe('ws://socket.test:8000/socketcluster/?tenant=acme')
        ->and($service->getClient())->toBeInstanceOf(Client::class);
});

it('broadcasts payloads to every channel through the socket cluster service', function () {
    $service     = new RecordingSocketClusterService();
    $broadcaster = new SocketClusterBroadcaster($service);

    $broadcaster->broadcast(['company.1', 'company.2'], 'IgnoredEventName', ['message' => 'updated']);

    expect($service->sentMessages)->toBe([
        ['company.1', ['message' => 'updated']],
        ['company.2', ['message' => 'updated']],
    ])->and($broadcaster->auth(null))->toBeNull()
        ->and($broadcaster->validAuthenticationResponse(null, true))->toBeNull();
});

it('sends socket cluster handshakes messages and records successful response state', function () {
    $client  = new SocketClusterClientFake(['handshake-ok', 'publish-ok']);
    $service = new SocketClusterServiceHarness($client, ['timeout' => ' 5 ']);

    expect($service->getClient())->toBe($client)
        ->and($service->getUri())->toBe('ws://socket.test/')
        ->and($service->getOption('timeout'))->toBe('5')
        ->and($service->send('activity', ['count' => 2]))->toBeTrue()
        ->and($service->response())->toBe('publish-ok')
        ->and($service->error())->toBeNull()
        ->and($client->closed)->toBe(1)
        ->and($client->sent)->toHaveCount(2)
        ->and($client->sent[0])->toBeInstanceOf(SocketClusterHandshake::class)
        ->and($client->sent[1])->toBeInstanceOf(SocketClusterMessage::class);
});

it('captures socket cluster send and handshake failures without throwing', function (Throwable $exception, string $message) {
    $sendService = new SocketClusterServiceHarness(new SocketClusterClientFake([], $exception));

    expect($sendService->send('activity'))->toBeFalse()
        ->and($sendService->error())->toBe($message);

    $handshakeService = new SocketClusterServiceHarness(new SocketClusterClientFake([], null, $exception));

    expect($handshakeService->handshake(10))->toBeFalse()
        ->and($handshakeService->handshakeError())->toBe($message);
})->with([
    'connection exception' => [new ConnectionException('socket unavailable'), 'socket unavailable'],
    'timeout exception'    => [new TimeoutException('socket timed out'), 'socket timed out'],
    'generic exception'    => [new RuntimeException('socket failed'), 'socket failed'],
]);
