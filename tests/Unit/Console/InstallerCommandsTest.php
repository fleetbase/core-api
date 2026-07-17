<?php

use Fleetbase\Console\Commands\NotifyInstalled;
use Fleetbase\Console\Commands\SeedDatabase;
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

    public function __construct(private array $options = [])
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
