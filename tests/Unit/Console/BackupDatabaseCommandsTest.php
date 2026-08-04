<?php

use Aws\Exception\MultipartUploadException;
use Aws\Multipart\UploadState;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Fleetbase\Console\Commands\BackupDatabase\MysqlS3Backup;
use Fleetbase\Console\Commands\BackupDatabase\S3BackupTrimmer;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class BackupDatabaseCommandContainer extends FleetbaseTestContainer
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}

class BackupDatabaseProcessFake
{
    public ?int $timeout = null;
    public bool $ran     = false;

    public function __construct(
        public string $command,
        private bool $successful = true,
        private string $errorOutput = '',
    ) {
    }

    public function setTimeout($timeout): void
    {
        $this->timeout = $timeout;
    }

    public function run(): void
    {
        $this->ran = true;
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }
}

class BackupDatabaseUploaderFake
{
    public bool $uploaded = false;

    public function __construct(
        public object $s3,
        public string $fileName,
        public array $options,
        private ?MultipartUploadException $exception = null,
    ) {
    }

    public function upload(): void
    {
        if ($this->exception) {
            throw $this->exception;
        }

        $this->uploaded = true;
    }
}

class BackupDatabaseTrimmerFake extends S3BackupTrimmer
{
    public bool $ran = false;

    public function __construct(int $days, string $bucket)
    {
        $this->days   = $days;
        $this->bucket = $bucket;
        $this->when   = now()->subDays($this->days)->startOfDay();
    }

    public function run(): void
    {
        $this->ran = true;
    }
}

class BackupDatabaseTestCommand extends MysqlS3Backup
{
    public array $processes                           = [];
    public array $s3Configs                           = [];
    public array $uploaders                           = [];
    public array $deletedFiles                        = [];
    public array $trimmers                            = [];
    public bool $processSuccessful                    = true;
    public string $processErrorOutput                 = '';
    public ?MultipartUploadException $uploadException = null;

    protected function makeProcess(string $command)
    {
        return $this->processes[] = new BackupDatabaseProcessFake($command, $this->processSuccessful, $this->processErrorOutput);
    }

    protected function makeS3Client(array $config)
    {
        $this->s3Configs[] = $config;

        return (object) ['config' => $config];
    }

    protected function makeMultipartUploader($s3, string $fileName, array $options)
    {
        return $this->uploaders[] = new BackupDatabaseUploaderFake($s3, $fileName, $options, $this->uploadException);
    }

    protected function deleteLocalFile(string $fileName): void
    {
        $this->deletedFiles[] = $fileName;
    }

    protected function makeBackupTrimmer($days, $bucket): S3BackupTrimmer
    {
        $trimmer          = new BackupDatabaseTrimmerFake((int) $days, $bucket);
        $this->trimmers[] = $trimmer;

        return $trimmer;
    }
}

class BackupDatabaseS3Fake
{
    public array $deletedPayloads = [];

    public function __construct(public array $contents)
    {
    }

    public function listObjects(array $payload): array
    {
        return ['Contents' => $this->contents];
    }

    public function deleteObjects(array $payload): void
    {
        $this->deletedPayloads[] = $payload;
    }
}

class BackupDatabaseTestTrimmer extends S3BackupTrimmer
{
    public function __construct(int $days, string $bucket, public BackupDatabaseS3Fake $s3)
    {
        parent::__construct($days, $bucket);
    }

    protected function makeS3Client(array $config)
    {
        return $this->s3;
    }
}

function backup_database_container(array $overrides = []): void
{
    Container::setInstance(new BackupDatabaseCommandContainer());

    bind_test_container(array_replace_recursive([
        'database.connections.mysql.host'               => 'db.example.test',
        'database.connections.mysql.port'               => 3307,
        'database.connections.mysql.username'           => 'fleetbase',
        'database.connections.mysql.password'           => 'secret value',
        'database.connections.mysql.database'           => 'fleetbase',
        'database.connections.sandbox.database'         => 'fleetbase_sandbox',
        'laravel-mysql-s3-backup.backup_dir'            => '/tmp/fleetbase-backups',
        'laravel-mysql-s3-backup.filename'              => '%s-%s.sql',
        'laravel-mysql-s3-backup.gzip'                  => true,
        'laravel-mysql-s3-backup.custom_mysqldump_args' => '--no-tablespaces',
        'laravel-mysql-s3-backup.sql_timout'            => 120,
        'laravel-mysql-s3-backup.keep_local_copy'       => false,
        'laravel-mysql-s3-backup.rolling_backup_days'   => 7,
        'laravel-mysql-s3-backup.s3'                    => [
            'bucket'  => 'fleetbase-backups',
            'folder'  => 'daily',
            'region'  => 'ap-southeast-1',
            'version' => 'latest',
        ],
    ], $overrides));

    Facade::clearResolvedInstances();
}

