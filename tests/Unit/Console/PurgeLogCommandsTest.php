<?php

use Fleetbase\Console\Commands\PurgeActivityLogs;
use Fleetbase\Console\Commands\PurgeApiLogs;
use Fleetbase\Console\Commands\PurgeScheduledTaskLogs;
use Fleetbase\Console\Commands\PurgeWebhookLogs;
use Fleetbase\Exceptions\PurgeBackupException;
use Fleetbase\Traits\ForcesCommands;
use Fleetbase\Traits\PurgeCommand;
use Illuminate\Console\Command;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;

class PurgeApiLogsTestCommand extends PurgeApiLogs
{
    public array $purges = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $query = $baseQuery->getQuery();

        $this->purges[] = [
            'table'       => $model->getTable(),
            'disk'        => $diskOption,
            'backup_path' => $backupPath,
            'wheres'      => $query->wheres ?? [],
            'bindings'    => $query->bindings['where'] ?? [],
        ];

        return 0;
    }
}

class PurgeWebhookLogsTestCommand extends PurgeWebhookLogs
{
    public array $purges = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $query = $baseQuery->getQuery();

        $this->purges[] = [
            'table'       => $model->getTable(),
            'disk'        => $diskOption,
            'backup_path' => $backupPath,
            'wheres'      => $query->wheres ?? [],
            'bindings'    => $query->bindings['where'] ?? [],
        ];

        return 0;
    }
}

class PurgeActivityLogsTestCommand extends PurgeActivityLogs
{
    public array $purges = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $query = $baseQuery->getQuery();

        $this->purges[] = [
            'table'       => $model->getTable(),
            'disk'        => $diskOption,
            'backup_path' => $backupPath,
            'wheres'      => $query->wheres ?? [],
            'bindings'    => $query->bindings['where'] ?? [],
        ];

        return 0;
    }
}

class PurgeScheduledTaskLogsTestCommand extends PurgeScheduledTaskLogs
{
    public array $purges = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $query = $baseQuery->getQuery();

        $this->purges[] = [
            'table'       => $model->getTable(),
            'disk'        => $diskOption,
            'backup_path' => $backupPath,
            'wheres'      => $query->wheres ?? [],
            'bindings'    => $query->bindings['where'] ?? [],
        ];

        return 0;
    }
}

class ForcesCommandsTestCommand extends Command
{
    use ForcesCommands;

    public array $warnings      = [];
    public array $confirmations = [];

    public function __construct(private array $options = [], private bool $confirmationResult = false)
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function warn($string, $verbosity = null): void
    {
        $this->warnings[] = $string;
    }

    public function confirm($question, $default = false): bool
    {
        $this->confirmations[] = $question;

        return $this->confirmationResult;
    }

    public function confirmForTest(string $message): bool
    {
        return $this->confirmOrForce($message);
    }
}

class PurgeCommandRecord extends Model
{
    protected $table   = 'purge_command_records';
    protected $guarded = [];
    public $timestamps = false;
}

class PurgeCommandNoKeyRecord extends Model
{
    protected $table   = 'purge_command_no_key_records';
    protected $guarded = [];
    protected $primaryKey;
    public $incrementing = false;
    public $timestamps   = false;
}

class PurgeCommandTestCommand extends Command
{
    use PurgeCommand;

    public array $infos         = [];
    public array $warnings      = [];
    public array $confirmations = [];

    public function __construct(private array $options = [], private bool $confirmationResult = true)
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function info($string, $verbosity = null): void
    {
        $this->infos[] = $string;
    }

    public function warn($string, $verbosity = null): void
    {
        $this->warnings[] = $string;
    }

    public function confirm($question, $default = false): bool
    {
        $this->confirmations[] = $question;

        return $this->confirmationResult;
    }

    public function runPurgeForTest(Builder $query, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        return $this->runPurge($query, $model, $diskOption, $backupPath);
    }

    public function writeSqlDumpForTest(string $tableName, Collection $records, string $fileName): void
    {
        $this->writeSqlDump($tableName, $records, $fileName);
    }

    public function detectPrimaryKeyForTest(string $tableName, ?Model $model = null): ?string
    {
        return $this->detectPrimaryKey($tableName, $model);
    }

    public function confirmDeleteLineForTest(string $tableName): string
    {
        return $this->confirmDeleteLine($tableName);
    }

