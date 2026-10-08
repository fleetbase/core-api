<?php

use Fleetbase\Console\Commands\BackupDatabase;
use Fleetbase\Http\Controllers\Internal\v1\DatabaseBackupController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Jobs\RunDatabaseBackup;
use Fleetbase\Models\DatabaseBackup;
use Fleetbase\Notifications\DatabaseBackupFailed;
use Fleetbase\Services\DatabaseBackup\DatabaseBackupException;
use Fleetbase\Services\DatabaseBackup\DatabaseBackupService;
use Fleetbase\Support\DatabaseBackupSettings;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class DatabaseBackupsTestContainer extends FleetbaseTestContainer
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}

/**
 * The real service with the dump client swapped for a PHP one-liner, so the streaming,
 * compression and verification all run for real.
 */
class DatabaseBackupsServiceFake extends DatabaseBackupService
{
    public string $script            = '';
    public array $commands           = [];
    public array $envs               = [];
    public array $diskConfigs        = [];
    public ?Filesystem $diskOverride = null;

    protected function makeProcess(array $command, array $env): Process
    {
        $this->commands[] = $command;
        $this->envs[]     = $env;

        return parent::makeProcess([PHP_BINARY, '-r', $this->script], $env);
    }

    protected function buildDisk(array $config): Filesystem
    {
        $this->diskConfigs[] = $config;

        return $this->diskOverride ?? parent::buildDisk($config);
    }
}

/**
 * A real local disk whose reported object size or listing can be made to misbehave.
 */
class DatabaseBackupsDiskFake extends FilesystemAdapter
{
    public ?int $reportedSize       = null;
    public ?Throwable $listingError = null;

    public static function at(string $root): self
    {
        $adapter = new LocalFilesystemAdapter($root);

        return new self(new Flysystem($adapter), $adapter, ['root' => $root, 'throw' => true]);
    }

    public function size($path)
    {
        return $this->reportedSize ?? parent::size($path);
    }

    public function files($directory = null, $recursive = false)
    {
        if ($this->listingError) {
            throw $this->listingError;
        }

        return parent::files($directory, $recursive);
    }
}

class DatabaseBackupsNotificationDispatcherFake implements NotificationDispatcher
{
    public array $sent = [];
    public bool $fail  = false;

    public function send($notifiables, $notification)
    {
        if ($this->fail) {
            throw new RuntimeException('mail is down');
        }

        $this->sent[] = [$notifiables, $notification];
    }

    public function sendNow($notifiables, $notification, ?array $channels = null)
    {
        $this->send($notifiables, $notification);
    }
}

class DatabaseBackupsBusFake implements BusDispatcher
{
    public array $dispatched = [];

    public function dispatch($command)
    {
        $this->dispatched[] = $command;
    }

    public function dispatchSync($command, $handler = null)
    {
        $this->dispatch($command);
    }

    public function dispatchNow($command, $handler = null)
    {
        $this->dispatch($command);
    }

    public function hasCommandHandler($command)
    {
        return false;
    }

    public function getCommandHandler($command)
    {
        return false;
    }

    public function pipeThrough(array $pipes)
    {
        return $this;
    }

    public function map(array $map)
    {
        return $this;
    }
}

class DatabaseBackupsServiceStub extends DatabaseBackupService
{
    public array $calls = [];

    public function __construct(public array|Throwable $result = [])
    {
    }

