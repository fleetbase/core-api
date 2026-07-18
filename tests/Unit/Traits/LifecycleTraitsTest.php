<?php

use Fleetbase\Models\File;
use Fleetbase\Services\FileResolverService;
use Fleetbase\Traits\DisablesSoftDeletes;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasAliases;
use Fleetbase\Traits\HasFileResolution;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class LifecycleTraitsAliasRecord extends Model
{
    use HasAliases;

    protected $guarded = [];

    public bool $saved    = false;
    public array $updates = [];

    public function update(array $attributes = [], array $options = [])
    {
        $this->updates[] = $attributes;
        $this->fill($attributes);

        return true;
    }

    public function save(array $options = [])
    {
        $this->saved = true;

        return true;
    }
}

class LifecycleTraitsExpirableRecord extends Model
{
    use Expirable;

    protected $table   = 'verification_codes';
    protected $guarded = [];
    protected $casts   = [
        'expires_at'  => 'datetime',
        'valid_until' => 'datetime',
    ];
    public bool $saved = false;

    public function save(array $options = [])
    {
        $this->saved = true;

        return true;
    }

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }
}

class LifecycleTraitsCustomExpiryRecord extends LifecycleTraitsExpirableRecord
{
    protected static $expires_at = 'valid_until';
}

class LifecycleTraitsUncastExpirableRecord extends Model
{
    use Expirable;

    protected $table   = 'verification_codes';
    protected $guarded = [];
}

class LifecycleTraitsExpirableQueryRecord extends Model
{
    use Expirable;

    protected $connection = 'mysql';
    protected $table      = 'expiry_scope_records';
    protected $guarded    = [];
    protected $casts      = [
        'expires_at' => 'datetime',
    ];
    public $timestamps = false;
}

class LifecycleTraitsHardDeleteRecord extends Model
{
    use SoftDeletes;
    use DisablesSoftDeletes {
        DisablesSoftDeletes::performDeleteOnModel insteadof SoftDeletes;
        DisablesSoftDeletes::restore insteadof SoftDeletes;
        DisablesSoftDeletes::trashed insteadof SoftDeletes;
    }

    protected $guarded = [];

    public bool $forceDeleted = false;

    public function forceDelete()
    {
        $this->forceDeleted = true;

        return true;
    }

    public function performHardDeleteForTest(): void
    {
        $this->performDeleteOnModel();
    }
}

class LifecycleTraitsFileRecord extends Model
{
    use HasFileResolution;

    protected $table   = 'vehicles';
    protected $guarded = [];

    public bool $saved = false;

    public function save(array $options = [])
    {
        $this->saved = true;

        return true;
    }
}

class LifecycleTraitsFileResolverFake extends FileResolverService
{
    public array $calls = [];
    public ?File $file  = null;

    public function resolve($fileInput, ?string $path = null, ?string $disk = null): ?File
    {
        $this->calls[] = compact('fileInput', 'path', 'disk');

        return $this->file;
    }
}

function lifecycle_traits_expirable_database(): Capsule
{
    Model::clearBootedModels();

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
    ]);
    Facade::setFacadeApplication($container);

    $capsule = new Capsule($container);
    $capsule->addConnection($container->make('config')->get('database.connections.mysql'), 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('expiry_scope_records', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name');
        $table->timestamp('expires_at')->nullable();
    });
    $schema->create('expiry_scope_related_records', function ($table) {
        $table->string('uuid')->primary();
        $table->string('expiry_scope_record_uuid');
    });

    $capsule->getConnection('mysql')->table('expiry_scope_records')->insert([
        ['uuid' => 'active', 'name' => 'Active', 'expires_at' => '2026-07-18 13:00:00'],
        ['uuid' => 'permanent', 'name' => 'Permanent', 'expires_at' => null],
        ['uuid' => 'expired', 'name' => 'Expired', 'expires_at' => '2026-07-18 11:00:00'],
    ]);
    $capsule->getConnection('mysql')->table('expiry_scope_related_records')->insert([
        ['uuid' => 'related-active', 'expiry_scope_record_uuid' => 'active'],
    ]);

    return $capsule;
}

