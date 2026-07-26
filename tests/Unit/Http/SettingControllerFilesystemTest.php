<?php

namespace PhpOption {
    if (!class_exists(Option::class)) {
        class Option
        {
            public function __construct(private mixed $value)
            {
            }

            public static function fromValue(mixed $value): self
            {
                return new self($value);
            }

            public function map(callable $callback): self
            {
                if ($this->value === null) {
                    return $this;
                }

                return new self($callback($this->value));
            }

            public function getOrCall(callable $callback): mixed
            {
                return $this->value ?? $callback();
            }

            public function getOrThrow(Throwable $throwable): mixed
            {
                if ($this->value === null) {
                    throw $throwable;
                }

                return $this->value;
            }
        }
    }
}

namespace Dotenv\Repository {
    if (!class_exists(RepositoryBuilder::class)) {
        class RepositoryBuilder
        {
            public static function createWithDefaultAdapters(): self
            {
                return new self();
            }

            public function addAdapter(string $adapter): self
            {
                return $this;
            }

            public function immutable(): self
            {
                return $this;
            }

            public function make(): object
            {
                return new class {
                    public function get(string $key): mixed
                    {
                        return null;
                    }
                };
            }
        }
    }
}

namespace Dotenv\Repository\Adapter {
    if (!class_exists(PutenvAdapter::class)) {
        class PutenvAdapter
        {
        }
    }
}

namespace {
    use Aws\Command;
    use Aws\S3\Exception\S3Exception;
    use Fleetbase\Http\Controllers\Internal\v1\SettingController;
    use Fleetbase\Http\Requests\AdminRequest;
    use Illuminate\Support\Facades\Facade;

    class SettingControllerFilesystemFake
    {
        public array $disks = [];

        public function disk(string $name): SettingControllerFilesystemDiskFake
        {
            return $this->disks[$name] ??= new SettingControllerFilesystemDiskFake();
        }
    }

    class SettingControllerFilesystemDiskFake
    {
        public ?string $existsException = null;

        public bool $existsResult = true;

        public string|Throwable|null $putException = null;

        public array $puts = [];

        public function put(string $path, string $contents): bool
        {
            if ($this->putException) {
                if ($this->putException instanceof Throwable) {
                    throw $this->putException;
                }

                throw new RuntimeException($this->putException);
            }

            $this->puts[] = [$path, $contents];

            return true;
        }

        public function exists(string $path): bool
        {
            if ($this->existsException) {
                throw new RuntimeException($this->existsException);
            }

            return $this->existsResult;
        }
    }

    function setting_controller_filesystem_fixtures(): SettingControllerFilesystemFake
    {
        $container = bind_test_container([
            'filesystems.default'           => 'local',
            'filesystems.disks.local'       => ['driver' => 'local'],
            'filesystems.disks.s3'          => [
                'driver'   => 's3',
                'bucket'   => 'fleetbase-media',
                'url'      => 'https://cdn.example.test',
                'endpoint' => 'https://s3.example.test',
            ],
            'filesystems.disks.gcs'         => [
                'driver'      => 'gcs',
                'bucket'      => 'fleetbase-gcs',
                'key_file_id' => 'not-a-uuid',
            ],
        ]);

        $filesystem = new SettingControllerFilesystemFake();
        $container->instance('filesystem', $filesystem);
        Facade::clearResolvedInstance('filesystem');

        return $filesystem;
    }

    function setting_controller_admin_request(array $input = []): AdminRequest
    {
        return AdminRequest::create('/int/v1/settings/filesystem', 'POST', $input);
    }

    afterEach(function () {
        Facade::clearResolvedInstances();
    });

    test('filesystem config response exposes active driver disk and provider settings', function () {
        setting_controller_filesystem_fixtures();

        $response = (new SettingController())->getFilesystemConfig(setting_controller_admin_request());

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toMatchArray([
                'driver'                           => 'local',
                'disks'                            => ['local', 's3', 'gcs'],
                's3Bucket'                         => 'fleetbase-media',
                's3Url'                            => 'https://cdn.example.test',
                's3Endpoint'                       => 'https://s3.example.test',
                'gcsBucket'                        => 'fleetbase-gcs',
                'isGoogleCloudStorageEnvConfigued' => false,
                'gcsCredentialsFileId'             => 'not-a-uuid',
                'gcsCredentialsFile'               => null,
            ]);
    });

    test('test filesystem config writes the probe file and reports successful upload', function () {
        $filesystem = setting_controller_filesystem_fixtures();

        $response = (new SettingController())->testFilesystemConfig(setting_controller_admin_request([
            'disk' => 's3',
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe([
                'status'   => 'success',
                'message'  => 'Filesystem configuration is successful, test file uploaded.',
                'uploaded' => true,
            ])
            ->and(config('filesystems.default'))->toBe('s3')
            ->and($filesystem->disk('s3')->puts)->toBe([
                ['testfile.txt', 'Hello World'],
            ]);
    });

    test('test filesystem config reports an error when the probe file is not found after upload', function () {
        $filesystem                            = setting_controller_filesystem_fixtures();
        $filesystem->disk('gcs')->existsResult = false;

        $response = (new SettingController())->testFilesystemConfig(setting_controller_admin_request([
            'disk' => 'gcs',
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe([
                'status'   => 'error',
                'message'  => 'Configuration is working, but test file upload failed for uknown reasons.',
                'uploaded' => false,
            ])
            ->and(config('filesystems.default'))->toBe('gcs')
            ->and($filesystem->disk('gcs')->puts)->toBe([
                ['testfile.txt', 'Hello World'],
            ]);
    });

    test('test filesystem config returns storage exception details without leaking thrown errors', function () {
        $filesystem                           = setting_controller_filesystem_fixtures();
        $filesystem->disk('s3')->putException = 'Access denied for bucket fleetbase-media';

        $response = (new SettingController())->testFilesystemConfig(setting_controller_admin_request([
            'disk' => 's3',
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe([
                'status'   => 'error',
                'message'  => 'Access denied for bucket fleetbase-media',
                'uploaded' => true,
            ])
            ->and($filesystem->disk('s3')->puts)->toBe([]);
    });

    test('test filesystem config handles s3 provider exceptions separately from generic storage failures', function () {
        $filesystem                           = setting_controller_filesystem_fixtures();
        $filesystem->disk('s3')->putException = new S3Exception('S3 rejected the probe upload', new Command('PutObject'));

        $response = (new SettingController())->testFilesystemConfig(setting_controller_admin_request([
            'disk' => 's3',
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe([
                'status'   => 'error',
                'message'  => 'S3 rejected the probe upload',
                'uploaded' => true,
            ])
            ->and($filesystem->disk('s3')->puts)->toBe([]);
    });

    test('test filesystem config reports exists probe exceptions after writing the test file', function () {
        $filesystem                              = setting_controller_filesystem_fixtures();
        $filesystem->disk('s3')->existsException = 'Unable to verify uploaded test file';

        $response = (new SettingController())->testFilesystemConfig(setting_controller_admin_request([
            'disk' => 's3',
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe([
                'status'   => 'error',
                'message'  => 'Configuration is working, but test file upload failed for uknown reasons.',
                'uploaded' => false,
            ])
            ->and(config('filesystems.default'))->toBe('s3')
            ->and($filesystem->disk('s3')->puts)->toBe([
                ['testfile.txt', 'Hello World'],
            ]);
    });
}
