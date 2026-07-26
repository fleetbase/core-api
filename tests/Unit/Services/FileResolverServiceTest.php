<?php

use Fleetbase\Models\File;
use Fleetbase\Services\FileResolverService;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileResolverLogFake
{
    public array $entries = [];

    public function warning(string $message, array $context = []): void
    {
        $this->entries[] = ['warning', $message, $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['error', $message, $context];
    }
}

function file_resolver_fixtures(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();

    $storageRoot = storage_path('file-resolver');
    $connection  = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'filesystems.default'        => 'testing',
        'filesystems.disks.testing'  => [
            'driver' => 'local',
            'root'   => $storageRoot,
            'url'    => 'http://fleetbase.test/storage',
        ],
        'filesystems.disks.s3.bucket' => 'fallback-bucket',
        'fleetbase.connection.db'     => 'mysql',
    ]);
    $container->instance(HttpFactory::class, new HttpFactory());

    $filesystem = new FilesystemManager($container);
    $container->instance('filesystem', $filesystem);
    $container->instance(FilesystemFactory::class, $filesystem);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('files');
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
        $table->string('type')->nullable();
        $table->string('content_type')->nullable();
        $table->integer('file_size')->nullable();
        $table->string('slug')->nullable();
        $table->string('caption')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-1',
    ]);

    return $capsule;
}

function file_resolver_upload(string $contents = 'avatar'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fleetbase-upload-');
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'avatar.png', 'image/png', null, true);
}