    public function uploadBackupForTest(string $localTmp, string $disk, string $remote): void
    {
        $this->uploadBackup($localTmp, $disk, $remote);
    }

    public function pruneBackupsForTest(string $disk, string $backupPath, string $tableName, string $justUploaded): void
    {
        $this->pruneBackups($disk, $backupPath, $tableName, $justUploaded);
    }
}

/**
 * Mirrors Symfony's behavior for a command that uses the trait without declaring the
 * `keep-backups` option: asking for it throws rather than returning null.
 */
class PurgeCommandUndeclaredOptionCommand extends PurgeCommandTestCommand
{
    public function option($key = null)
    {
        throw new InvalidArgumentException(sprintf('The "--%s" option does not exist.', $key));
    }
}

function purge_command_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (glob($directory . '/*') ?: [] as $path) {
        is_dir($path) ? purge_command_remove_directory($path) : @unlink($path);
    }

    @rmdir($directory);
}

function purge_log_commands_database(bool $withCreatedAt = true): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
        'activitylog.table_name'     => 'activity_log',
        'filesystems.default'        => 'local',
        'filesystems.disks.local'    => [
            'driver' => 'local',
            'root'   => sys_get_temp_dir() . '/fleetbase-purge-command-test-' . uniqid(),
        ],
    ]);
    $container->instance('filesystem', new FilesystemManager($container));

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    foreach (['api_request_logs', 'api_events', 'webhook_request_logs', 'activity_log', 'monitored_scheduled_task_log_items'] as $tableName) {
        $schema->dropIfExists($tableName);
        $schema->create($tableName, function ($table) use ($withCreatedAt) {
            $table->string('uuid')->primary();
            if ($withCreatedAt) {
                $table->timestamp('created_at')->nullable();
            }
        });
    }
    $schema->dropIfExists('purge_command_records');
    $schema->create('purge_command_records', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('created_at')->nullable();
    });
    $schema->dropIfExists('purge_command_no_key_records');
    $schema->create('purge_command_no_key_records', function ($table) {
        $table->string('name')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    return $capsule;
}

function purge_log_cutoff(array $purge): ?string
{
    $binding = $purge['bindings'][0] ?? null;

    return $binding instanceof Carbon ? $binding->toDateTimeString() : null;
}

/**
 * Swap the 'local' disk for a decorator that delegates to the real one, except for
 * the methods overridden here. Lets a test make an upload fail the way S3 can:
 * by returning a value rather than by throwing.
 */
function purge_command_break_disk(array $overrides): void
{
    $real = Storage::disk('local');

    Storage::set('local', new class($real, $overrides) {
        public function __construct(private $real, private array $overrides)
        {
        }

        public function __call($method, $arguments)
        {
            if (isset($this->overrides[$method])) {
                return ($this->overrides[$method])($this->real, ...$arguments);
            }

            return $this->real->{$method}(...$arguments);
        }
    });
}

/**
 * Local dump files left in storage/app/tmp for the given table.
 *
 * @return array<int,string>
 */
function purge_command_temp_dumps(string $tableName = 'purge_command_records'): array
{
    return glob(storage_path("app/tmp/{$tableName}_*.sql")) ?: [];
}

function purge_command_clear_temp_dumps(string $tableName = 'purge_command_records'): void
{
    foreach (purge_command_temp_dumps($tableName) as $path) {
        @unlink($path);
    }
}

beforeEach(function () {
    purge_command_clear_temp_dumps();
});

afterEach(function () {
    Carbon::setTestNow();
    purge_command_clear_temp_dumps();
    Storage::clearResolvedInstances();
    Facade::clearResolvedInstances();
});

it('builds API request and event log purge queries with age cutoff disk and backup paths', function () {
    purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $command = new PurgeApiLogsTestCommand([
        'days' => 45,
        'disk' => 's3-archive',
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->purges)->toHaveCount(2)
        ->and($command->purges[0]['table'])->toBe('api_request_logs')
        ->and($command->purges[0]['disk'])->toBe('s3-archive')
        ->and($command->purges[0]['backup_path'])->toBe('backups/api-logs/requests')
        ->and($command->purges[0]['wheres'][0])->toMatchArray([
            'type'     => 'Basic',
            'column'   => 'created_at',
            'operator' => '<',
            'boolean'  => 'and',
        ])
        ->and(purge_log_cutoff($command->purges[0]))->toBe('2026-06-02 12:00:00')
        ->and($command->purges[1]['table'])->toBe('api_events')
        ->and($command->purges[1]['disk'])->toBe('s3-archive')
        ->and($command->purges[1]['backup_path'])->toBe('backups/api-logs/events')
        ->and($command->purges[1]['wheres'][0]['column'])->toBe('created_at')
        ->and(purge_log_cutoff($command->purges[1]))->toBe('2026-06-02 12:00:00');
});

it('uses default API log retention and avoids age filters when created_at is unavailable', function () {
    purge_log_commands_database(withCreatedAt: false);
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $command = new PurgeApiLogsTestCommand([
        'days' => null,
        'disk' => null,
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->purges)->toHaveCount(2)
        ->and($command->purges[0]['table'])->toBe('api_request_logs')
        ->and($command->purges[0]['disk'])->toBeNull()
        ->and($command->purges[0]['wheres'])->toBe([])
        ->and($command->purges[0]['bindings'])->toBe([])
        ->and($command->purges[1]['table'])->toBe('api_events')
        ->and($command->purges[1]['wheres'])->toBe([])
        ->and($command->purges[1]['bindings'])->toBe([]);
});

it('builds webhook log purge query with default retention and webhook backup path', function () {
    purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $command = new PurgeWebhookLogsTestCommand([
        'days' => 0,
        'disk' => 'local-archive',
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->purges)->toHaveCount(1)
        ->and($command->purges[0]['table'])->toBe('webhook_request_logs')
        ->and($command->purges[0]['disk'])->toBe('local-archive')
        ->and($command->purges[0]['backup_path'])->toBe('backups/webhook-logs')
        ->and($command->purges[0]['wheres'][0])->toMatchArray([
            'type'     => 'Basic',
            'column'   => 'created_at',
            'operator' => '<',
            'boolean'  => 'and',
        ])
        ->and(purge_log_cutoff($command->purges[0]))->toBe('2026-06-17 12:00:00');
});

it('builds activity and scheduled task log purge queries with dedicated backup paths', function () {
    purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $activity = new PurgeActivityLogsTestCommand([
        'days' => 14,
        'disk' => 'audit-archive',
    ]);
    $scheduled = new PurgeScheduledTaskLogsTestCommand([
        'days' => 7,
        'disk' => 'task-archive',
    ]);

    expect($activity->handle())->toBe(0)
        ->and($scheduled->handle())->toBe(0)
        ->and($activity->purges[0]['table'])->toBe('activity_log')
        ->and($activity->purges[0]['disk'])->toBe('audit-archive')
        ->and($activity->purges[0]['backup_path'])->toBe('backups/activity-logs')
        ->and(purge_log_cutoff($activity->purges[0]))->toBe('2026-07-03 12:00:00')
        ->and($scheduled->purges[0]['table'])->toBe('monitored_scheduled_task_log_items')
        ->and($scheduled->purges[0]['disk'])->toBe('task-archive')
        ->and($scheduled->purges[0]['backup_path'])->toBe('backups/scheduled-task-logs')
        ->and(purge_log_cutoff($scheduled->purges[0]))->toBe('2026-07-10 12:00:00');
});

it('forces command confirmation when force is present and otherwise prompts', function () {
    $forced = new ForcesCommandsTestCommand(['force' => true]);
    $prompt = new ForcesCommandsTestCommand(['force' => false], true);

    expect($forced->confirmForTest('Delete records?'))->toBeTrue()
        ->and($forced->warnings)->toBe(['Force flag detected: Skipping confirmation.'])
        ->and($forced->confirmations)->toBe([])
        ->and($prompt->confirmForTest('Delete records?'))->toBeTrue()
        ->and($prompt->warnings)->toBe([])
        ->and($prompt->confirmations)->toBe(['Delete records?']);
});

it('runs purge flow with backup upload deletion and empty set handling', function () {
    $capsule = purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $capsule->getConnection('mysql')->table('purge_command_records')->insert([
        ['id' => 1, 'uuid' => 'record-1', 'name' => "O'Hare", 'created_at' => '2026-06-01 00:00:00'],
        ['id' => 2, 'uuid' => 'record-2', 'name' => 'Keep', 'created_at' => '2026-07-01 00:00:00'],
    ]);

    $command = new PurgeCommandTestCommand();

    expect($command->runPurgeForTest(PurgeCommandRecord::query()->where('created_at', '<', '2026-06-15 00:00:00'), new PurgeCommandRecord(), null, 'purge-backups'))->toBe(1)
        ->and(PurgeCommandRecord::query()->pluck('name')->all())->toBe(['Keep'])
        ->and($command->confirmations)->toBe(['Do you want to permanently delete the selected records from purge_command_records?'])
        ->and($command->infos)->toContain('Backup verified and uploaded.')
        ->and($command->infos)->toContain('Purge completed. Deleted: 1');

    $diskFiles = Storage::disk('local')->allFiles('purge-backups');
    expect($diskFiles)->toHaveCount(1)
        ->and(Storage::disk('local')->get($diskFiles[0]))->toContain('INSERT INTO `purge_command_records`')
        ->and(Storage::disk('local')->get($diskFiles[0]))->toContain("'O''Hare'");

    $empty = new PurgeCommandTestCommand();
    expect($empty->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'missing'), new PurgeCommandRecord()))->toBe(0)
        ->and($empty->infos)->toBe(['No records to purge from purge_command_records.']);
});

it('writes purge sql dumps for empty sets first writes nulls quoted values and numeric values', function () {
    purge_log_commands_database();

    $command   = new PurgeCommandTestCommand();
    $directory = sys_get_temp_dir() . '/fleetbase-purge-sql-dump-' . uniqid();
    $emptyFile = "{$directory}/empty.sql";
    $dataFile  = "{$directory}/records.sql";

    mkdir($directory, 0775, true);

    $command->writeSqlDumpForTest('purge_command_records', collect(), $emptyFile);
    $command->writeSqlDumpForTest('purge_command_records', collect([
        [
            'id'   => 10,
            'uuid' => '001-leading-zero',
            'name' => null,
        ],
        [
            'id'   => 11,
            'uuid' => 'record-11',
            'name' => "O'Hare",
        ],
    ]), $dataFile);

    expect(file_get_contents($emptyFile))->toBe("-- empty set\n")
        ->and(file_get_contents($dataFile))->toContain("-- Dump of purge_command_records\n")
        ->and(file_get_contents($dataFile))->toContain('INSERT INTO `purge_command_records` (`id`, `uuid`, `name`)')
        ->and(file_get_contents($dataFile))->toContain("(10, '001-leading-zero', NULL)")
        ->and(file_get_contents($dataFile))->toContain("(11, 'record-11', 'O''Hare');");
});

it('runs purge flow skip backup and decline paths and detects primary keys', function () {
    $capsule = purge_log_commands_database();
    $capsule->getConnection('mysql')->table('purge_command_records')->insert([
        ['id' => 1, 'uuid' => 'record-1', 'name' => 'Delete', 'created_at' => '2026-06-01 00:00:00'],
        ['id' => 2, 'uuid' => 'record-2', 'name' => 'Decline', 'created_at' => '2026-06-01 00:00:00'],
    ]);
    $capsule->getConnection('mysql')->table('purge_command_no_key_records')->insert([
        ['name' => 'No key', 'created_at' => '2026-06-01 00:00:00'],
    ]);

    $skipBackup = new PurgeCommandTestCommand(['skip-backup' => true, 'force' => true]);
    $declined   = new PurgeCommandTestCommand(['skip-backup' => false, 'force' => false], false);

    expect($skipBackup->confirmDeleteLineForTest('purge_command_records'))->toBe('Permanently delete selected records from purge_command_records WITHOUT BACKUP?')
        ->and($skipBackup->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'Delete'), new PurgeCommandRecord()))->toBe(1)
        ->and($skipBackup->warnings)->toContain('Force flag detected: Skipping confirmation.')
        ->and($skipBackup->warnings)->toContain('Skipping backup as --skip-backup was provided.')
        ->and($declined->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'Decline'), new PurgeCommandRecord()))->toBe(0)
        ->and(PurgeCommandRecord::query()->where('name', 'Decline')->exists())->toBeTrue()
        ->and($declined->warnings)->toBe(['Skipped purging purge_command_records.'])
        ->and($skipBackup->detectPrimaryKeyForTest('purge_command_records'))->toBe('uuid')
        ->and($skipBackup->detectPrimaryKeyForTest('purge_command_records', new PurgeCommandRecord()))->toBe('id')
        ->and($skipBackup->detectPrimaryKeyForTest('purge_command_no_key_records', new PurgeCommandNoKeyRecord()))->toBeNull();
});