test('has aliases casts stores normalizes and rejects unsafe aliases', function () {
    $record = new LifecycleTraitsAliasRecord();

    $record->aliases = ['alpha', 'beta'];

    expect($record->getAttributes()['aliases'])->toBe(json_encode(['alpha', 'beta']))
        ->and($record->aliases)->toBe(['alpha', 'beta'])
        ->and($record->hasAlias('alpha'))->toBeTrue()
        ->and($record->hasAlias('ALPHA'))->toBeFalse()
        ->and($record->hasAlias('gamma'))->toBeFalse();

    expect($record->addAlias('Gamma'))->toBeTrue()
        ->and($record->updates[0])->toBe(['aliases' => ['alpha', 'beta', 'gamma']])
        ->and($record->addAlias('gamma'))->toBeFalse()
        ->and($record->addAlias('bad-alias'))->toBeFalse()
        ->and($record->addAlias('bad/alias'))->toBeFalse();
});

test('has aliases cleans duplicates falsy values and whitespace before saving', function () {
    $record          = new LifecycleTraitsAliasRecord();
    $record->aliases = [' Alpha ', 'alpha', '', '  ', ' BETA ', 'beta'];

    expect($record->cleanAliases())->toBeTrue()
        ->and($record->aliases)->toBe(['alpha', 'beta'])
        ->and($record->saved)->toBeTrue();
});

test('expirable reports expiry ttl timestamp and qualified columns', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $active = new LifecycleTraitsExpirableRecord([
        'expires_at' => Carbon::now()->addMinutes(5),
    ]);
    $expired = new LifecycleTraitsExpirableRecord([
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    expect($active->hasExpired())->toBeFalse()
        ->and($active->timeToLive())->toBe(300)
        ->and($active->expiresAtTimestamp())->toBe(Carbon::now()->addMinutes(5)->timestamp)
        ->and($active->getExpiredAtColumn())->toBe('expires_at')
        ->and($active->getQualifiedExpiredAtColumn())->toBe('verification_codes.expires_at')
        ->and($expired->hasExpired())->toBeTrue()
        ->and((new LifecycleTraitsUncastExpirableRecord(['expires_at' => 'not-a-date']))->timeToLive())->toBeFalse()
        ->and((new LifecycleTraitsUncastExpirableRecord(['expires_at' => 'not-a-date']))->hasExpired())->toBeFalse();

    Carbon::setTestNow();
});

test('expirable revives expired records using default custom and named expiry columns', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $defaultRevival = new LifecycleTraitsExpirableRecord([
        'expires_at' => Carbon::now()->subMinute(),
    ]);
    $customRevival = new LifecycleTraitsExpirableRecord([
        'expires_at' => Carbon::now()->subMinute(),
    ]);
    $notExpired = new LifecycleTraitsExpirableRecord([
        'expires_at' => Carbon::now()->addMinute(),
    ]);
    $namedColumn = new LifecycleTraitsCustomExpiryRecord([
        'valid_until' => Carbon::now()->subMinute(),
    ]);

    expect($defaultRevival->reviveExpired())->toBeTrue()
        ->and($defaultRevival->saved)->toBeTrue()
        ->and($defaultRevival->expires_at->timestamp)->toBe(Carbon::now()->addSeconds($defaultRevival->revivalTime)->timestamp)
        ->and($customRevival->reviveExpired(60))->toBeTrue()
        ->and($customRevival->expires_at->timestamp)->toBe(Carbon::now()->addSeconds(60)->timestamp)
        ->and($notExpired->reviveExpired())->toBeFalse()
        ->and($notExpired->saved)->toBeFalse()
        ->and($namedColumn->getExpiredAtColumn())->toBe('valid_until')
        ->and($namedColumn->getQualifiedExpiredAtColumn())->toBe('verification_codes.valid_until')
        ->and($namedColumn->reviveExpired(120))->toBeTrue()
        ->and($namedColumn->valid_until->timestamp)->toBe(Carbon::now()->addSeconds(120)->timestamp);

    Carbon::setTestNow();
});