afterEach(function () {
    session()->flush();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('file resolver stores uploaded files and records session ownership metadata', function () {
    file_resolver_fixtures();
    $upload = file_resolver_upload(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgBzq7oQAAAAASUVORK5CYII='));

    $file = (new FileResolverService())->resolve($upload, 'avatars/');

    expect($file)->toBeInstanceOf(File::class)
        ->and($file->company_uuid)->toBe('company-1')
        ->and($file->uploader_uuid)->toBe('user-1')
        ->and($file->disk)->toBe('testing')
        ->and($file->path)->toStartWith('avatars/')
        ->and($file->path)->toEndWith('.png')
        ->and($file->original_filename)->toBe('avatar.png')
        ->and($file->content_type)->toBe('image/png')
        ->and($file->file_size)->toBe($upload->getSize());

    expect(Storage::disk('testing')->exists($file->path))->toBeTrue();
});

test('file resolver persists base64 image data to the configured disk', function () {
    file_resolver_fixtures();
    $payload = 'data:image/png;base64,' . base64_encode('image-body');

    $file = (new FileResolverService())->resolve($payload, 'inline-images/');

    expect($file)->toBeInstanceOf(File::class)
        ->and($file->company_uuid)->toBe('company-1')
        ->and($file->uploader_uuid)->toBe('user-1')
        ->and($file->disk)->toBe('testing')
        ->and($file->path)->toStartWith('inline-images/')
        ->and($file->original_filename)->toEndWith('.png')
        ->and($file->content_type)->toBe('image/png')
        ->and($file->file_size)->toBe(strlen('image-body'))
        ->and(Storage::disk('testing')->get($file->path))->toBe('image-body');
});

test('file resolver resolves public ids only within the active company session', function () {
    $capsule = file_resolver_fixtures();
    $capsule->getConnection('mysql')->table('files')->insert([
        [
            'uuid'              => 'file-1',
            'public_id'         => 'file_1234567890',
            'company_uuid'      => 'company-1',
            'disk'              => 'testing',
            'path'              => 'avatars/owned.png',
            'original_filename' => 'owned.png',
        ],
        [
            'uuid'              => 'file-2',
            'public_id'         => 'file_0987654321',
            'company_uuid'      => 'company-2',
            'disk'              => 'testing',
            'path'              => 'avatars/foreign.png',
            'original_filename' => 'foreign.png',
        ],
    ]);

    $resolver = new FileResolverService();

    expect($resolver->resolve('file_1234567890'))->toBeInstanceOf(File::class)
        ->and($resolver->resolve('file_1234567890')->uuid)->toBe('file-1')
        ->and($resolver->resolve('file_0987654321'))->toBeNull();
});

test('file resolver downloads remote urls and stores response metadata', function () {
    file_resolver_fixtures();
    Http::fake([
        'https://cdn.fleetbase.test/assets/invoice.pdf' => Http::response('pdf-body', 200, [
            'Content-Type'   => 'application/pdf',
            'Content-Length' => '8',
        ]),
    ]);

    $file = (new FileResolverService())->resolve('https://cdn.fleetbase.test/assets/invoice.pdf', 'downloads/');

    expect($file)->toBeInstanceOf(File::class)
        ->and($file->company_uuid)->toBe('company-1')
        ->and($file->uploader_uuid)->toBe('user-1')
        ->and($file->disk)->toBe('testing')
        ->and($file->path)->toBe('downloads/invoice.pdf')
        ->and($file->original_filename)->toBe('invoice.pdf')
        ->and($file->content_type)->toBe('application/pdf')
        ->and($file->file_size)->toEqual(8)
        ->and(Storage::disk('testing')->get('downloads/invoice.pdf'))->toBe('pdf-body');

    Http::assertSent(fn ($request) => $request->url() === 'https://cdn.fleetbase.test/assets/invoice.pdf');
});

test('file resolver url extension guesser preserves explicit extensions and binary fallback', function () {
    $resolver = new FileResolverService();
    $method   = new ReflectionMethod($resolver, 'guessExtensionFromUrl');
    $method->setAccessible(true);

    expect($method->invoke($resolver, 'https://cdn.fleetbase.test/assets/archive.tar.gz'))->toBe('gz')
        ->and($method->invoke($resolver, 'https://cdn.fleetbase.test/assets/download'))->toBe('bin');
});

test('file resolver returns null for failed urls unsupported inputs and filters many results', function () {
    file_resolver_fixtures();
    Http::fake([
        'https://cdn.fleetbase.test/missing.png'  => Http::response('missing', 404),
        'https://cdn.fleetbase.test/assets/photo' => Http::response('photo-body', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $resolver = new FileResolverService();
    $resolved = $resolver->resolveMany([
        'https://cdn.fleetbase.test/missing.png',
        'https://cdn.fleetbase.test/assets/photo',
        ['not-a-supported-input'],
        'not-a-url-or-file-id',
    ], 'remote/');

    expect($resolver->resolve('https://cdn.fleetbase.test/missing.png'))->toBeNull()
        ->and($resolver->resolve(['not-a-supported-input']))->toBeNull()
        ->and($resolved)->toHaveCount(1)
        ->and($resolved[0])->toBeInstanceOf(File::class)
        ->and($resolved[0]->path)->toStartWith('remote/')
        ->and($resolved[0]->path)->toEndWith('.bin')
        ->and($resolved[0]->content_type)->toBe('image/jpeg')
        ->and($resolved[0]->file_size)->toEqual(strlen('photo-body'));
});

test('file resolver logs remote download exceptions and attaches resolved file ids', function () {
    $capsule = file_resolver_fixtures();
    $logger  = new FileResolverLogFake();
    Log::swap($logger);
    Http::fake([
        'https://cdn.fleetbase.test/network-error.png' => fn () => throw new RuntimeException('network unavailable'),
    ]);

    $resolver = new FileResolverService();

    expect($resolver->resolve('https://cdn.fleetbase.test/network-error.png'))->toBeNull()
        ->and($logger->entries[0][0])->toBe('error')
        ->and($logger->entries[0][1])->toBe('Failed to download file from URL')
        ->and($logger->entries[0][2]['url'])->toBe('https://cdn.fleetbase.test/network-error.png')
        ->and($logger->entries[0][2]['error'])->toBe('network unavailable');

    $capsule->getConnection('mysql')->table('files')->insert([
        'uuid'              => 'file-attach',
        'public_id'         => 'file_1234567899',
        'company_uuid'      => 'company-1',
        'disk'              => 'testing',
        'path'              => 'attachments/pod.png',
        'original_filename' => 'pod.png',
    ]);

    $model = new class {
        public ?string $pod_uuid = null;
    };

    expect($resolver->resolveAndAttach('file_1234567899', $model, 'pod_uuid'))->toBeTrue()
        ->and($model->pod_uuid)->toBe('file-attach')
        ->and($resolver->resolveAndAttach('not-a-file', $model, 'pod_uuid'))->toBeFalse()
        ->and($resolver->resolveAndAttach('file_1234567899', null, 'pod_uuid'))->toBeFalse();
});