it('removes the local dump after a successful purge', function () {
    $capsule = purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $capsule->getConnection('mysql')->table('purge_command_records')->insert([
        ['id' => 1, 'uuid' => 'record-1', 'name' => 'Delete', 'created_at' => '2026-06-01 00:00:00'],
    ]);

    $command = new PurgeCommandTestCommand(['force' => true]);

    expect($command->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'Delete'), new PurgeCommandRecord(), null, 'purge-backups'))->toBe(1)
        ->and(purge_command_temp_dumps())->toBe([])
        ->and(Storage::disk('local')->allFiles('purge-backups'))->toHaveCount(1);
});

it('streams large purges to the dump one chunk at a time', function () {
    $capsule = purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $rows = [];
    for ($i = 1; $i <= 2500; $i++) {
        $rows[] = ['id' => $i, 'uuid' => "record-{$i}", 'name' => "Row {$i}", 'created_at' => '2026-06-01 00:00:00'];
    }
    foreach (array_chunk($rows, 500) as $batch) {
        $capsule->getConnection('mysql')->table('purge_command_records')->insert($batch);
    }

    $command = new PurgeCommandTestCommand(['force' => true]);

    expect($command->runPurgeForTest(PurgeCommandRecord::query()->where('created_at', '<', '2026-06-15 00:00:00'), new PurgeCommandRecord(), null, 'purge-backups'))->toBe(2500)
        ->and(PurgeCommandRecord::query()->count())->toBe(0)
        ->and(purge_command_temp_dumps())->toBe([]);

    $dump = Storage::disk('local')->get(Storage::disk('local')->allFiles('purge-backups')[0]);

    // One header, and one INSERT per 1000-row chunk rather than a single buffered write.
    expect(substr_count($dump, '-- Dump of purge_command_records'))->toBe(1)
        ->and(substr_count($dump, 'INSERT INTO `purge_command_records`'))->toBe(3)
        ->and($dump)->toContain("'Row 2500'");
});