    public function run(string $trigger = DatabaseBackup::TRIGGER_CONSOLE, ?array $connections = null, ?array $settings = null): array
    {
        $this->calls[] = [$trigger, $connections];

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

class DatabaseBackupsScheduleFake
{
    public array $events = [];

    public function command(string $command): DatabaseBackupsScheduledEventFake
    {
        return $this->events[] = new DatabaseBackupsScheduledEventFake($command);
    }
}

class DatabaseBackupsScheduledEventFake
{
    public array $calls = [];

    public function __construct(public string $command)
    {
    }

    public function __call($method, $arguments)
    {
        $this->calls[$method] = $arguments;

        return $this;
    }
}

const DATABASE_BACKUPS_DUMP_OK         = 'echo "-- MySQL dump 10.19\n", bin2hex(random_bytes(4000)), "\n-- Dump completed on 2026-10-06  0:00:01\n";';
const DATABASE_BACKUPS_DUMP_DENIED     = 'fwrite(STDERR, "mysqldump: Got error: 1045: Access denied"); exit(2);';
const DATABASE_BACKUPS_DUMP_TRUNCATED  = 'echo bin2hex(random_bytes(4000));';
const DATABASE_BACKUPS_DUMP_TINY       = 'echo "-- Dump completed\n";';
const DATABASE_BACKUPS_DUMP_SILENT_ERR = 'echo bin2hex(random_bytes(10)); fwrite(STDERR, "warning: lost connection");';

function database_backups_root(): string
{
    return sys_get_temp_dir() . '/fleetbase-database-backups-test';
}

function database_backups_rmdir(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

function database_backups_fixture(array $config = []): array
{
    EloquentModel::clearBootedModels();
    Container::setInstance(new DatabaseBackupsTestContainer());

    $root = database_backups_root();
    database_backups_rmdir($root);
    mkdir($root . '/disk', 0777, true);
    mkdir($root . '/tmp', 0777, true);

    $sqlite = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    $disks  = [
        'backups' => ['driver' => 'local', 'root' => $root . '/disk'],
        's3'      => ['driver' => 's3', 'bucket' => 'fleetbase-media', 'region' => 'ap-southeast-1'],
    ];

    $container = bind_test_container(array_merge([
        'app.name'                           => 'Fleetbase',
        'app.env'                            => 'production',
        'fleetbase.console.host'             => 'https://console.fleetbase.test',
        'database.default'                   => 'mysql',
        'database.connections.mysql'         => $sqlite,
        'database.connections.primary'       => ['driver' => 'mysql', 'host' => 'db.example.test', 'port' => 3307, 'username' => 'fleetbase', 'password' => 'secret value', 'database' => 'fleetbase'],
        'database.connections.sandbox'       => ['driver' => 'mysql', 'unix_socket' => '/run/mysqld.sock', 'username' => 'fleetbase', 'password' => 'sandbox secret', 'database' => 'fleetbase_sandbox'],
        'fleetbase.connection.db'            => 'mysql',
        'filesystems.disks'                  => $disks,
        'database-backups.enabled'           => true,
        'database-backups.frequency'         => 'daily',
        'database-backups.time'              => '02:30',
        'database-backups.day_of_week'       => 0,
        'database-backups.disk'              => 'backups',
        'database-backups.bucket'            => null,
        'database-backups.path'              => 'nightly',
        'database-backups.connections'       => ['primary', 'sandbox'],
        'database-backups.retention_days'    => 30,
        'database-backups.retention_count'   => null,
        'database-backups.min_size_bytes'    => 1024,
        'database-backups.notify_on_failure' => true,
        'database-backups.notify_emails'     => ['ops@example.test'],
        'database-backups.dump_binary'       => 'mysqldump',
        'database-backups.dump_args'         => ['--single-transaction', '--no-tablespaces'],
        'database-backups.extra_dump_args'   => ['--column-statistics=0'],
        'database-backups.timeout'           => 60,
        'database-backups.tmp_dir'           => $root . '/tmp',
    ], $config));

    $container->instance('cache', new CacheRepository(new ArrayStore()));
    $container->instance('filesystem', new FilesystemManager($container));
    $notifications = new DatabaseBackupsNotificationDispatcherFake();
    $container->instance(NotificationDispatcher::class, $notifications);
    $bus = new DatabaseBackupsBusFake();
    $container->instance(BusDispatcher::class, $bus);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($sqlite, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    (require __DIR__ . '/../../migrations/2026_10_06_000000_create_database_backups_table.php')->up();

    $validation = new ValidationFactory(new Translator(new ArrayLoader(), 'en'));
    Request::macro('validate', function (array $rules) use ($validation) {
        return $validation->make($this->all(), $rules)->validate();
    });

    return compact('container', 'capsule', 'notifications', 'bus', 'root');
}

function database_backups_record(array $attributes): DatabaseBackup
{
    return DatabaseBackup::create(array_merge([
        'connection_name' => 'primary',
        'database'        => 'fleetbase',
        'status'          => DatabaseBackup::STATUS_COMPLETED,
        'trigger'         => DatabaseBackup::TRIGGER_SCHEDULED,
        'disk'            => 'backups',
        'started_at'      => now(),
    ], $attributes));
}

function database_backups_command(DatabaseBackupService $service, array $input = []): array
{
    app()->instance(DatabaseBackupService::class, $service);

    $command = new BackupDatabase();
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $code   = $tester->execute($input);

    return [$code, $tester->getDisplay()];
}

afterEach(function () {
    Carbon::setTestNow();

    $macros = new ReflectionProperty(Request::class, 'macros');
    $macros->setAccessible(true);
    $macros->setValue(null, array_diff_key($macros->getValue(), ['validate' => true]));

    database_backups_rmdir(database_backups_root());
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

// ---------------------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------------------

test('database backup settings come from the environment until an administrator overrides them', function () {
    database_backups_fixture();

    $defaults = DatabaseBackupSettings::defaults();

    expect($defaults)->toBe([
        'enabled'           => true,
        'frequency'         => 'daily',
        'time'              => '02:30',
        'day_of_week'       => 0,
        'disk'              => 'backups',
        'bucket'            => null,
        'path'              => 'nightly',
        'connections'       => ['primary', 'sandbox'],
        'retention_days'    => 30,
        'retention_count'   => null,
        'min_size_bytes'    => 1024,
        'notify_on_failure' => true,
        'notify_emails'     => ['ops@example.test'],
    ])->and(DatabaseBackupSettings::settings())->toBe($defaults);

    $stored = DatabaseBackupSettings::store(['frequency' => 'weekly', 'day_of_week' => 3, 'retention_count' => '5']);

    expect($stored['frequency'])->toBe('weekly')
        ->and($stored['day_of_week'])->toBe(3)
        ->and($stored['retention_count'])->toBe(5)
        ->and(DatabaseBackupSettings::settings())->toBe($stored)
        ->and(DatabaseBackupSettings::reset())->toBe($defaults);
});

test('database backup settings fall back to the defaults when the settings table is unreachable', function () {
    $fixture = database_backups_fixture();
    $fixture['capsule']->getConnection('mysql')->getSchemaBuilder()->drop('settings');

    expect(DatabaseBackupSettings::settings())->toBe(DatabaseBackupSettings::defaults());
});

test('database backup settings normalize loose and invalid input', function () {
    database_backups_fixture();

    expect(DatabaseBackupSettings::normalize([
        'enabled'           => 'true',
        'frequency'         => 'fortnightly',
        'time'              => '25:00',
        'day_of_week'       => 9,
        'disk'              => '',
        'bucket'            => '  ',
        'path'              => '/backups/db/',
        'connections'       => 'primary, sandbox ,,primary',
        'retention_days'    => '0',
        'retention_count'   => 'many',
        'min_size_bytes'    => -5,
        'notify_on_failure' => '1',
        'notify_emails'     => [' ops@example.test ', ''],
    ]))->toBe([
        'enabled'           => true,
        'frequency'         => 'daily',
        'time'              => '00:00',
        'day_of_week'       => 6,
        'disk'              => 's3',
        'bucket'            => null,
        'path'              => 'backups/db',
        'connections'       => ['primary', 'sandbox'],
        'retention_days'    => null,
        'retention_count'   => null,
        'min_size_bytes'    => 0,
        'notify_on_failure' => true,
        'notify_emails'     => ['ops@example.test'],
    ])->and(DatabaseBackupSettings::normalize([])['min_size_bytes'])->toBe(1024);
});

test('database backup frequencies translate to utc cron expressions', function () {
    database_backups_fixture();
    $settings = fn (string $frequency, string $time = '14:05', int $day = 2) => ['frequency' => $frequency, 'time' => $time, 'day_of_week' => $day];

    expect(DatabaseBackupSettings::cronExpression($settings('hourly')))->toBe('5 * * * *')
        ->and(DatabaseBackupSettings::cronExpression($settings('every_six_hours')))->toBe('5 2,8,14,20 * * *')
        ->and(DatabaseBackupSettings::cronExpression($settings('every_twelve_hours')))->toBe('5 2,14 * * *')
        ->and(DatabaseBackupSettings::cronExpression($settings('daily')))->toBe('5 14 * * *')
        ->and(DatabaseBackupSettings::cronExpression($settings('weekly')))->toBe('5 14 * * 2');
});

test('database backups are scheduled only while enabled', function () {
    database_backups_fixture();

    $schedule = new DatabaseBackupsScheduleFake();
    DatabaseBackupSettings::schedule($schedule);

    expect($schedule->events)->toHaveCount(1)
        ->and($schedule->events[0]->command)->toBe('db:backup --no-interaction --trigger=scheduled')
        ->and($schedule->events[0]->calls)->toBe([
            'cron'               => ['30 2 * * *'],
            'timezone'           => ['UTC'],
            'name'               => ['database-backup'],
            'withoutOverlapping' => [240],
        ]);

    $disabled = new DatabaseBackupsScheduleFake();
    DatabaseBackupSettings::schedule($disabled, array_merge(DatabaseBackupSettings::settings(), ['enabled' => false]));

    expect($disabled->events)->toBe([]);
});

test('database backup settings list the disks and the mysql connections', function () {
    database_backups_fixture();

    expect(DatabaseBackupSettings::disks())->toBe([
        ['name' => 'backups', 'driver' => 'local'],
        ['name' => 's3', 'driver' => 's3'],
    ])->and(DatabaseBackupSettings::connections())->toBe(['primary', 'sandbox']);
});

// ---------------------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------------------

test('database backup service dumps uploads verifies and records every connection', function () {
    $fixture = database_backups_fixture();
    Carbon::setTestNow(Carbon::parse('2026-10-06 00:00:05'));

    $service         = new DatabaseBackupsServiceFake();
    $service->script = DATABASE_BACKUPS_DUMP_OK;
    $records         = $service->run(DatabaseBackup::TRIGGER_SCHEDULED);

    expect($records)->toHaveCount(2)
        ->and($records[0]->status)->toBe(DatabaseBackup::STATUS_COMPLETED)
        ->and($records[0]->path)->toBe('nightly/production_fleetbase_backup-20261006-000005.sql.gz')
        ->and($records[1]->path)->toBe('nightly/production_fleetbase_sandbox_backup-20261006-000005.sql.gz')
        ->and($records[0]->size_bytes)->toBeGreaterThan(1024)
        ->and($records[0]->trigger)->toBe('scheduled')
        ->and($records[0]->error)->toBeNull()
        ->and($records[0]->duration_ms)->toBeInt()
        ->and(DatabaseBackup::count())->toBe(2)
        ->and(file_exists($fixture['root'] . '/disk/' . $records[0]->path))->toBeTrue()
        ->and(glob($fixture['root'] . '/tmp/*'))->toBe([])
        ->and($fixture['notifications']->sent)->toBe([]);

    $sql = gzdecode(file_get_contents($fixture['root'] . '/disk/' . $records[0]->path));
    expect($sql)->toStartWith('-- MySQL dump')->toContain('-- Dump completed');

    expect($service->commands[0])->toBe(['mysqldump', '--host=db.example.test', '--port=3307', '--user=fleetbase', '--single-transaction', '--no-tablespaces', '--column-statistics=0', 'fleetbase'])
        ->and($service->commands[1])->toBe(['mysqldump', '--socket=/run/mysqld.sock', '--user=fleetbase', '--single-transaction', '--no-tablespaces', '--column-statistics=0', 'fleetbase_sandbox'])
        ->and($service->envs)->toBe([['MYSQL_PWD' => 'secret value'], ['MYSQL_PWD' => 'sandbox secret']])
        ->and($service->diskConfigs[0]['throw'])->toBeTrue();
});

test('database backup service fails loudly when the dump client fails and keeps old backups', function () {
    $fixture = database_backups_fixture();
    Carbon::setTestNow(Carbon::parse('2026-10-06 00:00:05'));
    file_put_contents($fixture['root'] . '/disk/old.txt', 'x');
    mkdir($fixture['root'] . '/disk/nightly');
    file_put_contents($fixture['root'] . '/disk/nightly/production_fleetbase_backup-20260101-000000.sql.gz', 'old');
    file_put_contents($fixture['root'] . '/disk/nightly/production_fleetbase_backup-20260102-000000.sql.gz', 'old');

    $service         = new DatabaseBackupsServiceFake();
    $service->script = DATABASE_BACKUPS_DUMP_DENIED;
    $records         = $service->run(DatabaseBackup::TRIGGER_MANUAL, ['primary']);

    expect($records)->toHaveCount(1)
        ->and($records[0]->status)->toBe(DatabaseBackup::STATUS_FAILED)
        ->and($records[0]->error)->toBe('The dump exited with code 2: mysqldump: Got error: 1045: Access denied')
        ->and($records[0]->path)->toBeNull()
        ->and($records[0]->completed_at)->not->toBeNull()
        ->and(glob($fixture['root'] . '/tmp/*'))->toBe([])
        ->and(file_exists($fixture['root'] . '/disk/nightly/production_fleetbase_backup-20260101-000000.sql.gz'))->toBeTrue()
        ->and($fixture['notifications']->sent)->toHaveCount(1);

    [$notifiable, $notification] = $fixture['notifications']->sent[0];
    expect($notifiable)->toBeInstanceOf(AnonymousNotifiable::class)
        ->and($notifiable->routes['mail'])->toBe(['ops@example.test'])
        ->and($notification)->toBeInstanceOf(DatabaseBackupFailed::class)
        ->and($notification->failures)->toBe([[
            'connection' => 'primary',
            'database'   => 'fleetbase',
            'error'      => 'The dump exited with code 2: mysqldump: Got error: 1045: Access denied',
        ]])
        ->and(collect(app('log')->entries)->pluck(1))->toContain('Database backup failed');
});

test('database backup service rejects incomplete, empty and undersized dumps', function () {
    $fixture = database_backups_fixture(['database-backups.notify_on_failure' => false]);

    $service         = new DatabaseBackupsServiceFake();
    $service->script = DATABASE_BACKUPS_DUMP_TRUNCATED;
    expect($service->run('manual', ['primary'])[0]->error)->toBe('The dump ended without its completion marker, so it is incomplete.');

    $service->script = DATABASE_BACKUPS_DUMP_SILENT_ERR;
    expect($service->run('manual', ['primary'])[0]->error)->toBe('The dump ended without its completion marker, so it is incomplete. warning: lost connection');

    $service->script = DATABASE_BACKUPS_DUMP_TINY;
    expect($service->run('manual', ['primary'])[0]->error)->toMatch('/^The compressed dump is \d+ bytes, below the 1024 byte minimum\.$/');

    $service->script = 'exit(3);';
    expect($service->run('manual', ['primary'])[0]->error)->toBe('The dump exited with code 3: no error output')
        ->and(glob($fixture['root'] . '/tmp/*'))->toBe([])
        ->and(glob($fixture['root'] . '/disk/nightly/*') ?: [])->toBe([])
        ->and($fixture['notifications']->sent)->toBe([]);
});

test('database backup service refuses connections that are not mysql or are missing', function () {
    database_backups_fixture(['database-backups.notify_emails' => []]);

    $service = new DatabaseBackupsServiceFake();
    $records = $service->run('console', ['mysql', 'nowhere']);

    expect($records[0]->error)->toBe('Connection [mysql] is not a MySQL connection.')
        ->and($records[1]->database)->toBe('nowhere')
        ->and($records[1]->error)->toBe('Connection [nowhere] is not a MySQL connection.')
        ->and($service->commands)->toBe([]);
});

test('database backup service reports an unwritable temporary directory', function () {
    $fixture = database_backups_fixture();
    file_put_contents($fixture['root'] . '/blocker', 'x');
    config(['database-backups.tmp_dir' => $fixture['root'] . '/blocker/tmp']);

    $service = new DatabaseBackupsServiceFake();

    expect(fn () => $service->dump('primary', 'x.sql.gz'))
        ->toThrow(DatabaseBackupException::class, 'Cannot create the backup directory ' . $fixture['root'] . '/blocker/tmp.');

    config(['database-backups.tmp_dir' => $fixture['root'] . '/tmp']);
    mkdir($fixture['root'] . '/tmp/x.sql.gz');

    expect(fn () => $service->dump('primary', 'x.sql.gz'))
        ->toThrow(DatabaseBackupException::class, 'Cannot write ' . $fixture['root'] . '/tmp/x.sql.gz.');
});

test('database backup service wraps a dump client that cannot start or times out', function () {
    $fixture = database_backups_fixture(['database-backups.timeout' => 1]);

    $service         = new DatabaseBackupsServiceFake();
    $service->script = 'sleep(3);';

    expect(fn () => $service->dump('primary', 'slow.sql.gz'))
        ->toThrow(DatabaseBackupException::class, 'The dump could not run: ')
        ->and(file_exists($fixture['root'] . '/tmp/slow.sql.gz'))->toBeFalse();
});

test('database backup service fails a run whose upload does not match the local dump', function () {
    database_backups_fixture(['database-backups.notify_on_failure' => false]);

    $disk               = DatabaseBackupsDiskFake::at(database_backups_root() . '/disk');
    $disk->reportedSize = 20;

    $service               = new DatabaseBackupsServiceFake();
    $service->script       = DATABASE_BACKUPS_DUMP_OK;
    $service->diskOverride = $disk;
    $record                = $service->run('manual', ['primary'])[0];

    expect($record->status)->toBe('failed')
        ->and($record->error)->toMatch('/^The uploaded backup is 20 bytes but the local dump is \d+ bytes\.$/');
});

test('database backup service fails a run whose disk is not configured', function () {
    database_backups_fixture(['database-backups.disk' => 'missing', 'database-backups.notify_on_failure' => false]);

    $service         = new DatabaseBackupsServiceFake();
    $service->script = DATABASE_BACKUPS_DUMP_OK;

    expect($service->run('manual', ['primary'])[0]->error)->toBe('Filesystem disk [missing] is not configured.');
});

test('database backup service applies the bucket override only to s3 disks', function () {
    database_backups_fixture();

    $service               = new DatabaseBackupsServiceFake();
    $service->diskOverride = DatabaseBackupsDiskFake::at(database_backups_root() . '/disk');
    $settings              = DatabaseBackupSettings::settings();

    $service->disk(array_merge($settings, ['disk' => 's3', 'bucket' => 'fleetbase-db-backups']));
    $service->disk(array_merge($settings, ['disk' => 's3', 'bucket' => null]));
    $service->disk(array_merge($settings, ['bucket' => 'ignored']));

    expect($service->diskConfigs[0]['bucket'])->toBe('fleetbase-db-backups')
        ->and($service->diskConfigs[1]['bucket'])->toBe('fleetbase-media')
        ->and($service->diskConfigs[2])->not->toHaveKey('bucket')
        ->and($service->objectPath('', 'a.sql.gz'))->toBe('a.sql.gz')
        ->and($service->objectPath('/x/y/', 'a.sql.gz'))->toBe('x/y/a.sql.gz');
});

test('database backup retention trims by age and count but always keeps each database newest', function () {
    $fixture = database_backups_fixture(['database-backups.retention_days' => 10, 'database-backups.retention_count' => 2]);
    Carbon::setTestNow(Carbon::parse('2026-10-06 00:00:00'));
    $dir = $fixture['root'] . '/disk/nightly';
    mkdir($dir);

    $files = [
        'production_fleetbase_backup-20261005-000000.sql.gz',
        'production_fleetbase_backup-20261004-000000.sql.gz',
        'production_fleetbase_backup-20261003-000000.sql.gz', // beyond the count of 2
        'production_fleetbase_sandbox_backup-20260801-000000.sql.gz', // old, but the newest of its database
        'production_fleetbase_sandbox_backup-20260701-000000.sql', // old
        'notes.txt',
    ];
    foreach ($files as $file) {
        file_put_contents($dir . '/' . $file, 'x');
    }
    database_backups_record(['path' => 'nightly/production_fleetbase_backup-20261003-000000.sql.gz']);

    $service = new DatabaseBackupsServiceFake();
    $deleted = $service->prune(DatabaseBackupSettings::settings());
    sort($deleted);

    expect($deleted)->toBe([
        'nightly/production_fleetbase_backup-20261003-000000.sql.gz',
        'nightly/production_fleetbase_sandbox_backup-20260701-000000.sql',
    ])
        ->and(array_map('basename', glob($dir . '/*')))->toBe([
            'notes.txt',
            'production_fleetbase_backup-20261004-000000.sql.gz',
            'production_fleetbase_backup-20261005-000000.sql.gz',
            'production_fleetbase_sandbox_backup-20260801-000000.sql.gz',
        ])
        ->and(DatabaseBackup::first()->pruned_at)->not->toBeNull()
        ->and($service->prune(array_merge(DatabaseBackupSettings::settings(), ['retention_days' => null, 'retention_count' => null])))->toBe([])
        ->and($service->prune(array_merge(DatabaseBackupSettings::settings(), ['retention_count' => null])))->toBe([]);
});

test('database backup runs trim after a full success and log a failed trim without failing', function () {
    $fixture = database_backups_fixture();
    Carbon::setTestNow(Carbon::parse('2026-10-06 00:00:05'));
    mkdir($fixture['root'] . '/disk/nightly');
    file_put_contents($fixture['root'] . '/disk/nightly/production_fleetbase_backup-20260101-000000.sql.gz', 'old');

    $service         = new DatabaseBackupsServiceFake();
    $service->script = DATABASE_BACKUPS_DUMP_OK;
    $service->run('scheduled', ['primary']);

    expect(file_exists($fixture['root'] . '/disk/nightly/production_fleetbase_backup-20260101-000000.sql.gz'))->toBeFalse();

    $disk                  = DatabaseBackupsDiskFake::at($fixture['root'] . '/disk');
    $disk->listingError    = new RuntimeException('listing denied');
    $service->diskOverride = $disk;

    expect($service->run('scheduled', ['primary'])[0]->status)->toBe('completed')
        ->and(collect(app('log')->entries)->last())->toBe(['warning', 'Database backup retention failed', ['error' => 'listing denied']]);
});

test('database backup service logs a failure notification it could not send', function () {
    $fixture                        = database_backups_fixture();
    $fixture['notifications']->fail = true;

    $service         = new DatabaseBackupsServiceFake();
    $service->script = DATABASE_BACKUPS_DUMP_DENIED;
    $service->run('manual', ['primary']);

    expect(collect(app('log')->entries)->last())->toBe(['error', 'Could not send the database backup failure notification', ['error' => 'mail is down']]);
});

test('database backup service never runs two backups at once', function () {
    database_backups_fixture();

    $service = new DatabaseBackupsServiceFake();
    $held    = app('cache')->lock(DatabaseBackupService::LOCK_KEY, 60);
    $held->get();

    expect(fn () => $service->run('manual'))->toThrow(DatabaseBackupException::class, 'Another database backup is already running.');

    $held->release();
    $service->script = DATABASE_BACKUPS_DUMP_OK;
    $service->run('manual', ['primary']);

    expect(app('cache')->lock(DatabaseBackupService::LOCK_KEY, 60)->get())->toBeTrue();
});

test('database backup service runs unlocked when the cache cannot lock', function () {
    database_backups_fixture();
    app()->instance('cache', new class {
        public function lock()
        {
            throw new BadMethodCallException('no locks');
        }
    });
    Facade::clearResolvedInstance('cache');

    $service         = new DatabaseBackupsServiceFake();
    $service->script = DATABASE_BACKUPS_DUMP_OK;

    expect($service->run('manual', ['primary'])[0]->status)->toBe('completed')
        ->and($service->run('manual', []))->toHaveCount(2);
});

// ---------------------------------------------------------------------------------------
// Command, job, notification, model
// ---------------------------------------------------------------------------------------

test('db backup command reports each database and exits non zero on any failure', function () {
    database_backups_fixture();

    $ok     = database_backups_record(['path' => 'nightly/a.sql.gz', 'size_bytes' => 2048, 'duration_ms' => 15]);
    $failed = database_backups_record(['database' => 'fleetbase_sandbox', 'status' => 'failed', 'error' => 'Access denied']);

    $service          = new DatabaseBackupsServiceStub([$ok]);
    [$code, $display] = database_backups_command($service, ['--trigger' => 'scheduled', '--connection' => ['primary']]);
    expect($code)->toBe(0)
        ->and($display)->toContain('Backed up fleetbase to backups:nightly/a.sql.gz (2048 bytes, 15 ms)')
        ->and($service->calls)->toBe([['scheduled', ['primary']]]);

    $service          = new DatabaseBackupsServiceStub([$ok, $failed]);
    [$code, $display] = database_backups_command($service, ['--trigger' => 'bogus']);
    expect($code)->toBe(1)
        ->and($display)->toContain('Backup of fleetbase_sandbox failed: Access denied')
        ->and($service->calls)->toBe([['console', null]]);

    [$code, $display] = database_backups_command(new DatabaseBackupsServiceStub([]));
    expect($code)->toBe(1)->and($display)->toContain('No database connections are configured for backup.');

    [$code, $display] = database_backups_command(new DatabaseBackupsServiceStub(new DatabaseBackupException('Another database backup is already running.')));
    expect($code)->toBe(1)->and($display)->toContain('Another database backup is already running.');
});

test('db backup command does nothing while disabled unless forced', function () {
    database_backups_fixture(['database-backups.enabled' => false]);

    $service          = new DatabaseBackupsServiceStub([]);
    [$code, $display] = database_backups_command($service);
    expect($code)->toBe(0)
        ->and($display)->toContain('Database backups are disabled.')
        ->and($service->calls)->toBe([]);

    $service = new DatabaseBackupsServiceStub([database_backups_record([])]);
    [$code]  = database_backups_command($service, ['--force' => true]);
    expect($code)->toBe(0)->and($service->calls)->toHaveCount(1);
});

test('run database backup job backs up as a manual run and logs a run that could not start', function () {
    database_backups_fixture();

    $job     = new RunDatabaseBackup();
    $service = new DatabaseBackupsServiceStub([]);
    $job->handle($service);

    expect($service->calls)->toBe([['manual', null]])
        ->and($job->tries)->toBe(1);

    $job->handle(new DatabaseBackupsServiceStub(new DatabaseBackupException('Another database backup is already running.')));

    expect(collect(app('log')->entries)->last())->toBe(['warning', 'Requested database backup did not run', ['error' => 'Another database backup is already running.']]);
});

test('database backup failed notification mails each failure with a link to the console', function () {
    database_backups_fixture();

    $notification = new DatabaseBackupFailed([
        database_backups_record(['status' => 'failed', 'error' => 'Access denied']),
        database_backups_record(['connection_name' => 'sandbox', 'database' => 'fleetbase_sandbox', 'status' => 'failed', 'error' => null]),
    ]);
    $mail = $notification->toMail(new AnonymousNotifiable());

    expect($notification->via(null))->toBe(['mail'])
        ->and($mail->subject)->toBe('Fleetbase database backup failed')
        ->and($mail->level)->toBe('error')
        ->and($mail->introLines)->toContain('fleetbase (primary): Access denied')
        ->and($mail->introLines)->toContain('fleetbase_sandbox (sandbox): unknown error')
        ->and($mail->actionUrl)->toContain('admin/database-backups')
        ->and($notification->toArray(null)['failures'])->toHaveCount(2);
});

test('database backup records expose the admin shape and prune after a year', function () {
    database_backups_fixture();
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00'));

    $record = database_backups_record(['path' => 'a.sql.gz', 'size_bytes' => 10, 'duration_ms' => 5, 'completed_at' => now(), 'pruned_at' => null]);
    database_backups_record(['started_at' => now()->subYears(2)]);

    expect($record->uuid)->toBeString()->toHaveLength(36)
        ->and($record->getConnectionName())->toBe('mysql')
        ->and($record->toAdminArray())->toBe([
            'id'           => $record->uuid,
            'connection'   => 'primary',
            'database'     => 'fleetbase',
            'status'       => 'completed',
            'trigger'      => 'scheduled',
            'disk'         => 'backups',
            'path'         => 'a.sql.gz',
            'size_bytes'   => 10,
            'duration_ms'  => 5,
            'error'        => null,
            'started_at'   => '2026-10-06T12:00:00+00:00',
            'completed_at' => '2026-10-06T12:00:00+00:00',
            'pruned_at'    => null,
        ])
        ->and($record->prunable()->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------
// Admin controller
// ---------------------------------------------------------------------------------------

test('database backup controller returns settings with defaults, choices and the latest runs', function () {
    database_backups_fixture();
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00'));

    $controller = new DatabaseBackupController();
    $empty      = $controller->getSettings(AdminRequest::create('/int/v1/database-backups/settings'))->getData(true);

    expect($empty['last_run'])->toBeNull()
        ->and($empty['last_success'])->toBeNull()
        ->and($empty['disks'])->toBe(DatabaseBackupSettings::disks())
        ->and($empty['connections'])->toBe(['primary', 'sandbox'])
        ->and($empty['settings'])->toBe(DatabaseBackupSettings::defaults())
        ->and($empty['defaults'])->toBe(DatabaseBackupSettings::defaults());

    $success = database_backups_record(['started_at' => now()->subDay()]);
    $failure = database_backups_record(['status' => 'failed', 'started_at' => now()]);
    $payload = $controller->getSettings(AdminRequest::create('/int/v1/database-backups/settings'))->getData(true);

    expect($payload['last_run']['id'])->toBe($failure->uuid)
        ->and($payload['last_success']['id'])->toBe($success->uuid);
});

test('database backup controller saves validated settings and resets them', function () {
    database_backups_fixture();
    $controller = new DatabaseBackupController();
    $input      = [
        'enabled'           => true,
        'frequency'         => 'every_six_hours',
        'time'              => '01:15',
        'day_of_week'       => 1,
        'disk'              => 's3',
        'bucket'            => 'fleetbase-db-backups',
        'path'              => 'mysql',
        'connections'       => ['primary'],
        'retention_days'    => 14,
        'retention_count'   => null,
        'min_size_bytes'    => 2048,
        'notify_on_failure' => true,
        'notify_emails'     => ['ops@example.test', 'cto@example.test'],
    ];

    $saved = $controller->saveSettings(AdminRequest::create('/int/v1/database-backups/settings', 'POST', $input))->getData(true);

    expect($saved['settings'])->toBe(array_merge($input, ['day_of_week' => 1]))
        ->and(DatabaseBackupSettings::settings()['frequency'])->toBe('every_six_hours');

    $reset = $controller->resetSettings(AdminRequest::create('/int/v1/database-backups/settings', 'DELETE'))->getData(true);
    expect($reset['settings'])->toBe(DatabaseBackupSettings::defaults());
});

test('database backup controller rejects invalid settings', function (array $override) {
    database_backups_fixture();
    $input = array_merge([
        'enabled'     => true,
        'frequency'   => 'daily',
        'time'        => '00:00',
        'disk'        => 'backups',
        'connections' => ['primary'],
    ], $override);

    expect(fn () => (new DatabaseBackupController())->saveSettings(AdminRequest::create('/int/v1/database-backups/settings', 'POST', $input)))
        ->toThrow(ValidationException::class);
})->with([
    'unknown frequency'  => [['frequency' => 'monthly']],
    'bad time'           => [['time' => '7pm']],
    'unknown disk'       => [['disk' => 'nowhere']],
    'non mysql database' => [['connections' => ['mysql']]],
    'no databases'       => [['connections' => []]],
    'bad email'          => [['notify_emails' => ['not-an-email']]],
    'zero retention'     => [['retention_days' => 0]],
]);

test('database backup controller lists recent runs newest first', function () {
    database_backups_fixture();
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00'));

    $older = database_backups_record(['started_at' => now()->subHours(2)]);
    $newer = database_backups_record(['started_at' => now()->subHour()]);
    database_backups_record(['started_at' => now()->subHours(3)]);

    $controller = new DatabaseBackupController();
    $all        = $controller->runs(AdminRequest::create('/int/v1/database-backups/runs'))->getData(true);
    $limited    = $controller->runs(AdminRequest::create('/int/v1/database-backups/runs', 'GET', ['limit' => 2]))->getData(true);

    expect(array_column($all['runs'], 'id'))->toHaveCount(3)
        ->and(array_column($limited['runs'], 'id'))->toBe([$newer->uuid, $older->uuid]);

    expect(fn () => $controller->runs(AdminRequest::create('/int/v1/database-backups/runs', 'GET', ['limit' => 0])))
        ->toThrow(ValidationException::class);
});

test('database backup controller queues a manual run', function () {
    $fixture = database_backups_fixture();

    $response = (new DatabaseBackupController())->run(AdminRequest::create('/int/v1/database-backups/run', 'POST'));

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getData(true))->toBe(['status' => 'queued'])
        ->and($fixture['bus']->dispatched)->toHaveCount(1)
        ->and($fixture['bus']->dispatched[0])->toBeInstanceOf(RunDatabaseBackup::class)
        ->and($fixture['bus']->dispatched[0]->trigger)->toBe('manual');
});
