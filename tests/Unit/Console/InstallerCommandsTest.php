<?php

use Fleetbase\Console\Commands\NotifyInstalled;
use Fleetbase\Console\Commands\SeedDatabase;
use Fleetbase\Support\SocketCluster\SocketClusterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Psr\Log\AbstractLogger;

class SeedDatabaseTestCommand extends SeedDatabase
{
    public array $seeders = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runSeeder(string $class): int
    {
        $this->seeders[] = $class;

        return 0;
    }
}

class NotifyInstalledTestCommand extends NotifyInstalled
{
    public array $messages = [];

    public function __construct(private array $options = [], private ?SocketClusterService $socketClusterService = null)
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function warn($string, $verbosity = null): void
    {
        $this->messages[] = ['warn', $string];
    }

    protected function makeSocketClusterService(): SocketClusterService
    {
        return $this->socketClusterService ?? parent::makeSocketClusterService();
    }
}

class NotifyInstalledSocketClusterFake extends SocketClusterService
{
    public array $sentPayloads = [];

    public function __construct(private bool $sendResult = true, private ?string $sendError = null, private ?Throwable $throwable = null)
    {
    }

    public function send($channel, array $data = []): bool
    {
        if ($this->throwable) {
            throw $this->throwable;
        }

        $this->sentPayloads[] = [
            'channel' => $channel,
            'data'    => $data,
        ];

        return $this->sendResult;
    }

    public function error(): ?string
    {
        return $this->sendError;
    }
}

class InstallerCommandLogger extends AbstractLogger
{
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:34:56'));
    bind_test_container([
        'broadcasting.connections.socketcluster.options' => [
            'secure'  => false,
            'host'    => '127.0.0.1',
            'port'    => 1,
            'path'    => '',
            'query'   => [],
            'timeout' => 1,
        ],
    ]);
    Facade::clearResolvedInstances();
});

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

it('dispatches an explicit Fleetbase seeder class with force enabled', function () {
    $command = new SeedDatabaseTestCommand([
        'class' => 'SystemConfigSeeder',
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->seeders)->toBe([
            'Fleetbase\\Seeders\\SystemConfigSeeder',
        ]);
});

it('warns and logs when install notification socket delivery fails on the default channel', function () {
    $logger = new InstallerCommandLogger();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');

    $command = new NotifyInstalledTestCommand([
        'channel' => null,
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toHaveCount(1)
        ->and($command->messages[0][0])->toBe('warn')
        ->and($command->messages[0][1])->toStartWith('Install notification was not sent: ')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toBe('Fleetbase install notification was not sent.')
        ->and($logger->records[0]['context']['channel'])->toBe('fleetbase.install')
        ->and($logger->records[0]['context']['error'])->toBeString()->not->toBe('');
});

it('sends install notification payloads on the configured channel', function () {
    $socket  = new NotifyInstalledSocketClusterFake();
    $command = new NotifyInstalledTestCommand([
        'channel' => 'tenant.install.completed',
    ], $socket);

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toBe([
            ['info', 'Install notification sent.'],
        ])
        ->and($socket->sentPayloads)->toBe([
            [
                'channel' => 'tenant.install.completed',
                'data'    => [
                    'event'     => 'fleetbase.installed',
                    'installed' => true,
                    'timestamp' => '2026-07-17T12:34:56+00:00',
                ],
            ],
        ]);
});

it('uses the configured install notification channel in warning logs', function () {
    $logger = new InstallerCommandLogger();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');

    $command = new NotifyInstalledTestCommand([
        'channel' => 'tenant.install.completed',
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->messages[0][1])->toStartWith('Install notification was not sent: ')
        ->and($logger->records[0]['context']['channel'])->toBe('tenant.install.completed');
});

it('logs thrown install notification failures without failing the command', function () {
    $logger = new InstallerCommandLogger();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');

    $command = new NotifyInstalledTestCommand([
        'channel' => 'tenant.install.completed',
    ], new NotifyInstalledSocketClusterFake(throwable: new RuntimeException('socket boom')));

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toBe([
            ['warn', 'Install notification failed: socket boom'],
        ])
        ->and($logger->records)->toBe([
            [
                'level'   => 'warning',
                'message' => 'Fleetbase install notification failed.',
                'context' => [
                    'channel' => 'tenant.install.completed',
                    'error'   => 'socket boom',
                ],
            ],
        ]);
});