test('expirable scope filters active records and exposes expiry query macros', function () {
    lifecycle_traits_expirable_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));

    $default = LifecycleTraitsExpirableQueryRecord::query()->orderBy('uuid')->pluck('uuid')->all();
    $with    = LifecycleTraitsExpirableQueryRecord::query()->withHasExpiry()->orderBy('uuid')->pluck('uuid')->all();
    $without = LifecycleTraitsExpirableQueryRecord::query()->withoutHasExpiry()->orderBy('uuid')->pluck('uuid')->all();
    $only    = LifecycleTraitsExpirableQueryRecord::query()->onlyHasExpiry()->orderBy('uuid')->pluck('uuid')->all();

    expect($default)->toBe(['active', 'permanent'])
        ->and($with)->toBe(['active', 'expired', 'permanent'])
        ->and($without)->toBe(['permanent'])
        ->and($only)->toBe(['active', 'expired']);

    Carbon::setTestNow();
});

test('expiry scope resolves qualified expiry columns for joined builders', function () {
    lifecycle_traits_expirable_database();

    $scope       = new Fleetbase\Scopes\ExpiryScope();
    $plain       = LifecycleTraitsExpirableQueryRecord::query();
    $joined      = LifecycleTraitsExpirableQueryRecord::query()->join('expiry_scope_related_records', 'expiry_scope_records.uuid', '=', 'expiry_scope_related_records.expiry_scope_record_uuid');
    $column      = new ReflectionMethod($scope, 'getExpiredAtColumn');
    $column->setAccessible(true);

    expect($column->invoke($scope, $plain))->toBe('expires_at')
        ->and($column->invoke($scope, $joined))->toBe('expiry_scope_records.expires_at');
});

test('disables soft deletes forces hard deletes and makes restore a no op', function () {
    $record = new LifecycleTraitsHardDeleteRecord();
    $scope  = LifecycleTraitsHardDeleteRecord::getGlobalScope('disablesSoftDeletes');
    $query  = $record->newQueryWithoutScopes();
    $scope($query);

    expect($record->trashed())->toBeFalse()
        ->and($record->restore())->toBe($record)
        ->and($query->removedScopes())->toContain(Illuminate\Database\Eloquent\SoftDeletingScope::class);

    $record->performHardDeleteForTest();

    expect($record->forceDeleted)->toBeTrue()
        ->and(LifecycleTraitsHardDeleteRecord::hasGlobalScope('disablesSoftDeletes'))->toBeTrue();
});

test('has file resolution resolves defaults explicit paths and save boundaries', function () {
    $container = bind_test_container();
    session()->flush();
    session(['company' => 'company-1']);

    $resolver       = new LifecycleTraitsFileResolverFake();
    $resolver->file = new File();
    $resolver->file->setRawAttributes(['uuid' => 'file-1']);
    $container->instance(FileResolverService::class, $resolver);

    $record = new LifecycleTraitsFileRecord();

    expect($record->resolveAndSetFile('photo_uuid', null))->toBeFalse()
        ->and($resolver->calls)->toBe([])
        ->and($record->resolveAndSetFile('photo_uuid', 'incoming-file'))->toBeTrue()
        ->and($record->photo_uuid)->toBe('file-1')
        ->and($resolver->calls[0])->toBe([
            'fileInput' => 'incoming-file',
            'path'      => 'uploads/company-1/vehicles',
            'disk'      => null,
        ]);

    $resolver->file = new File();
    $resolver->file->setRawAttributes(['uuid' => 'file-2']);

    expect($record->resolveSetAndSaveFile('document_uuid', 'document-file', 'custom/path', 'archive'))->toBeTrue()
        ->and($record->document_uuid)->toBe('file-2')
        ->and($record->saved)->toBeTrue()
        ->and($resolver->calls[1])->toBe([
            'fileInput' => 'document-file',
            'path'      => 'custom/path',
            'disk'      => 'archive',
        ]);

    $resolver->file = null;
    $unsaved        = new LifecycleTraitsFileRecord();

    expect($unsaved->resolveSetAndSaveFile('photo_uuid', 'missing-file'))->toBeFalse()
        ->and($unsaved->saved)->toBeFalse();
});