function backup_database_call(object $target, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target, ...$arguments);
}

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

it('creates s3 backup trimmers through the static factory', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 09:30:45'));

    $trimmer = S3BackupTrimmer::make(14, 'fleetbase-backups');

    expect($trimmer)->toBeInstanceOf(S3BackupTrimmer::class)
        ->and($trimmer->days)->toBe(14)
        ->and($trimmer->bucket)->toBe('fleetbase-backups')
        ->and($trimmer->when->toDateTimeString())->toBe('2026-07-04 00:00:00');
});

it('builds real backup helper collaborators without invoking external services', function () {
    backup_database_container();
    $command = new MysqlS3Backup();

    $process = backup_database_call($command, 'makeProcess', 'echo fleetbase');
    $s3      = backup_database_call($command, 'makeS3Client', [
        'region'      => 'ap-southeast-1',
        'version'     => 'latest',
        'credentials' => [
            'key'    => 'test-key',
            'secret' => 'test-secret',
        ],
    ]);
    $fileName = tempnam(sys_get_temp_dir(), 'fleetbase-backup-helper-');
    file_put_contents($fileName, 'sql dump');

    $uploader = backup_database_call($command, 'makeMultipartUploader', $s3, $fileName, [
        'bucket' => 'fleetbase-backups',
        'key'    => 'daily/' . basename($fileName),
    ]);
    $trimmer = backup_database_call($command, 'makeBackupTrimmer', 3, 'fleetbase-backups');

    expect($process)->toBeInstanceOf(Process::class)
        ->and($process->getCommandLine())->toBe('echo fleetbase')
        ->and($s3)->toBeInstanceOf(S3Client::class)
        ->and($uploader)->toBeInstanceOf(MultipartUploader::class)
        ->and($trimmer)->toBeInstanceOf(S3BackupTrimmer::class)
        ->and(file_exists($fileName))->toBeTrue();

    backup_database_call($command, 'deleteLocalFile', $fileName);

    expect(file_exists($fileName))->toBeFalse();
});

it('builds mysql and sandbox dump commands uploads to configured s3 folder and trims rolling backups', function () {
    backup_database_container();
    Carbon::setTestNow(Carbon::parse('2026-07-18 09:30:45'));

    $command = new BackupDatabaseTestCommand();
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_DEBUG]))->toBe(0)
        ->and($command->processes)->toHaveCount(2)
        ->and($command->processes[0]->command)->toMatch("/^mysqldump --host='db\\.example\\.test' --port='3307' --user='fleetbase' --password='secret value' --single-transaction --routines --triggers --no-tablespaces 'fleetbase' \\| gzip > '\\/tmp\\/fleetbase-backups\\/fleetbase-\\d{8}-\\d{6}\\.sql\\.gz'$/")
        ->and($command->processes[1]->command)->toMatch("/^mysqldump --host='db\\.example\\.test' --port='3307' --user='fleetbase' --password='secret value' --single-transaction --routines --triggers --no-tablespaces 'fleetbase_sandbox' \\| gzip > '\\/tmp\\/fleetbase-backups\\/fleetbase_sandbox-\\d{8}-\\d{6}\\.sql\\.gz'$/")
        ->and($command->processes[0]->timeout)->toBe(120)
        ->and($command->processes[0]->ran)->toBeTrue()
        ->and($command->s3Configs)->toHaveCount(2)
        ->and($command->uploaders)->toHaveCount(2)
        ->and($command->uploaders[0]->uploaded)->toBeTrue();

    $firstBackup  = $command->uploaders[0]->fileName;
    $secondBackup = $command->uploaders[1]->fileName;

    expect($command->uploaders[0]->options)->toBe([
        'bucket' => 'fleetbase-backups',
        'key'    => 'daily/' . basename($firstBackup),
    ])
        ->and($command->deletedFiles)->toBe([
            $firstBackup,
            $secondBackup,
        ])
        ->and($command->trimmers)->toHaveCount(2)
        ->and($command->trimmers[0]->ran)->toBeTrue()
        ->and($command->trimmers[0]->days)->toBe(7)
        ->and($command->trimmers[0]->bucket)->toBe('fleetbase-backups')
        ->and($tester->getDisplay())->toContain('Running backup for database `fleetbase`')
        ->and($tester->getDisplay())->toContain('Running command: mysqldump');
});

