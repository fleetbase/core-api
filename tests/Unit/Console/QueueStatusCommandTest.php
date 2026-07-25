<?php

use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Fleetbase\Console\Commands\QueueStatusCommand;
use Illuminate\Support\Facades\Facade;

class QueueStatusCommandOutputFake extends QueueStatusCommand
{
    public array $messages = [];

    public function exposeMakeSqsClient(array $sqsConfig, Credentials $credentials): SqsClient
    {
        return $this->makeSqsClient($sqsConfig, $credentials);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = ['error', $string];
    }

    public function warn($string, $verbosity = null): void
    {
        $this->messages[] = ['warn', $string];
    }
}

class QueueStatusCommandSqsFake extends QueueStatusCommandOutputFake
{
    public function __construct(private MockHandler $handler)
    {
        parent::__construct();
    }

    protected function makeSqsClient(array $sqsConfig, Credentials $credentials): SqsClient
    {
        return new SqsClient([
            'version'     => 'latest',
            'region'      => $sqsConfig['region'],
            'credentials' => $credentials,
            'handler'     => $this->handler,
        ]);
    }
}

class QueueStatusRedisManagerFake
{
    public function __construct(private mixed $pingResponse = 'PONG', private ?Throwable $exception = null)
    {
    }

    public function connection(string $name): object
    {
        if ($this->exception) {
            throw $this->exception;
        }

        return new class($this->pingResponse) {
            public function __construct(private mixed $pingResponse)
            {
            }

            public function ping(): mixed
            {
                return $this->pingResponse;
            }
        };
    }
}

class QueueStatusDatabaseManagerFake
{
    public function __construct(private ?Throwable $exception = null)
    {
    }

    public function connection(?string $name = null): object
    {
        if ($this->exception) {
            throw $this->exception;
        }

        return new class {
            public function select(string $query): array
            {
                return [(object) ['ok' => 1]];
            }
        };
    }
}

function queue_status_command(array $config = []): QueueStatusCommandOutputFake
{
    bind_test_container(array_merge([
        'queue.default'                         => 'sync',
        'queue.connections.redis.connection'    => 'default',
        'queue.connections.database.connection' => 'mysql',
        'database.default'                      => 'mysql',
    ], $config));
    Facade::clearResolvedInstances();

    return new QueueStatusCommandOutputFake();
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

it('reports healthy redis queue connections and accepts common ping responses', function (mixed $pingResponse) {
    $container = bind_test_container([
        'queue.default'                      => 'redis',
        'queue.connections.redis.connection' => 'cache',
    ]);
    $container->instance('redis', new QueueStatusRedisManagerFake($pingResponse));
    Facade::clearResolvedInstances();

    $command = new QueueStatusCommandOutputFake();

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toBe([
            ['info', 'Checking queue status for driver: redis'],
            ['info', 'Redis connection is healthy: ' . $pingResponse],
        ]);
})->with(['PONG', '+PONG', 1, true]);

it('reports unhealthy redis queue responses and connection failures', function () {
    $container = bind_test_container([
        'queue.default'                      => 'redis',
        'queue.connections.redis.connection' => 'cache',
    ]);
    $container->instance('redis', new QueueStatusRedisManagerFake('NOPE'));
    Facade::clearResolvedInstances();

    $unexpected       = new QueueStatusCommandOutputFake();
    $unexpectedResult = $unexpected->handle();

    $container->instance('redis', new QueueStatusRedisManagerFake(exception: new RuntimeException('connection refused')));
    Facade::clearResolvedInstance('redis');

    $exception = new QueueStatusCommandOutputFake();

    expect($unexpectedResult)->toBe(1)
        ->and($unexpected->messages)->toBe([
            ['info', 'Checking queue status for driver: redis'],
            ['error', 'Unexpected response from Redis: NOPE'],
        ])
        ->and($exception->handle())->toBe(1)
        ->and($exception->messages)->toBe([
            ['info', 'Checking queue status for driver: redis'],
            ['error', 'Redis connection failed: connection refused'],
        ]);
});

it('reports healthy and failing database queue connections', function () {
    $container = bind_test_container([
        'queue.default'                         => 'database',
        'queue.connections.database.connection' => 'queues',
        'database.default'                      => 'mysql',
    ]);
    $container->instance('db', new QueueStatusDatabaseManagerFake());
    Facade::clearResolvedInstances();

    $healthy       = new QueueStatusCommandOutputFake();
    $healthyResult = $healthy->handle();

    $container->instance('db', new QueueStatusDatabaseManagerFake(new RuntimeException('database down')));
    Facade::clearResolvedInstance('db');

    $failing = new QueueStatusCommandOutputFake();

    expect($healthyResult)->toBe(0)
        ->and($healthy->messages)->toBe([
            ['info', 'Checking queue status for driver: database'],
            ['info', 'Database queue connection is healthy.'],
        ])
        ->and($failing->handle())->toBe(1)
        ->and($failing->messages)->toBe([
            ['info', 'Checking queue status for driver: database'],
            ['error', 'Database queue connection failed: database down'],
        ]);
});

it('reports healthy and failing sqs queue connections', function () {
    bind_test_container([
        'queue.default'                => 'sqs',
        'queue.connections.sqs.key'    => 'test-key',
        'queue.connections.sqs.secret' => 'test-secret',
        'queue.connections.sqs.token'  => 'test-token',
        'queue.connections.sqs.region' => 'us-east-1',
    ]);

    $healthyHandler = new MockHandler([
        new Result([
            'QueueUrls' => [
                'https://sqs.us-east-1.amazonaws.com/123/default',
                'https://sqs.us-east-1.amazonaws.com/123/critical',
            ],
        ]),
    ]);
    $healthy       = new QueueStatusCommandSqsFake($healthyHandler);
    $healthyResult = $healthy->handle();

    $failingHandler = new MockHandler([
        function (CommandInterface $command) {
            throw new AwsException('AWS credentials rejected', $command);
        },
    ]);
    $failing = new QueueStatusCommandSqsFake($failingHandler);

    expect($healthyResult)->toBe(0)
        ->and($healthy->messages)->toBe([
            ['info', 'Checking queue status for driver: sqs'],
            ['info', 'SQS connection is healthy. Queues: https://sqs.us-east-1.amazonaws.com/123/default, https://sqs.us-east-1.amazonaws.com/123/critical'],
        ])
        ->and($failing->handle())->toBe(1)
        ->and($failing->messages)->toBe([
            ['info', 'Checking queue status for driver: sqs'],
            ['error', 'SQS connection failed: AWS credentials rejected'],
        ]);
});

it('builds default sqs clients from queue region and credentials', function () {
    $credentials = new Credentials('test-key', 'test-secret', 'test-token');
    $client      = (new QueueStatusCommandOutputFake())->exposeMakeSqsClient([
        'region' => 'ap-southeast-1',
    ], $credentials);

    expect($client)->toBeInstanceOf(SqsClient::class)
        ->and($client->getRegion())->toBe('ap-southeast-1')
        ->and($client->getCredentials()->wait())->toBe($credentials);
});

it('warns without failing for drivers without a health check', function () {
    $command = queue_status_command(['queue.default' => 'sync']);

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toBe([
            ['info', 'Checking queue status for driver: sync'],
            ['warn', 'No specific health check implemented for driver: sync'],
        ]);
});
