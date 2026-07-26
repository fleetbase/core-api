<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\AdminRequest;
use Illuminate\Support\Facades\Facade;

class SettingControllerQueueFake
{
    public ?string $exceptionMessage = null;

    public ?Throwable $exception = null;

    public array $pushedRaw = [];

    public function pushRaw(string $payload): mixed
    {
        if ($this->exception) {
            throw $this->exception;
        }

        if ($this->exceptionMessage) {
            throw new RuntimeException($this->exceptionMessage);
        }

        $this->pushedRaw[] = $payload;

        return 'job-id-1';
    }
}

function setting_controller_queue_fixtures(): SettingControllerQueueFake
{
    $container = bind_test_container([
        'queue.default'                       => 'sync',
        'queue.connections'                   => [
            'sync'       => ['driver' => 'sync'],
            'sqs'        => [
                'driver' => 'sqs',
                'prefix' => 'https://sqs.example.test/123456789012',
                'queue'  => 'fleetbase-jobs',
                'suffix' => '-prod',
            ],
            'beanstalkd' => [
                'driver' => 'beanstalkd',
                'host'   => 'beanstalkd.example.test',
                'queue'  => 'fleetbase-default',
            ],
        ],
        'queue.connections.sqs'               => [
            'driver' => 'sqs',
            'prefix' => 'https://sqs.example.test/123456789012',
            'queue'  => 'fleetbase-jobs',
            'suffix' => '-prod',
        ],
        'queue.connections.beanstalkd'        => [
            'driver' => 'beanstalkd',
            'host'   => 'beanstalkd.example.test',
            'queue'  => 'fleetbase-default',
        ],
    ]);

    $queue = new SettingControllerQueueFake();
    $container->instance('queue', $queue);
    Facade::clearResolvedInstance('queue');

    return $queue;
}

function setting_controller_queue_request(array $input = []): AdminRequest
{
    return AdminRequest::create('/int/v1/settings/queue', 'POST', $input);
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('queue config response exposes active driver connections and provider details', function () {
    setting_controller_queue_fixtures();

    $response = (new SettingController())->getQueueConfig(setting_controller_queue_request());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'driver'          => 'sync',
            'connections'     => ['sync', 'sqs', 'beanstalkd'],
            'beanstalkdHost'  => 'beanstalkd.example.test',
            'beanstalkdQueue' => 'fleetbase-default',
            'sqsPrefix'       => 'https://sqs.example.test/123456789012',
            'sqsQueue'        => 'fleetbase-jobs',
            'sqsSuffix'       => '-prod',
        ]);
});

test('test queue config switches the active queue and pushes a raw probe payload', function () {
    $queue = setting_controller_queue_fixtures();

    $response = (new SettingController())->testQueueConfig(setting_controller_queue_request([
        'queue' => 'sqs',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'success',
            'message' => 'Queue configuration is successful, message sent to queue.',
        ])
        ->and(config('queue.default'))->toBe('sqs')
        ->and($queue->pushedRaw)->toBe([
            json_encode(['message' => 'Hello World']),
        ]);
});

test('test queue config returns queue push exceptions as stable json errors', function () {
    $queue                   = setting_controller_queue_fixtures();
    $queue->exceptionMessage = 'SQS credentials rejected';

    $response = (new SettingController())->testQueueConfig(setting_controller_queue_request([
        'queue' => 'sqs',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'SQS credentials rejected',
        ])
        ->and(config('queue.default'))->toBe('sqs')
        ->and($queue->pushedRaw)->toBe([]);
});

test('test queue config returns sqs exceptions from the provider specific catch branch', function () {
    $queue            = setting_controller_queue_fixtures();
    $queue->exception = new Aws\Sqs\Exception\SqsException(
        'SQS queue URL is invalid',
        new Aws\Command('SendMessage')
    );

    $response = (new SettingController())->testQueueConfig(setting_controller_queue_request([
        'queue' => 'sqs',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'SQS queue URL is invalid',
        ])
        ->and(config('queue.default'))->toBe('sqs')
        ->and($queue->pushedRaw)->toBe([]);
});