it('stops backup processing when a database dump fails before upload or cleanup', function () {
    backup_database_container([
        'laravel-mysql-s3-backup.gzip'                  => false,
        'laravel-mysql-s3-backup.custom_mysqldump_args' => null,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00'));

    $command                     = new BackupDatabaseTestCommand();
    $command->processSuccessful  = false;
    $command->processErrorOutput = 'mysqldump failed';
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]))->toBe(0)
        ->and($command->processes)->toHaveCount(1)
        ->and($command->processes[0]->command)->toMatch("/^mysqldump --host='db\\.example\\.test' --port='3307' --user='fleetbase' --password='secret value' --single-transaction --routines --triggers 'fleetbase' > '\\/tmp\\/fleetbase-backups\\/fleetbase-\\d{8}-\\d{6}\\.sql'$/")
        ->and($command->uploaders)->toBeEmpty()
        ->and($command->deletedFiles)->toBeEmpty()
        ->and($command->trimmers)->toBeEmpty()
        ->and($tester->getDisplay())->toContain('mysqldump failed');
});

it('reports multipart upload failures while still cleaning up local backups', function () {
    backup_database_container([
        'laravel-mysql-s3-backup.keep_local_copy'     => false,
        'laravel-mysql-s3-backup.rolling_backup_days' => null,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-07-18 11:00:00'));

    $command                  = new BackupDatabaseTestCommand();
    $command->uploadException = new MultipartUploadException(new UploadState([
        'Bucket' => 'fleetbase-backups',
        'Key'    => 'daily/fleetbase.sql.gz',
    ]));
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]))->toBe(0)
        ->and($command->processes)->toHaveCount(2)
        ->and($command->uploaders)->toHaveCount(2)
        ->and($command->uploaders[0]->uploaded)->toBeFalse()
        ->and($command->uploaders[1]->uploaded)->toBeFalse()
        ->and($command->deletedFiles)->toBe([
            $command->uploaders[0]->fileName,
            $command->uploaders[1]->fileName,
        ])
        ->and($command->trimmers)->toBeEmpty()
        ->and($tester->getDisplay())->toContain('Unable to upload "' . $command->uploaders[0]->fileName . '" backup to s3. Error: An exception occurred while performing a multipart upload')
        ->and($tester->getDisplay())->toContain('Deleting local backup file ' . $command->uploaders[0]->fileName);
});

it('trims only old backup objects inside the configured s3 folder', function () {
    backup_database_container([
        'laravel-mysql-s3-backup.s3.folder' => 'daily',
    ]);
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));

    $s3 = new BackupDatabaseS3Fake([
        ['Key' => 'daily/fleetbase-20260701-000000.sql.gz'],
        ['Key' => 'daily/fleetbase-20260717-000000.sql.gz'],
        ['Key' => 'weekly/fleetbase-20260701-000000.sql.gz'],
    ]);

    $trimmer = new BackupDatabaseTestTrimmer(7, 'fleetbase-backups', $s3);
    $trimmer->run();

    expect($trimmer->days)->toBe(7)
        ->and($trimmer->bucket)->toBe('fleetbase-backups')
        ->and($trimmer->when->toDateTimeString())->toBe('2026-07-11 00:00:00')
        ->and($s3->deletedPayloads)->toBe([[
            'Bucket' => 'fleetbase-backups',
            'Delete' => [
                'Objects' => [
                    ['Key' => 'daily/fleetbase-20260701-000000.sql.gz'],
                ],
            ],
        ]]);
});

it('does not call s3 delete when no backups are old enough to trim', function () {
    backup_database_container([
        'laravel-mysql-s3-backup.s3.folder' => null,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));

    $s3 = new BackupDatabaseS3Fake([
        ['Key' => 'fleetbase-20260717-000000.sql.gz'],
    ]);

    (new BackupDatabaseTestTrimmer(7, 'fleetbase-backups', $s3))->run();

    expect($s3->deletedPayloads)->toBeEmpty();
});
