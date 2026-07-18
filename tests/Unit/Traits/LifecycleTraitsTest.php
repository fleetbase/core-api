<?php

use Fleetbase\Traits\DisablesSoftDeletes;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\HasAliases;
use Fleetbase\Traits\HasFileResolution;
use Fleetbase\Models\File;
use Fleetbase\Services\FileResolverService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class LifecycleTraitsAliasRecord extends Model
{
    use HasAliases;

    protected $guarded = [];

    public bool $saved = false;
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

    protected $table = 'verification_codes';
    protected $guarded = [];
    protected $casts = [
        'expires_at' => 'datetime',
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

    protected $table = 'verification_codes';
    protected $guarded = [];
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

    protected $table = 'vehicles';
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
    public ?File $file = null;

    public function resolve($fileInput, ?string $path = null, ?string $disk = null): ?File
    {
        $this->calls[] = compact('fileInput', 'path', 'disk');

        return $this->file;
    }
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
    $record = new LifecycleTraitsAliasRecord();
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

test('disables soft deletes forces hard deletes and makes restore a no op', function () {
    $record = new LifecycleTraitsHardDeleteRecord();

    expect($record->trashed())->toBeFalse()
        ->and($record->restore())->toBe($record);

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