it('aborts without deleting when the backup upload fails', function () {
    $capsule = purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $capsule->getConnection('mysql')->table('purge_command_records')->insert([
        ['id' => 1, 'uuid' => 'record-1', 'name' => 'Delete', 'created_at' => '2026-06-01 00:00:00'],
    ]);

    purge_command_break_disk(['writeStream' => fn () => false]);

    $command = new PurgeCommandTestCommand(['force' => true]);

    expect(fn () => $command->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'Delete'), new PurgeCommandRecord(), null, 'purge-backups'))
        ->toThrow(PurgeBackupException::class, 'aborting purge without deleting rows')
        ->and(PurgeCommandRecord::query()->count())->toBe(1)
        ->and(purge_command_temp_dumps())->toBe([])
        ->and($command->infos)->not->toContain('Backup verified and uploaded.');
});

it('aborts without deleting when the uploaded backup is missing', function () {
    $capsule = purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $capsule->getConnection('mysql')->table('purge_command_records')->insert([
        ['id' => 1, 'uuid' => 'record-1', 'name' => 'Delete', 'created_at' => '2026-06-01 00:00:00'],
    ]);

    purge_command_break_disk(['exists' => fn () => false]);

    $command = new PurgeCommandTestCommand(['force' => true]);

    expect(fn () => $command->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'Delete'), new PurgeCommandRecord(), null, 'purge-backups'))
        ->toThrow(PurgeBackupException::class, 'upload failed')
        ->and(PurgeCommandRecord::query()->count())->toBe(1)
        ->and(purge_command_temp_dumps())->toBe([]);
});

