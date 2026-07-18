<?php

use Fleetbase\Http\Controllers\Api\v1\FileController as PublicFileController;
use Fleetbase\Http\Controllers\Internal\v1\FileController;
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
        'uuid' => 'user-1',
        'public_id' => 'user_1',
        'company_uuid' => 'company-1',
        'name' => 'Uploader User',
        'email' => 'uploader@example.test',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
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

function public_file_controller_upload_base64_request(array $input = []): UploadBase64FileRequest
{
    return UploadBase64FileRequest::create('/v1/files/upload-base64', 'POST', $input);
}

function public_file_controller_download_request(array $query = []): DownloadFileRequest
{
    return DownloadFileRequest::create('/v1/files/download', 'GET', $query);
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
        'caption' => 'Updated caption',
        'meta' => ['reviewed' => true],
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
