<?php

use Fleetbase\Http\Controllers\Api\v1\FileController as PublicFileController;
use Fleetbase\Http\Controllers\Internal\v1\FileController;
use Fleetbase\Http\Requests\DownloadFileRequest as PublicDownloadFileRequest;
use Fleetbase\Http\Requests\Internal\DownloadFileRequest;
use Fleetbase\Http\Requests\Internal\UploadBase64FileRequest;
use Fleetbase\Http\Requests\Internal\UploadFileRequest;
use Fleetbase\Models\User;
use Fleetbase\Services\ImageService;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FileControllerTaggedCacheFake
{
    private array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
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

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function decrement(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) - $value;

        return $this->values[$key];
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class FileControllerResponseCacheFake
{
    public function clear(): void
    {
    }
}

class FileControllerEncodedImageFake
{
    public function __construct(private string $contents)
    {
    }

    public function toString(): string
    {
        return $this->contents;
    }
}

class FileControllerImageFake
{
    public array $operations = [];

    public function scale(int|string|null $width, int|string|null $height): self
    {
        $this->operations[] = ['scale', $width, $height];

        return $this;
    }

    public function scaleDown(int|string|null $width, int|string|null $height): self
    {
        $this->operations[] = ['scaleDown', $width, $height];

        return $this;
    }

    public function cover(int|string|null $width, int|string|null $height): self
    {
        $this->operations[] = ['cover', $width, $height];

        return $this;
    }

    public function coverDown(int|string|null $width, int|string|null $height): self
    {
        $this->operations[] = ['coverDown', $width, $height];

        return $this;
    }

    public function toFormat(string $format, int $quality): FileControllerEncodedImageFake
    {
        $this->operations[] = ['toFormat', $format, $quality];

        return new FileControllerEncodedImageFake("formatted-{$format}-{$quality}");
    }

    public function encode(int $quality = 85): FileControllerEncodedImageFake
    {
        $this->operations[] = ['encode', $quality];

        return new FileControllerEncodedImageFake("encoded-{$quality}");
    }
}

class FileControllerBase64ImageServiceFake extends ImageService
{
    public array $readPaths = [];

    public FileControllerImageFake $image;

    public function __construct()
    {
        $this->image = new FileControllerImageFake();
    }

    public function read(string $path): mixed
    {
        $this->readPaths[] = $path;

        return $this->image;
    }

    public function getPreset(string $preset): ?array
    {
        return match ($preset) {
            'thumb' => ['width' => 150, 'height' => 150],
            default => ['width' => 320, 'height' => 240],
        };
    }
}

class FileControllerFailingUploadedFile extends UploadedFile
{
    public function storeAs($path, $name = null, $options = [])
    {
        return false;
    }
}

class FileControllerFailingFilesystemManager
{
    public function disk(?string $name = null): object
    {
        return new class {
            public function put(string $path, string $contents): bool
            {
                return false;
            }
        };
    }
}

class PublicFileControllerRoute
{
    public object $controller;

    public function __construct(private string $method = 'query')
    {
        $this->controller = new class {
        };
    }

    public function getAction(?string $key = null): mixed
    {
        $action = [
            'controller' => PublicFileController::class . '@' . $this->method,
        ];

        return $key ? $action[$key] ?? null : $action;
    }

    public function getActionMethod(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return 'v1/files';
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        throw new HttpException($code, $message);
    }
}

function file_controller_fixtures(): Capsule
{
    EloquentModel::clearBootedModels();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        });
    }
    if (!Request::hasMacro('or')) {
        Request::macro('or', function (array $params = [], mixed $default = null): mixed {
            foreach ($params as $param) {
                if ($this->has($param)) {
                    return $this->input($param);
                }
            }

            return $default;
        });
    }
    Request::macro('getController', function () {
        return $this->route()?->controller;
    });

    $storageRoot = sys_get_temp_dir() . '/fleetbase-file-controller-' . uniqid();
    $connection  = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.env'                    => 'testing',
        'app.url'                    => 'http://fleetbase.test',
        'activitylog.table_name'     => 'activity_log',
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'filesystems.default'        => 'testing',
        'filesystems.disks.testing'  => [
            'driver' => 'local',
            'root'   => $storageRoot,
            'url'    => 'http://fleetbase.test/storage',
        ],
        'filesystems.disks.uploads' => [
            'driver' => 'local',
            'root'   => $storageRoot . '/uploads-disk',
            'url'    => 'http://fleetbase.test/uploads',
        ],
        'filesystems.disks.archive' => [
            'driver' => 'local',
            'root'   => $storageRoot . '/archive',
            'url'    => 'http://fleetbase.test/archive',
        ],
        'filesystems.disks.s3.bucket' => 'fallback-bucket',
        'fleetbase.connection.db'     => 'mysql',
    ]);

    $filesystem = new FilesystemManager($container);
    $container->instance('filesystem', $filesystem);
    $container->instance(FilesystemFactory::class, $filesystem);
    $container->instance('cache', new FileControllerTaggedCacheFake());
    $container->instance('responsecache', new FileControllerResponseCacheFake());
    $container->instance(ConfigRepositoryContract::class, $container->make('config'));
    Facade::clearResolvedInstances();

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-1',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstance('db.schema');
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable()->unique();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('uploader_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('disk')->nullable();
        $table->longText('path')->nullable();
        $table->string('bucket')->nullable();
        $table->string('folder')->nullable();
        $table->text('meta')->nullable();
        $table->string('etag')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('extension')->nullable();
        $table->string('type')->nullable();
        $table->string('content_type')->nullable();
        $table->integer('file_size')->nullable();
        $table->string('slug')->nullable();
        $table->string('caption')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    $schema->create('activity_log', function ($table) {
        $table->increments('id');
        $table->string('log_name')->nullable();
        $table->text('description')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->string('event')->nullable();
        $table->text('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
    $schema->create('directives', function ($table) {
        $table->string('uuid')->primary();
        $table->string('permission_uuid')->nullable()->index();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('key')->nullable();
        $table->string('operator')->nullable();
        $table->string('value')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $capsule->getConnection('mysql')->table('users')->insert([
        'uuid'         => 'user-1',
        'public_id'    => 'user_1',
        'company_uuid' => 'company-1',
        'name'         => 'Uploader User',
        'email'        => 'uploader@example.test',
        'created_at'   => '2026-07-18 00:00:00',
        'updated_at'   => '2026-07-18 00:00:00',
    ]);

    return $capsule;
}

function file_controller(): FileController
{
    return new FileController(new class extends ImageService {
        public function __construct()
        {
        }
    });
}

function file_controller_with_image_service(ImageService $imageService): FileController
{
    return new FileController($imageService);
}

function public_file_controller(): PublicFileController
{
    return new PublicFileController();
}

function public_file_controller_payload($resource): array
{
    return $resource->resolve(Request::create('/v1/files', 'GET'));
}

function public_file_controller_upload_request(array $input = [], string $contents = 'uploaded body'): UploadFileRequest
{
    $path = tempnam(sys_get_temp_dir(), 'fleetbase-upload-');
    file_put_contents($path, $contents);
    $file = new UploadedFile($path, 'manifest.txt', 'text/plain', null, true);

    return UploadFileRequest::create('/v1/files', 'POST', $input, [], ['file' => $file]);
}

function public_file_controller_failing_upload_request(array $input = []): UploadFileRequest
{
    $path = tempnam(sys_get_temp_dir(), 'fleetbase-upload-');
    file_put_contents($path, 'uploaded body');
    $file = new FileControllerFailingUploadedFile($path, 'manifest.txt', 'text/plain', null, true);

    return UploadFileRequest::create('/v1/files', 'POST', $input, [], ['file' => $file]);
}

function file_controller_upload_request(array $input = [], string $contents = 'uploaded body'): UploadFileRequest
{
    $path = tempnam(sys_get_temp_dir(), 'fleetbase-upload-');
    file_put_contents($path, $contents);
    $file = new UploadedFile($path, 'manifest.txt', 'text/plain', null, true);

    return UploadFileRequest::create('/int/v1/files', 'POST', $input, [], ['file' => $file]);
}

function public_file_controller_upload_base64_request(array $input = []): UploadBase64FileRequest
{
    return UploadBase64FileRequest::create('/v1/files/upload-base64', 'POST', $input);
}

function public_file_controller_download_request(array $query = []): PublicDownloadFileRequest
{
    // The public route validates with the public request class: it accepts a public_id,
    // where the internal one requires a uuid.
    return PublicDownloadFileRequest::create('/v1/files/download', 'GET', $query);
}

function public_file_controller_query_request(array $query = []): Request
{
    $request = Request::create('/v1/files', 'GET', $query);
    $request->setRouteResolver(fn () => new PublicFileControllerRoute());

    return $request;
}

function file_controller_upload_base64_request(array $input = []): UploadBase64FileRequest
{
    return UploadBase64FileRequest::create('/int/v1/files/upload-base64', 'POST', $input);
}

function file_controller_download_request(array $query = []): DownloadFileRequest
{
    return DownloadFileRequest::create('/int/v1/files/download', 'GET', $query);
}

afterEach(function () {
    session()->flush();
    config([
        'activitylog.table_name' => 'activities',
        'filesystems.default'    => null,
        'filesystems.disks'      => [],
    ]);
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('file controller uploads base64 data with session ownership storage and subject metadata', function () {
    $capsule = file_controller_fixtures();

    $response = file_controller()->uploadBase64(file_controller_upload_base64_request([
        'data'         => base64_encode('plain text body'),
        'path'         => 'uploads/documents',
        'file_name'    => 'manifest.txt',
        'file_type'    => 'document',
        'content_type' => 'text/plain',
        'subject_uuid' => 'user-subject',
        'subject_type' => User::class,
    ]));

    $payload = $response->getData(true);
    $record  = $capsule->getConnection('mysql')->table('files')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['file']['company_uuid'])->toBe('company-1')
        ->and($payload['file']['uploader_uuid'])->toBe('user-1')
        ->and($payload['file']['disk'])->toBe('testing')
        ->and($payload['file']['bucket'])->toBe('fallback-bucket')
        ->and($payload['file']['path'])->toBe('uploads/documents/manifest.txt')
        ->and($payload['file']['original_filename'])->toBe('manifest.txt')
        ->and($payload['file']['type'])->toBe('document')
        ->and($payload['file']['content_type'])->toBe('text/plain')
        ->and($payload['file']['file_size'])->toBe(strlen('plain text body'))
        ->and($record->subject_uuid)->toBe('user-subject')
        ->and($record->subject_type)->toBe(User::class)
        ->and(Storage::disk('testing')->get('uploads/documents/manifest.txt'))->toBe('plain text body');
});

test('file controller uploads multipart files with generated storage path and subject metadata', function () {
    $capsule = file_controller_fixtures();

    $response = file_controller()->upload(file_controller_upload_request([
        'path'         => 'uploads/documents',
        'type'         => 'document',
        'file_size'    => 13,
        'subject_uuid' => 'user-subject',
        'subject_type' => User::class,
    ], 'uploaded body'));

    $payload = $response->getData(true);
    $record  = $capsule->getConnection('mysql')->table('files')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['file']['company_uuid'])->toBe('company-1')
        ->and($payload['file']['uploader_uuid'])->toBe('user-1')
        ->and($payload['file']['disk'])->toBe('testing')
        ->and($payload['file']['bucket'])->toBe('fallback-bucket')
        ->and($payload['file']['path'])->toStartWith('uploads/documents/')
        ->and($payload['file']['path'])->toEndWith('.txt')
        ->and($payload['file']['original_filename'])->toBe('manifest.txt')
        ->and($payload['file']['type'])->toBe('document')
        ->and($payload['file']['content_type'])->toBe('text/plain')
        ->and($payload['file']['file_size'])->toBe(13)
        ->and($record->subject_uuid)->toBe('user-subject')
        ->and($record->subject_type)->toBe(User::class)
        ->and(Storage::disk('testing')->get($record->path))->toBe('uploaded body');
});

test('file controller upload reports storage failures before creating records', function () {
    $capsule = file_controller_fixtures();

    $response = file_controller()->upload(file_controller_upload_request([
        'disk'      => 'missing-disk',
        'path'      => 'uploads/documents',
        'type'      => 'document',
        'file_size' => 13,
    ], 'uploaded body'));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['errors'][0])->toContain('Disk [missing-disk] does not have a configured driver')
        ->and($capsule->getConnection('mysql')->table('files')->count())->toBe(0);

    $falseStorageFailure = file_controller()->upload(public_file_controller_failing_upload_request([
        'path' => 'uploads/documents',
        'type' => 'document',
    ]));

    expect($falseStorageFailure->getStatusCode())->toBe(400)
        ->and($falseStorageFailure->getData(true))->toBe(['errors' => ['File upload failed.']])
        ->and($capsule->getConnection('mysql')->table('files')->count())->toBe(0);
});

test('file controller upload reports record creation failures after multipart storage succeeds', function () {
    $capsule = file_controller_fixtures();
    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('files');

    $response = file_controller()->upload(file_controller_upload_request([
        'path' => 'uploads/documents',
        'type' => 'document',
    ], 'uploaded body'));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['errors'][0])->toContain('no such table: files');
});

test('file controller uploads resized multipart files with preset metadata', function () {
    $capsule = file_controller_fixtures();
    $service = new class extends ImageService {
        public array $presetCall = [];

        public function __construct()
        {
        }

        public function isImage(UploadedFile $file): bool
        {
            return true;
        }

        public function resizePreset(UploadedFile $file, string $preset, string $mode = 'fit', ?int $quality = null, ?bool $allowUpscale = null): string
        {
            $this->presetCall = compact('preset', 'mode', 'quality', 'allowUpscale');

            return 'resized preset body';
        }
    };

    $response = file_controller_with_image_service($service)->upload(file_controller_upload_request([
        'path'             => 'uploads/images',
        'type'             => 'avatar',
        'resize'           => 'sm',
        'resize_mode'      => 'crop',
        'resize_quality'   => 72,
        'resize_upscale'   => 'true',
        'resize_width'     => 100,
        'resize_height'    => 80,
        'subject_uuid'     => 'user-subject',
        'subject_type'     => User::class,
    ]));

    $payload = $response->getData(true);
    $record  = $capsule->getConnection('mysql')->table('files')->first();
    $meta    = json_decode($record->meta, true);

    expect($response->getStatusCode())->toBe(200)
        ->and($service->presetCall)->toBe([
            'preset'       => 'sm',
            'mode'         => 'crop',
            'quality'      => 72,
            'allowUpscale' => true,
        ])
        ->and($payload['file']['path'])->toStartWith('uploads/images/')
        ->and($payload['file']['file_size'])->toBe(strlen('resized preset body'))
        ->and($payload['file']['type'])->toBe('avatar')
        ->and($record->subject_uuid)->toBe('user-subject')
        ->and($meta['resized'])->toBeTrue()
        ->and($meta['resize_params'])->toMatchArray([
            'preset'  => 'sm',
            'width'   => 100,
            'height'  => 80,
            'mode'    => 'crop',
            'quality' => 72,
            'upscale' => true,
        ])
        ->and(Storage::disk('testing')->get($record->path))->toBe('resized preset body');
});

test('file controller uploads resized multipart files with explicit dimensions and format rewrite', function () {
    $capsule = file_controller_fixtures();
    $service = new class extends ImageService {
        public array $resizeCall = [];

        public function __construct()
        {
        }

        public function isImage(UploadedFile $file): bool
        {
            return true;
        }

        public function resize(UploadedFile $file, ?int $width = null, ?int $height = null, string $mode = 'fit', ?int $quality = null, ?string $format = null, ?bool $allowUpscale = null): string
        {
            $this->resizeCall = compact('width', 'height', 'mode', 'quality', 'format', 'allowUpscale');

            return 'resized explicit body';
        }
    };

    $response = file_controller_with_image_service($service)->upload(file_controller_upload_request([
        'path'             => 'uploads/images',
        'resize_width'     => 120,
        'resize_height'    => 90,
        'resize_mode'      => 'fit',
        'resize_quality'   => 81,
        'resize_format'    => 'webp',
        'resize_upscale'   => 'false',
    ]));

    $payload = $response->getData(true);
    $record  = $capsule->getConnection('mysql')->table('files')->first();
    $meta    = json_decode($record->meta, true);

    expect($response->getStatusCode())->toBe(200)
        ->and($service->resizeCall)->toBe([
            'width'        => 120,
            'height'       => 90,
            'mode'         => 'fit',
            'quality'      => 81,
            'format'       => 'webp',
            'allowUpscale' => false,
        ])
        ->and($payload['file']['path'])->toStartWith('uploads/images/')
        ->and($payload['file']['path'])->toEndWith('.webp')
        ->and($payload['file']['file_size'])->toBe(strlen('resized explicit body'))
        ->and($meta['resized'])->toBeTrue()
        ->and($meta['resize_params']['format'])->toBe('webp')
        ->and(Storage::disk('testing')->get($record->path))->toBe('resized explicit body');
});

test('file controller returns stable errors when multipart image resizing fails', function () {
    $capsule = file_controller_fixtures();
    $service = new class extends ImageService {
        public function __construct()
        {
        }

        public function isImage(UploadedFile $file): bool
        {
            return true;
        }

        public function resize(UploadedFile $file, ?int $width = null, ?int $height = null, string $mode = 'fit', ?int $quality = null, ?string $format = null, ?bool $allowUpscale = null): string
        {
            throw new RuntimeException('resize unavailable');
        }
    };

    $response = file_controller_with_image_service($service)->upload(file_controller_upload_request([
        'path'          => 'uploads/images',
        'resize_width'  => 120,
        'resize_height' => 90,
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['Image resize failed: resize unavailable']])
        ->and($capsule->getConnection('mysql')->table('files')->count())->toBe(0);
});

test('file controller reports resized multipart storage false before creating records', function () {
    $capsule = file_controller_fixtures();
    $service = new class extends ImageService {
        public function __construct()
        {
        }

        public function isImage(UploadedFile $file): bool
        {
            return true;
        }

        public function resize(UploadedFile $file, ?int $width = null, ?int $height = null, string $mode = 'fit', ?int $quality = null, ?string $format = null, ?bool $allowUpscale = null): string
        {
            return 'resized body';
        }
    };

    app()->instance('filesystem', new FileControllerFailingFilesystemManager());
    Facade::clearResolvedInstance('filesystem');

    $response = file_controller_with_image_service($service)->upload(file_controller_upload_request([
        'path'          => 'uploads/images',
        'resize_width'  => 120,
        'resize_height' => 90,
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['Failed to upload resized image.']])
        ->and($capsule->getConnection('mysql')->table('files')->count())->toBe(0);
});

test('file controller upload base64 reports missing data and storage failures consistently', function () {
    file_controller_fixtures();

    $missing = file_controller()->uploadBase64(file_controller_upload_base64_request([
        'path'      => 'uploads/documents',
        'file_name' => 'missing.txt',
        'file_type' => 'document',
    ]));

    expect($missing->getStatusCode())->toBe(400)
        ->and($missing->getData(true))->toBe([
            'errors' => ['Oops! Looks like no data was provided for upload.'],
        ]);

    $failure = file_controller()->uploadBase64(file_controller_upload_base64_request([
        'data'      => base64_encode('body'),
        'disk'      => 'missing-disk',
        'path'      => 'uploads/documents',
        'file_name' => 'failed.txt',
    ]));

    expect($failure->getStatusCode())->toBe(400)
        ->and($failure->getData(true)['errors'][0])->toContain('Disk [missing-disk] does not have a configured driver');

    app()->instance('filesystem', new FileControllerFailingFilesystemManager());
    Facade::clearResolvedInstance('filesystem');

    $falseStorageFailure = file_controller()->uploadBase64(file_controller_upload_base64_request([
        'data'      => base64_encode('body'),
        'path'      => 'uploads/documents',
        'file_name' => 'failed.txt',
    ]));

    expect($falseStorageFailure->getStatusCode())->toBe(400)
        ->and($falseStorageFailure->getData(true))->toBe(['errors' => ['File upload failed.']]);
});

test('file controller upload base64 resizes preset images and normalizes uploads disk paths', function () {
    $capsule = file_controller_fixtures();
    $service = new FileControllerBase64ImageServiceFake();

    $response = file_controller_with_image_service($service)->uploadBase64(file_controller_upload_base64_request([
        'data'             => base64_encode('raw image body'),
        'disk'             => 'uploads',
        'path'             => 'uploads/avatars',
        'file_name'        => 'avatar.png',
        'file_type'        => 'avatar',
        'content_type'     => 'image/png',
        'resize'           => 'thumb',
        'resize_quality'   => 64,
        'resize_format'    => 'webp',
        'resize_upscale'   => 'true',
        'subject_uuid'     => 'user-subject',
        'subject_type'     => User::class,
    ]));

    $payload = $response->getData(true);
    $record  = $capsule->getConnection('mysql')->table('files')->first();
    $meta    = json_decode($record->meta, true);

    expect($response->getStatusCode())->toBe(200)
        ->and($service->readPaths)->toHaveCount(1)
        ->and(file_exists($service->readPaths[0]))->toBeFalse()
        ->and($service->image->operations)->toBe([
            ['scale', 150, 150],
            ['toFormat', 'webp', 64],
        ])
        ->and($payload['file']['path'])->toBe('avatars/avatar.webp')
        ->and($payload['file']['disk'])->toBe('uploads')
        ->and($payload['file']['file_size'])->toBe(strlen('formatted-webp-64'))
        ->and($record->subject_uuid)->toBe('user-subject')
        ->and($record->subject_type)->toBe(User::class)
        ->and($meta['resized'])->toBeTrue()
        ->and($meta['resize_params'])->toMatchArray([
            'preset'  => 'thumb',
            'mode'    => 'fit',
            'quality' => 64,
            'format'  => 'webp',
            'upscale' => true,
        ])
        ->and(Storage::disk('uploads')->get('avatars/avatar.webp'))->toBe('formatted-webp-64');
});

test('file controller upload base64 resizes explicit crop images without upscaling', function () {
    $capsule = file_controller_fixtures();
    $service = new FileControllerBase64ImageServiceFake();

    $response = file_controller_with_image_service($service)->uploadBase64(file_controller_upload_base64_request([
        'data'           => base64_encode('raw image body'),
        'path'           => 'uploads/images',
        'file_name'      => 'cover.png',
        'content_type'   => 'image/png',
        'resize_width'   => 320,
        'resize_height'  => 180,
        'resize_mode'    => 'crop',
        'resize_upscale' => 'false',
    ]));

    $payload = $response->getData(true);
    $record  = $capsule->getConnection('mysql')->table('files')->first();
    $meta    = json_decode($record->meta, true);

    expect($response->getStatusCode())->toBe(200)
        ->and($service->image->operations)->toBe([
            ['coverDown', 320, 180],
            ['encode', 85],
        ])
        ->and($payload['file']['path'])->toBe('uploads/images/cover.png')
        ->and($payload['file']['file_size'])->toBe(strlen('encoded-85'))
        ->and($meta['resize_params'])->toMatchArray([
            'preset'  => null,
            'width'   => 320,
            'height'  => 180,
            'mode'    => 'crop',
            'quality' => null,
            'format'  => null,
            'upscale' => false,
        ])
        ->and(Storage::disk('testing')->get('uploads/images/cover.png'))->toBe('encoded-85')
        ->and($record->original_filename)->toBe('cover.png');
});

test('file controller upload base64 resizes explicit fit images without upscaling', function () {
    file_controller_fixtures();
    $service = new FileControllerBase64ImageServiceFake();

    $response = file_controller_with_image_service($service)->uploadBase64(file_controller_upload_base64_request([
        'data'          => base64_encode('raw image body'),
        'path'          => 'uploads/images',
        'file_name'     => 'fit.png',
        'content_type'  => 'image/png',
        'resize_width'  => 240,
        'resize_height' => 160,
    ]));

    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($service->image->operations)->toBe([
            ['scaleDown', 240, 160],
            ['encode', 85],
        ])
        ->and($payload['file']['path'])->toBe('uploads/images/fit.png')
        ->and(Storage::disk('testing')->get('uploads/images/fit.png'))->toBe('encoded-85');
});

test('file controller upload base64 covers remaining resize operation branches', function () {
    file_controller_fixtures();

    $presetWithoutUpscale = new FileControllerBase64ImageServiceFake();
    $presetResponse       = file_controller_with_image_service($presetWithoutUpscale)->uploadBase64(file_controller_upload_base64_request([
        'data'           => base64_encode('raw image body'),
        'path'           => 'uploads/images',
        'file_name'      => 'preset.png',
        'content_type'   => 'image/png',
        'resize'         => 'thumb',
        'resize_upscale' => 'false',
    ]));

    $cropWithUpscale = new FileControllerBase64ImageServiceFake();
    $cropResponse    = file_controller_with_image_service($cropWithUpscale)->uploadBase64(file_controller_upload_base64_request([
        'data'           => base64_encode('raw image body'),
        'path'           => 'uploads/images',
        'file_name'      => 'crop-upscale.png',
        'content_type'   => 'image/png',
        'resize_width'   => 320,
        'resize_height'  => 180,
        'resize_mode'    => 'crop',
        'resize_upscale' => 'true',
    ]));

    $fitWithUpscale = new FileControllerBase64ImageServiceFake();
    $fitResponse    = file_controller_with_image_service($fitWithUpscale)->uploadBase64(file_controller_upload_base64_request([
        'data'           => base64_encode('raw image body'),
        'path'           => 'uploads/images',
        'file_name'      => 'fit-upscale.png',
        'content_type'   => 'image/png',
        'resize_width'   => 240,
        'resize_height'  => 160,
        'resize_upscale' => 'true',
    ]));

    expect($presetResponse->getStatusCode())->toBe(200)
        ->and($presetWithoutUpscale->image->operations)->toBe([
            ['scaleDown', 150, 150],
            ['encode', 85],
        ])
        ->and($cropResponse->getStatusCode())->toBe(200)
        ->and($cropWithUpscale->image->operations)->toBe([
            ['cover', 320, 180],
            ['encode', 85],
        ])
        ->and($fitResponse->getStatusCode())->toBe(200)
        ->and($fitWithUpscale->image->operations)->toBe([
            ['scale', 240, 160],
            ['encode', 85],
        ]);
});

test('file controller upload base64 reports resize and record creation failures', function () {
    $capsule = file_controller_fixtures();
    $service = new class extends FileControllerBase64ImageServiceFake {
        public function read(string $path): mixed
        {
            throw new RuntimeException('base64 resize unavailable');
        }
    };

    $resizeFailure = file_controller_with_image_service($service)->uploadBase64(file_controller_upload_base64_request([
        'data'          => base64_encode('raw image body'),
        'path'          => 'uploads/images',
        'file_name'     => 'avatar.png',
        'content_type'  => 'image/png',
        'resize_width'  => 100,
        'resize_height' => 100,
    ]));

    expect($resizeFailure->getStatusCode())->toBe(400)
        ->and($resizeFailure->getData(true))->toBe(['errors' => ['Image resize failed: base64 resize unavailable']])
        ->and($capsule->getConnection('mysql')->table('files')->count())->toBe(0);

    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('files');

    $recordFailure = file_controller()->uploadBase64(file_controller_upload_base64_request([
        'data'      => base64_encode('body'),
        'path'      => 'uploads/documents',
        'file_name' => 'failed.txt',
    ]));

    expect($recordFailure->getStatusCode())->toBe(400)
        ->and($recordFailure->getData(true)['errors'][0])->toContain('no such table: files');
});

test('file controller download resolves query and route ids inside active company using stored disk', function () {
    $capsule = file_controller_fixtures();

    Storage::disk('archive')->put('exports/report.csv', 'a,b');
    $capsule->getConnection('mysql')->table('files')->insert([
        [
            'uuid'              => '11111111-1111-4111-8111-111111111111',
            'public_id'         => 'file_1111111111',
            'company_uuid'      => 'company-1',
            'uploader_uuid'     => 'user-1',
            'disk'              => 'archive',
            'path'              => 'exports/report.csv',
            'bucket'            => 'archive-bucket',
            'original_filename' => 'monthly-report.csv',
            'content_type'      => 'text/csv',
            'file_size'         => 3,
            'created_at'        => '2026-07-18 00:00:00',
            'updated_at'        => '2026-07-18 00:00:00',
        ],
        [
            'uuid'              => '22222222-2222-4222-8222-222222222222',
            'public_id'         => 'file_2222222222',
            'company_uuid'      => 'company-2',
            'uploader_uuid'     => 'user-2',
            'disk'              => 'testing',
            'path'              => 'exports/foreign.csv',
            'bucket'            => 'testing-bucket',
            'original_filename' => 'foreign.csv',
            'content_type'      => 'text/csv',
            'file_size'         => 7,
            'created_at'        => '2026-07-18 00:00:00',
            'updated_at'        => '2026-07-18 00:00:00',
        ],
    ]);

    $queryDownload = file_controller()->download(file_controller_download_request([
        'file' => '11111111-1111-4111-8111-111111111111',
    ]));
    $routeDownload = file_controller()->download(file_controller_download_request(), '11111111-1111-4111-8111-111111111111');

    expect($queryDownload->getStatusCode())->toBe(200)
        ->and($queryDownload->headers->get('content-disposition'))->toContain('monthly-report.csv')
        ->and($routeDownload->getStatusCode())->toBe(200)
        ->and($routeDownload->headers->get('content-disposition'))->toContain('monthly-report.csv');

    file_controller()->download(file_controller_download_request([
        'file' => '22222222-2222-4222-8222-222222222222',
    ]));
})->throws(Illuminate\Database\Eloquent\ModelNotFoundException::class);

test('file controller download rejects requests without route or query identifier', function () {
    file_controller_fixtures();

    file_controller()->download(file_controller_download_request());
})->throws(HttpException::class, 'Missing file identifier.');

test('public file controller uploads multipart files with session ownership and subject metadata', function () {
    $capsule = file_controller_fixtures();

    $response = public_file_controller()->create(public_file_controller_upload_request([
        'path'         => 'uploads/documents',
        'type'         => 'document',
        'file_size'    => 13,
        'subject_uuid' => 'user-subject',
        'subject_type' => User::class,
    ], 'uploaded body'));

    $payload = public_file_controller_payload($response);
    $record  = $capsule->getConnection('mysql')->table('files')->first();

    expect($payload['original_filename'])->toBe('manifest.txt')
        ->and($payload['content_type'])->toBe('text/plain')
        ->and($payload['type'])->toBe('document')
        ->and($payload['file_size'])->toBe(13)
        ->and($record->company_uuid)->toBe('company-1')
        ->and($record->uploader_uuid)->toBe('user-1')
        ->and($record->subject_uuid)->toBe('user-subject')
        ->and($record->subject_type)->toBe(User::class)
        ->and(Storage::disk('testing')->exists($record->path))->toBeTrue();
});

test('public file controller reports multipart storage and record creation failures', function () {
    $capsule = file_controller_fixtures();

    $falseStorageFailure = public_file_controller()->create(public_file_controller_failing_upload_request([
        'path' => 'uploads/documents',
        'type' => 'document',
    ]));

    expect($falseStorageFailure->getStatusCode())->toBe(400)
        ->and($falseStorageFailure->getData(true))->toBe(['error' => 'File upload failed.'])
        ->and($capsule->getConnection('mysql')->table('files')->count())->toBe(0);

    $storageFailure = public_file_controller()->create(public_file_controller_upload_request([
        'disk' => 'missing-disk',
        'path' => 'uploads/documents',
        'type' => 'document',
    ], 'uploaded body'));

    expect($storageFailure->getStatusCode())->toBe(400)
        ->and($storageFailure->getData(true)['error'])->toContain('Disk [missing-disk] does not have a configured driver')
        ->and($capsule->getConnection('mysql')->table('files')->count())->toBe(0);

    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('files');

    $recordFailure = public_file_controller()->create(public_file_controller_upload_request([
        'path' => 'uploads/documents',
        'type' => 'document',
    ], 'uploaded body'));

    expect($recordFailure->getStatusCode())->toBe(400)
        ->and($recordFailure->getData(true)['error'])->toContain('no such table: files');
});

test('public file controller creates base64 files and reports missing data using api error shape', function () {
    $capsule = file_controller_fixtures();

    $created = public_file_controller()->createFromBase64(public_file_controller_upload_base64_request([
        'data'         => base64_encode('plain text body'),
        'path'         => 'uploads/documents',
        'file_name'    => 'manifest.txt',
        'file_type'    => 'document',
        'content_type' => 'text/plain',
        'subject_uuid' => 'subject-1',
        'subject_type' => User::class,
    ]));
    $missing = public_file_controller()->createFromBase64(public_file_controller_upload_base64_request([
        'path'      => 'uploads/documents',
        'file_name' => 'missing.txt',
    ]));

    $payload = public_file_controller_payload($created);
    $record  = $capsule->getConnection('mysql')->table('files')->first();

    expect($payload['original_filename'])->toBe('manifest.txt')
        ->and($payload['content_type'])->toBe('text/plain')
        ->and($record->company_uuid)->toBe('company-1')
        ->and($record->uploader_uuid)->toBe('user-1')
        ->and($record->subject_uuid)->toBe('subject-1')
        ->and(Storage::disk('testing')->get('uploads/documents/manifest.txt'))->toBe('plain text body')
        ->and($missing->getStatusCode())->toBe(400)
        ->and($missing->getData(true))->toBe(['error' => 'Oops! Looks like nodata was provided for upload.']);
});

test('public file controller normalizes uploads disk base64 paths and reports failure branches', function () {
    $capsule = file_controller_fixtures();

    $normalized = public_file_controller()->createFromBase64(public_file_controller_upload_base64_request([
        'data'      => base64_encode('avatar body'),
        'disk'      => 'uploads',
        'path'      => 'uploads/avatars',
        'file_name' => 'avatar.png',
    ]));

    $payload = public_file_controller_payload($normalized);
    $record  = $capsule->getConnection('mysql')->table('files')->first();

    expect($payload['original_filename'])->toBe('avatar.png')
        ->and($record->path)->toBe('avatars/avatar.png')
        ->and($record->disk)->toBe('uploads')
        ->and(Storage::disk('uploads')->get('avatars/avatar.png'))->toBe('avatar body');

    $storageFailure = public_file_controller()->createFromBase64(public_file_controller_upload_base64_request([
        'data'      => base64_encode('body'),
        'disk'      => 'missing-disk',
        'path'      => 'uploads/documents',
        'file_name' => 'failed.txt',
    ]));

    expect($storageFailure->getStatusCode())->toBe(400)
        ->and($storageFailure->getData(true)['error'])->toContain('Disk [missing-disk] does not have a configured driver');

    app()->instance('filesystem', new FileControllerFailingFilesystemManager());
    Facade::clearResolvedInstance('filesystem');

    $falseStorageFailure = public_file_controller()->createFromBase64(public_file_controller_upload_base64_request([
        'data'      => base64_encode('body'),
        'path'      => 'uploads/documents',
        'file_name' => 'failed.txt',
    ]));

    expect($falseStorageFailure->getStatusCode())->toBe(400)
        ->and($falseStorageFailure->getData(true))->toBe(['error' => 'File upload failed.']);

    $filesystem = new FilesystemManager(app());
    app()->instance('filesystem', $filesystem);
    app()->instance(FilesystemFactory::class, $filesystem);
    Facade::clearResolvedInstance('filesystem');

    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('files');

    $recordFailure = public_file_controller()->createFromBase64(public_file_controller_upload_base64_request([
        'data'      => base64_encode('body'),
        'path'      => 'uploads/documents',
        'file_name' => 'failed.txt',
    ]));

    expect($recordFailure->getStatusCode())->toBe(400)
        ->and($recordFailure->getData(true)['errors'][0])->toContain('no such table: files');
});

test('public file controller downloads updates finds queries and deletes active company files', function () {
    $capsule = file_controller_fixtures();

    Storage::disk('testing')->put('exports/report.csv', 'a,b');
    $capsule->getConnection('mysql')->table('files')->insert([
        [
            'uuid'              => '11111111-1111-4111-8111-111111111111',
            'public_id'         => 'file_public_1',
            'company_uuid'      => 'company-1',
            'uploader_uuid'     => 'user-1',
            'disk'              => 'testing',
            'path'              => 'exports/report.csv',
            'bucket'            => 'testing-bucket',
            'original_filename' => 'report.csv',
            'content_type'      => 'text/csv',
            'file_size'         => 3,
            'caption'           => 'Original caption',
            'created_at'        => '2026-07-18 00:00:00',
            'updated_at'        => '2026-07-18 00:00:00',
        ],
        [
            'uuid'              => '22222222-2222-4222-8222-222222222222',
            'public_id'         => 'file_foreign',
            'company_uuid'      => 'company-2',
            'uploader_uuid'     => 'user-2',
            'disk'              => 'testing',
            'path'              => 'exports/foreign.csv',
            'bucket'            => 'testing-bucket',
            'original_filename' => 'foreign.csv',
            'content_type'      => 'text/csv',
            'file_size'         => 7,
            'caption'           => null,
            'created_at'        => '2026-07-18 00:00:00',
            'updated_at'        => '2026-07-18 00:00:00',
        ],
    ]);

    $download = public_file_controller()->download('file_public_1', public_file_controller_download_request());
    $updated  = public_file_controller()->update('file_public_1', Request::create('/v1/files/file_public_1', 'PUT', [
        'caption'  => 'Updated caption',
        'meta'     => ['reviewed' => true],
        'filename' => 'renamed.csv',
    ]));
    $found   = public_file_controller()->find('file_public_1');
    $queried = public_file_controller()->query(public_file_controller_query_request());
    $deleted = public_file_controller()->delete('file_public_1');
    $missing = public_file_controller()->find('file_public_1');
    $foreign = public_file_controller()->find('file_foreign');

    expect($download->getStatusCode())->toBe(200)
        ->and($download->headers->get('content-disposition'))->toContain('report.csv')
        ->and(public_file_controller_payload($updated)['caption'])->toBe('Updated caption')
        ->and($updated->resource->original_filename)->toBe('renamed.csv')
        ->and($updated->resource->meta)->toBe(['reviewed' => true])
        ->and(public_file_controller_payload($found)['id'])->toBe('file_public_1')
        ->and($queried->collection->pluck('public_id')->all())->toContain('file_public_1')
        ->and($queried->collection->pluck('public_id')->all())->not->toContain('file_foreign')
        ->and($deleted->resource->public_id)->toBe('file_public_1')
        ->and($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'File resource not found.'])
        ->and($foreign->getStatusCode())->toBe(404)
        ->and($foreign->getData(true))->toBe(['error' => 'File resource not found.']);
});

test('public file controller returns stable missing resource responses for download update and delete', function () {
    file_controller_fixtures();

    $download = public_file_controller()->download('missing-file', public_file_controller_download_request());
    $update   = public_file_controller()->update('missing-file', Request::create('/v1/files/missing-file', 'PUT', [
        'caption' => 'No file',
    ]));
    $delete = public_file_controller()->delete('missing-file');

    expect($download->getStatusCode())->toBe(404)
        ->and($download->getData(true))->toBe(['error' => 'File resource not found.'])
        ->and($update->getStatusCode())->toBe(404)
        ->and($update->getData(true))->toBe(['error' => 'File resource not found.'])
        ->and($delete->getStatusCode())->toBe(404)
        ->and($delete->getData(true))->toBe(['error' => 'File resource not found.']);
});