it('aborts without deleting when the uploaded backup is empty', function () {
    $capsule = purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $capsule->getConnection('mysql')->table('purge_command_records')->insert([
        ['id' => 1, 'uuid' => 'record-1', 'name' => 'Delete', 'created_at' => '2026-06-01 00:00:00'],
    ]);

    purge_command_break_disk(['size' => fn () => 0]);

    $command = new PurgeCommandTestCommand(['force' => true]);

    expect(fn () => $command->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'Delete'), new PurgeCommandRecord(), null, 'purge-backups'))
        ->toThrow(PurgeBackupException::class, 'is empty')
        ->and(PurgeCommandRecord::query()->count())->toBe(1)
        ->and(purge_command_temp_dumps())->toBe([]);
});

it('aborts when the local dump was never written or is empty', function () {
    purge_log_commands_database();

    $command = new PurgeCommandTestCommand();
    $missing = storage_path('app/tmp/purge_command_records_never-written.sql');
    $empty   = storage_path('app/tmp/purge_command_records_empty.sql');

    if (!is_dir(dirname($empty))) {
        mkdir(dirname($empty), 0775, true);
    }
    file_put_contents($empty, '');

    expect(fn () => $command->uploadBackupForTest($missing, 'local', 'purge-backups/missing.sql'))
        ->toThrow(PurgeBackupException::class, 'was not written locally, or is empty')
        ->and(fn () => $command->uploadBackupForTest($empty, 'local', 'purge-backups/empty.sql'))
        ->toThrow(PurgeBackupException::class, 'was not written locally, or is empty');

    @unlink($empty);
});

