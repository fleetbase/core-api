<?php

use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;

class FileModelCacheFake
{
    public array $values = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }
}

class FileModelFilesystemFake
{
    /** @var array<string, FileModelDiskFake> */
    public array $disks = [];

    public function disk(string $name): FileModelDiskFake
    {
        return $this->disks[$name] ??= new FileModelDiskFake($name);
    }
}

class FileModelDiskFake implements Filesystem
{
    public array $files         = [];
    public array $temporaryUrls = [];
    public bool $putResult      = true;

    public function __construct(private string $name)
    {
    }

    public function exists($path)
    {
        return array_key_exists($path, $this->files);
    }

    public function get($path)
    {
        return $this->files[$path] ?? '';
    }

    public function readStream($path)
    {
        return false;
    }

    public function put($path, $contents, $options = [])
    {
        if (!$this->putResult) {
            return false;
        }

        $this->files[$path] = $contents;

        return true;
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return true;
    }

    public function getVisibility($path)
    {
        return 'public';
    }

    public function setVisibility($path, $visibility)
    {
        return true;
    }

    public function prepend($path, $data)
    {
        return true;
    }

    public function append($path, $data)
    {
        return true;
    }

    public function delete($paths)
    {
        return true;
    }

    public function copy($from, $to)
    {
        return true;
    }

    public function move($from, $to)
    {
        return true;
    }

    public function size($path)
    {
        return strlen((string) ($this->files[$path] ?? ''));
    }

    public function lastModified($path)
    {
        return 0;
    }

    public function files($directory = null, $recursive = false)
    {
        return array_keys($this->files);
    }

    public function allFiles($directory = null)
    {
        return array_keys($this->files);
    }

    public function directories($directory = null, $recursive = false)
    {
        return [];
    }

    public function allDirectories($directory = null)
    {
        return [];
    }

    public function makeDirectory($path)
    {
        return true;
    }

    public function deleteDirectory($directory)
    {
        return true;
    }

    public function url($path): string
    {
        return "https://{$this->name}.example.test/" . ltrim((string) $path, '/');
    }

    public function temporaryUrl($path, $expiration, array $options = []): string
    {
        $url                             = $this->url($path) . '?temporary=1';
        $this->temporaryUrls[$path]      = $url;

        return $url;
    }
}

class FileModelDbFake
{
    public ?string $table = null;
    public array $where   = [];

    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function select(array $columns): self
    {
        return $this;
    }

    public function where(array $where): self
    {
        $this->where = $where;

        return $this;
    }

    public function first(): object
    {
        return (object) ['uuid' => 'subject-uuid-1'];
    }
}

class FileModelCreateSpy extends File
{
    public static array $created = [];

    public static function create(array $attributes = [])
    {
        static::$created[] = $attributes;

        $file = new static();
        $file->setRawAttributes($attributes, true);

        return $file;
    }
}

class FileModelNullMimeUploadedFile extends UploadedFile
{
    public function getMimeType(): ?string
    {
        return null;
    }
}

class FileModelExtensionOnlyUploadedFile extends FileModelNullMimeUploadedFile
{
    public function getClientOriginalName(): string
    {
        return 'manifest';
    }

    public function getClientOriginalExtension(): string
    {
        return 'txt';
    }
}

class FileModelSaveSpy extends File
{
    public array $updates = [];
    public int $saves     = 0;