it('does not append to a dump left behind by an earlier run', function () {
    $capsule = purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $capsule->getConnection('mysql')->table('purge_command_records')->insert([
        ['id' => 1, 'uuid' => 'record-1', 'name' => 'Delete', 'created_at' => '2026-06-01 00:00:00'],
    ]);

    // Same table, same second: exactly what an aborted run leaves behind.
    $stale = storage_path('app/tmp/purge_command_records_2026-07-17_12-00-00.sql');
    if (!is_dir(dirname($stale))) {
        mkdir(dirname($stale), 0775, true);
    }
    file_put_contents($stale, "-- Dump of purge_command_records\nINSERT INTO `purge_command_records` (`id`) VALUES (999);\n");

    $command = new PurgeCommandTestCommand(['force' => true]);

    expect($command->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'Delete'), new PurgeCommandRecord(), null, 'purge-backups'))->toBe(1);

    $dump = Storage::disk('local')->get(Storage::disk('local')->allFiles('purge-backups')[0]);

    expect(substr_count($dump, '-- Dump of purge_command_records'))->toBe(1)
        ->and($dump)->not->toContain('(999)')
        ->and(purge_command_temp_dumps())->toBe([]);
});

it('prunes old backups down to keep-backups leaving unrelated objects alone', function () {
    $capsule = purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $capsule->getConnection('mysql')->table('purge_command_records')->insert([
        ['id' => 1, 'uuid' => 'record-1', 'name' => 'Delete', 'created_at' => '2026-06-01 00:00:00'],
    ]);

    Storage::disk('local')->put('purge-backups/purge_command_records_2026-01-01_00-00-00.sql', 'old');
    Storage::disk('local')->put('purge-backups/purge_command_records_2026-02-01_00-00-00.sql', 'older');
    Storage::disk('local')->put('purge-backups/purge_command_records_2026-03-01_00-00-00.sql', 'newest kept');
    Storage::disk('local')->put('purge-backups/other_table_2026-01-01_00-00-00.sql', 'not ours');
    Storage::disk('local')->put('purge-backups/notes.txt', 'not a dump');

    $command = new PurgeCommandTestCommand(['force' => true, 'keep-backups' => 2]);

    expect($command->runPurgeForTest(PurgeCommandRecord::query()->where('name', 'Delete'), new PurgeCommandRecord(), null, 'purge-backups'))->toBe(1)
        ->and(Storage::disk('local')->allFiles('purge-backups'))->toBe([
            'purge-backups/notes.txt',
            'purge-backups/other_table_2026-01-01_00-00-00.sql',
            'purge-backups/purge_command_records_2026-03-01_00-00-00.sql',
            'purge-backups/purge_command_records_2026-07-17_12-00-00.sql',
        ])
        ->and($command->infos)->toContain('Pruned 2 old backup(s), keeping the 2 most recent.');
});

it('leaves old backups alone when keep-backups is unset or invalid', function () {
    purge_log_commands_database();

    Storage::disk('local')->put('purge-backups/purge_command_records_2026-01-01_00-00-00.sql', 'old');
    Storage::disk('local')->put('purge-backups/purge_command_records_2026-02-01_00-00-00.sql', 'older');

    $unset    = new PurgeCommandTestCommand();
    $blank    = new PurgeCommandTestCommand(['keep-backups' => '']);
    $zero     = new PurgeCommandTestCommand(['keep-backups' => 0]);
    $uploaded = 'purge-backups/purge_command_records_2026-07-17_12-00-00.sql';

    $unset->pruneBackupsForTest('local', 'purge-backups', 'purge_command_records', $uploaded);
    $blank->pruneBackupsForTest('local', 'purge-backups', 'purge_command_records', $uploaded);
    $zero->pruneBackupsForTest('local', 'purge-backups', 'purge_command_records', $uploaded);

    expect(Storage::disk('local')->allFiles('purge-backups'))->toHaveCount(2)
        ->and($unset->infos)->toBe([]);
});

it('keeps every backup when the retention budget exceeds the dumps on disk', function () {
    purge_log_commands_database();

    Storage::disk('local')->put('purge-backups/purge_command_records_2026-01-01_00-00-00.sql', 'old');
    Storage::disk('local')->put('purge-backups/purge_command_records_2026-02-01_00-00-00.sql', 'older');

    $command = new PurgeCommandTestCommand(['keep-backups' => 5]);
    $command->pruneBackupsForTest('local', 'purge-backups', 'purge_command_records', 'purge-backups/purge_command_records_2026-07-17_12-00-00.sql');

    expect(Storage::disk('local')->allFiles('purge-backups'))->toHaveCount(2)
        ->and($command->infos)->toBe([]);
});

it('skips pruning entirely for commands that never declared the keep-backups option', function () {
    purge_log_commands_database();

    Storage::disk('local')->put('purge-backups/purge_command_records_2026-01-01_00-00-00.sql', 'old');
    Storage::disk('local')->put('purge-backups/purge_command_records_2026-02-01_00-00-00.sql', 'older');

    $command = new PurgeCommandUndeclaredOptionCommand();
    $command->pruneBackupsForTest('local', 'purge-backups', 'purge_command_records', 'purge-backups/purge_command_records_2026-07-17_12-00-00.sql');

    expect(Storage::disk('local')->allFiles('purge-backups'))->toHaveCount(2)
        ->and($command->infos)->toBe([])
        ->and($command->warnings)->toBe([]);
});

it('creates the dump directory before writing when it does not exist yet', function () {
    purge_log_commands_database();

    $directory = storage_path('app/tmp/nested/purge-dumps');
    $fileName  = $directory . '/purge_command_records_2026-07-17_12-00-00.sql';
    purge_command_remove_directory(storage_path('app/tmp/nested'));

    $command = new PurgeCommandTestCommand();
    $command->writeSqlDumpForTest('purge_command_records', collect([['id' => 1, 'name' => 'Keep']]), $fileName);

    expect(is_dir($directory))->toBeTrue()
        ->and(file_get_contents($fileName))->toContain('INSERT INTO `purge_command_records`');

    purge_command_remove_directory(storage_path('app/tmp/nested'));
});

it('warns instead of failing when pruning old backups errors', function () {
    purge_log_commands_database();

    purge_command_break_disk(['files' => fn () => throw new RuntimeException('listing denied')]);

    $command = new PurgeCommandTestCommand(['keep-backups' => 2]);
    $command->pruneBackupsForTest('local', 'purge-backups', 'purge_command_records', 'purge-backups/whatever.sql');

    expect($command->warnings)->toBe(['Could not prune old backups: listing denied']);
});

it('runs purge flow through model deletes when no primary key can be detected', function () {
    $capsule = purge_log_commands_database();
    $capsule->getConnection('mysql')->table('purge_command_no_key_records')->insert([
        ['name' => 'Delete no key', 'created_at' => '2026-06-01 00:00:00'],
        ['name' => 'Keep no key', 'created_at' => '2026-07-01 00:00:00'],
    ]);

    $command = new PurgeCommandTestCommand(['skip-backup' => true, 'force' => true]);

    expect($command->runPurgeForTest(PurgeCommandNoKeyRecord::query()->where('name', 'Delete no key'), new PurgeCommandNoKeyRecord()))->toBe(1)
        ->and(PurgeCommandNoKeyRecord::query()->pluck('name')->all())->toBe(['Keep no key'])
        ->and($command->infos)->toContain('Purge completed. Deleted: 1')
        ->and($command->warnings)->toContain('Skipping backup as --skip-backup was provided.');
});