    public function save(array $options = []): bool
    {
        $this->saves++;

        return true;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

function bind_file_model_filesystem(array $config = []): FileModelFilesystemFake
{
    $container = bind_test_container(array_merge([
        'app.env'                     => 'testing',
        'filesystems.default'         => 'testing',
        'filesystems.disks.s3.bucket' => 'fallback-bucket',
    ], $config));

    $filesystem = new FileModelFilesystemFake();
    $cache      = new FileModelCacheFake();

    $container->instance('filesystem', $filesystem);
    $container->instance('cache', $cache);
    Cache::swap($cache);
    Facade::clearResolvedInstance('filesystem');
    Storage::clearResolvedInstances();

    return $filesystem;
}

it('derives file extensions MIME types and hash names from stable metadata', function () {
    bind_test_container();

    $archive = new File();
    $archive->setRawAttributes([
        'original_filename' => 'fleetbase-backup.tar.gz',
        'content_type'      => null,
        'path'              => 'archives/2026/fleetbase-backup.tar.gz',
    ], true);

    $pdf = new File();
    $pdf->setRawAttributes([
        'original_filename' => 'invoice.unknown',
        'content_type'      => 'application/pdf',
        'path'              => 'documents/invoice.pdf',
    ], true);

    expect(File::parseExtensionFromFilename('fleetbase-backup.tar.gz'))->toBe('tar.gz')
        ->and(File::parseExtensionFromFilename('photo.JPEG'))->toBe('JPEG')
        ->and(File::getMimeTypeFromExtension('png'))->toBe('image/png')
        ->and(File::getFileMimeType('pdf'))->toBe('application/pdf')
        ->and(File::getExtensionFromMimeType('application/vnd.custom-report'))->toBe('vnd.custom-report')
        ->and($archive->getExtensionFromFilename())->toBe('tar.gz')
        ->and($archive->getExtension())->toBe('tar.gz')
        ->and($archive->hash_name)->toBe('fleetbase-backup.tar.gz')
        ->and($pdf->getExtension())->toBe('pdf')
        ->and($pdf->getMimeType('pdf'))->toBe('application/pdf')
        ->and($pdf->hash_name)->toBe('invoice.pdf');
});

it('exposes file uploader relationship metadata', function () {
    bind_test_container();

    $file = new File();

    expect($file->uploader()->getRelated())->toBeInstanceOf(User::class)
        ->and($file->uploader()->getForeignKeyName())->toBe('uploader_uuid')
        ->and($file->uploader()->getOwnerKeyName())->toBe('uuid');
});

it('resolves cached temporary urls and reads stored contents through the configured disk', function () {
    $filesystem = bind_file_model_filesystem([
        'filesystems.default' => 's3',
    ]);

    $file = new File();
    $file->setRawAttributes([
        'uuid' => 'file-1',
        'disk' => 's3',
        'path' => 'exports/report.csv',
    ], true);

    $filesystem->disk('s3')->put('exports/report.csv', 'id,total');

    expect($file->url)->toBe('https://s3.example.test/exports/report.csv?temporary=1')
        ->and($filesystem->disk('s3')->temporaryUrls)->toHaveKey('exports/report.csv')
        ->and($file->url)->toBe('https://s3.example.test/exports/report.csv?temporary=1')
        ->and($file->getContents())->toBe('id,total');

    Cache::put('file_url_file-1', 'https://cdn.example.test/cached-report.csv');

    expect($file->url)->toBe('https://cdn.example.test/cached-report.csv');
});

it('assigns uploaders subjects and file types through model mutators', function () {
    bind_test_container();

    $uploader = new User();
    $uploader->setRawAttributes(['uuid' => 'user-1'], true);

    $subject = new User();
    $subject->setRawAttributes(['uuid' => 'subject-user-1'], true);

    $file = new FileModelSaveSpy();
    $file->setRawAttributes(['uuid' => 'file-1'], true);

    expect($file->setUploader($uploader))->toBe($file)
        ->and($file->uploader_uuid)->toBe('user-1')
        ->and($file->setSubject($subject, 'avatar'))->toBe($file)
        ->and($file->subject_uuid)->toBe('subject-user-1')
        ->and($file->subject_type)->toBe(User::class)
        ->and($file->type)->toBe('avatar')
        ->and($file->setType('document'))->toBe($file)
        ->and($file->type)->toBe('document')
        ->and($file->saves)->toBe(2);

    $keyed = new FileModelSaveSpy();
    $keyed->setRawAttributes(['uuid' => 'file-2'], true);

    expect($keyed->setKey($subject, 'profile'))->toBe($keyed)
        ->and($keyed->subject_uuid)->toBe('subject-user-1')
        ->and($keyed->subject_type)->toBe(User::class)
        ->and($keyed->type)->toBe('profile');
});

it('updates file subjects from request uuid and type-only payloads', function () {
    bind_test_container();

    $file = new FileModelSaveSpy();
    $file->setRawAttributes(['uuid' => 'file-1'], true);

    $uuidRequest = Request::create('/int/v1/files/file-1', 'PATCH', [
        'subject_uuid' => 'subject-1',
        'subject_type' => 'user',
    ]);

    expect($file->setSubjectFromRequest($uuidRequest))->toBe($file)
        ->and($file->updates)->toHaveCount(2)
        ->and($file->updates[0])->toBe([
            'subject_uuid' => 'subject-1',
            'subject_type' => '\\' . User::class,
        ])
        ->and($file->updates[1])->toBe([
            'subject_type' => '\\' . User::class,
        ]);

    $typeOnlyRequest = Request::create('/int/v1/files/file-1', 'PATCH', [
        'subject_type' => User::class,
    ]);

    $file->setSubjectFromRequest($typeOnlyRequest);

    expect($file->updates)->toHaveCount(3)
        ->and($file->updates[2])->toBe([
            'subject_type' => User::class,
        ]);
});

it('updates file subjects from public subject identifiers using company scoping', function () {
    $container = bind_test_container();
    session()->flush();
    session(['company' => 'company-1']);

    $db = new FileModelDbFake();
    $container->instance('db', $db);
    Facade::clearResolvedInstance('db');

    $file = new FileModelSaveSpy();
    $file->setRawAttributes(['uuid' => 'file-1'], true);

    $request = Request::create('/int/v1/files/file-1', 'PATCH', [
        'subject_id'   => 'user_public_1',
        'subject_type' => 'user',
    ]);

    expect($file->setSubjectFromRequest($request))->toBe($file)
        ->and($db->table)->toBe('users')
        ->and($db->where)->toBe([
            'public_id'    => 'user_public_1',
            'company_uuid' => 'company-1',
        ])
        ->and($file->updates)->toHaveCount(2)
        ->and($file->updates[0])->toBe([
            'subject_uuid' => 'subject-uuid-1',
            'subject_type' => '\\' . User::class,
        ])
        ->and($file->updates[1])->toBe([
            'subject_type' => '\\' . User::class,
        ]);
});

it('falls back to uploaded file extensions when MIME detection is unavailable', function () {
    $path = tempnam(sys_get_temp_dir(), 'fleetbase-upload-');
    file_put_contents($path, 'plain text');

    $upload              = new FileModelNullMimeUploadedFile($path, 'manifest.txt', null, null, true);
    $extensionOnlyUpload = new FileModelExtensionOnlyUploadedFile($path, 'manifest', null, null, true);

    expect(File::getUploadedFileMimeType($upload))->toBe('text/plain')
        ->and(File::getUploadedFileMimeType($extensionOnlyUpload))->toBe('text/plain');
});

it('normalizes base64 upload paths and reports storage failures', function () {
    $filesystem = bind_file_model_filesystem([
        'filesystems.default' => 'uploads',
    ]);
    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-1',
    ]);
    FileModelCreateSpy::$created = [];

    $created = FileModelCreateSpy::createFromBase64(
        'data:text/plain;base64,' . base64_encode('manifest body'),
        'manifest.txt',
        'uploads/documents',
        'document',
        null,
        null,
        'uploads',
    );

    expect($created)->toBeInstanceOf(FileModelCreateSpy::class)
        ->and($filesystem->disk('uploads')->files)->toHaveKey('documents/manifest.txt')
        ->and($filesystem->disk('uploads')->get('documents/manifest.txt'))->toBe('manifest body')
        ->and(FileModelCreateSpy::$created[0])->toMatchArray([
            'company_uuid'      => 'company-1',
            'uploader_uuid'     => 'user-1',
            'disk'              => 'uploads',
            'original_filename' => 'manifest.txt',
            'content_type'      => 'text/plain',
            'path'              => 'documents/manifest.txt',
            'bucket'            => 'fallback-bucket',
            'type'              => 'document',
            'file_size'         => 13,
        ]);

    $filesystem->disk('uploads')->putResult = false;

    expect(FileModelCreateSpy::createFromBase64(
        base64_encode('failed body'),
        'failed.txt',
        'uploads/documents',
        'document',
        'text/plain',
        11,
        'uploads',
    ))->toBeFalse();
});

it('uses random filename request fallback and rejects missing storage coordinates', function () {
    bind_file_model_filesystem();

    $request  = Request::create('/int/v1/files/upload', 'POST');
    $filename = File::randomFileNameFromRequest($request, 'missing_file', 'TXT');

    $file = new File();

    expect($filename)->toEndWith('.txt')
        ->and(fn () => $file->getContents())->toThrow(InvalidArgumentException::class, 'Disk or path is not specified.');
});

it('generates random filenames with normalized lowercase extensions', function () {
    $png = File::randomFileName();
    $jpg = File::randomFileName('JPG');
    $gif = File::randomFileName('.GIF');

    expect($png)->toEndWith('.png')
        ->and($jpg)->toEndWith('.jpg')
        ->and($gif)->toEndWith('.gif')
        ->and($png)->not->toBe($jpg);
});
