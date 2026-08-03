<?php

use Fleetbase\Exports\ApiCredentialExport;
use Fleetbase\Http\Controllers\Internal\v1\ApiCredentialController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\ApiCredential;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class ApiCredentialControllerSpy extends ApiCredentialController
{
    public array $syncedModels = [];

    protected function upsertModelToSandbox(EloquentModel $model): void
    {
        $this->syncedModels[] = [
            'class' => $model::class,
            'uuid'  => $model->getAttribute('uuid'),
        ];
    }
}

class ApiCredentialControllerCreateSpy extends ApiCredentialController
{
    public array $syncCalls = [];

    protected function syncCurrentSessionToSandbox(Request $request): void
    {
        $this->syncCalls[] = [
            'path'    => $request->path(),
            'sandbox' => $request->header('Access-Console-Sandbox'),
        ];
    }
}

class ApiCredentialControllerHashFake
{
    public function make(mixed $value, array $options = []): string
    {
        return 'hashed:' . $value;
    }
}

class ApiCredentialControllerAuthFake
{
    public function __construct(private bool $valid)
    {
    }

    public function validate(array $credentials = []): bool
    {
        return $this->valid && ($credentials['password'] ?? null) === 'correct-password';
    }
}

class ApiCredentialControllerTaggedCacheFake
{
    private array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
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

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function forget(string $key): bool
    {
        return $this->delete($key);
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->values[$key] ??= $callback();
    }
}

class ApiCredentialControllerExcelFake
{
    public ?object $export   = null;
    public ?string $filename = null;

    public function download(object $export, string $filename): Response
    {
        $this->export   = $export;
        $this->filename = $filename;

        return new Response('api credential export');
    }
}

class ApiCredentialControllerUserStub
{
    public function __construct(public string $email)
    {
    }
}

class ApiCredentialSandboxPayloadModel extends Model
{
    protected $connection = 'mysql';
    protected $table      = 'api_credential_sandbox_payloads';

    protected $fillable = [
        'uuid',
        'name',
        'payload',
        'created_at',
        'invalid_at',
    ];

    protected $casts = [
        'payload' => Fleetbase\Casts\Json::class,
    ];
}

function api_credential_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'              => 'mysql',
        'database.connections.mysql'    => $connection,
        'database.connections.sandbox'  => $connection,
        'fleetbase.connection.db'       => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->addConnection($connection, 'sandbox');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');

    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    $container->instance('hash', new ApiCredentialControllerHashFake());
    Cache::swap(new ApiCredentialControllerTaggedCacheFake());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');
    Facade::clearResolvedInstance('hash');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-1',
    ]);

    foreach (['mysql', 'sandbox'] as $connectionName) {
        $schema = $capsule->getConnection($connectionName)->getSchemaBuilder();
        $schema->create('users', function ($table) {
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('companies', function ($table) {
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
            $table->string('public_id')->nullable();
            $table->string('name')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
        $schema->create('company_users', function ($table) {
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
            $table->string('company_uuid')->index();
            $table->string('user_uuid')->index();
            $table->string('status')->nullable();
            $table->boolean('external')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('api_credentials', function ($table) {
            $table->integer('id')->nullable();
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
            $table->string('user_uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('key')->nullable();
            $table->string('secret')->nullable();
            $table->boolean('test_mode')->default(false);
            $table->string('api')->nullable();
            $table->json('browser_origins')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('orders', function ($table) {
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
            $table->string('description')->nullable();
        });
        $schema->create('roles', function ($table) {
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
        });
        $schema->create('api_credential_sandbox_payloads', function ($table) {
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
            $table->string('name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('invalid_at')->nullable();
        });
    }

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('users')->insert([
        'uuid'         => 'user-1',
        'public_id'    => 'user_public_1',
        'company_uuid' => 'company-1',
        'name'         => 'Developer User',
        'email'        => 'developer@example.test',
        'status'       => 'active',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $capsule->getConnection('mysql')->table('companies')->insert([
        'uuid'       => 'company-1',
        'public_id'  => 'company_public_1',
        'name'       => 'Acme Logistics',
        'owner_uuid' => 'user-1',
        'slug'       => 'acme-logistics',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        'uuid'         => 'company-user-1',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'user-1',
        'status'       => 'active',
        'external'     => false,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $capsule->getConnection('mysql')->table('api_credentials')->insert([
        'id'           => 42,
        'uuid'         => 'credential-1',
        '_key'         => 'old-internal-key',
        'user_uuid'    => 'user-1',
        'company_uuid' => 'company-1',
        'name'         => 'Console Key',
        'key'          => 'flb_test_oldkey',
        'secret'       => 'old-secret',
        'test_mode'    => true,
        'api'          => 'developers',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $capsule->getConnection('sandbox')->table('orders')->insert([
        ['uuid' => 'order-uses-old-key', '_key' => 'flb_test_oldkey', 'description' => 'replace me'],
        ['uuid' => 'order-uses-other-key', '_key' => 'flb_test_other', 'description' => 'leave me'],
    ]);
    $capsule->getConnection('sandbox')->table('roles')->insert([
        'uuid' => 'role-skip',
        '_key' => 'flb_test_oldkey',
    ]);

    return $capsule;
}

function api_credential_controller_reflect(object $controller, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(ApiCredentialController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    config([
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
    ]);
    EloquentModel::clearBootedModels();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('api credential controller syncs current user company and membership into sandbox when session is complete', function () {
    api_credential_controller_database();
    $controller = new ApiCredentialControllerSpy();

    api_credential_controller_reflect($controller, 'syncCurrentSessionToSandbox', Request::create('/int/v1/api-credentials', 'POST'));

    expect($controller->syncedModels)->toBe([
        ['class' => Fleetbase\Models\User::class, 'uuid' => 'user-1'],
        ['class' => Fleetbase\Models\Company::class, 'uuid' => 'company-1'],
        ['class' => Fleetbase\Models\CompanyUser::class, 'uuid' => 'company-user-1'],
    ]);
});

test('api credential controller sandbox sync is a no op without complete session context', function () {
    api_credential_controller_database();
    session(['company' => null]);
    $controller = new ApiCredentialControllerSpy();

    api_credential_controller_reflect($controller, 'syncCurrentSessionToSandbox', Request::create('/int/v1/api-credentials', 'POST'));

    expect($controller->syncedModels)->toBe([]);
});

test('api credential controller upserts fillable sandbox payloads with json and datetime normalization', function () {
    $capsule   = api_credential_controller_database();
    $model     = new ApiCredentialSandboxPayloadModel();
    $model->setRawAttributes([
        'uuid'       => 'payload-1',
        'name'       => 'Payload One',
        'payload'    => ['scope' => ['orders', 'drivers']],
        'created_at' => '2026-07-18T08:30:00+00:00',
        'invalid_at' => 'not-a-date',
        'ignored'    => 'not fillable',
    ], true);

    api_credential_controller_reflect(new ApiCredentialController(), 'upsertModelToSandbox', $model);
    api_credential_controller_reflect(new ApiCredentialController(), 'upsertModelToSandbox', $model->forceFill(['name' => 'Payload Updated']));

    $stored = $capsule->getConnection('sandbox')->table('api_credential_sandbox_payloads')->where('uuid', 'payload-1')->first();

    expect($stored->name)->toBe('Payload Updated')
        ->and(json_decode($stored->payload, true))->toBe(['scope' => ['orders', 'drivers']])
        ->and($stored->created_at)->toBe('2026-07-18 08:30:00')
        ->and($stored->invalid_at)->toBeNull()
        ->and(property_exists($stored, 'ignored'))->toBeFalse();
});

test('api credential controller skips sandbox upserts without string uuids', function () {
    $capsule = api_credential_controller_database();
    $model   = new ApiCredentialSandboxPayloadModel();
    $model->setRawAttributes([
        'name'       => 'Missing UUID',
        'payload'    => ['scope' => ['orders']],
        'created_at' => '2026-07-18T08:30:00+00:00',
    ], true);

    api_credential_controller_reflect(new ApiCredentialController(), 'upsertModelToSandbox', $model);

    expect($capsule->getConnection('sandbox')->table('api_credential_sandbox_payloads')->count())->toBe(0);
});

test('api credential controller create syncs sandbox context only for sandbox console requests', function () {
    api_credential_controller_database();

    $sandboxController = new ApiCredentialControllerCreateSpy(new ApiCredential());
    $liveController    = new ApiCredentialControllerCreateSpy(new ApiCredential());

    $sandboxResponse = $sandboxController->createRecord(Request::create('/int/v1/api-credentials', 'POST', [], [], [], [
        'HTTP_ACCESS_CONSOLE_SANDBOX' => 'true',
    ]));
    $liveResponse = $liveController->createRecord(Request::create('/int/v1/api-credentials', 'POST'));

    expect($sandboxController->syncCalls)->toBe([
        ['path' => 'int/v1/api-credentials', 'sandbox' => 'true'],
    ])
        ->and($liveController->syncCalls)->toBe([])
        ->and($sandboxResponse->getStatusCode())->toBe(400)
        ->and($liveResponse->getStatusCode())->toBe(400);
});

test('api credential controller export downloads api credential exports with requested format', function () {
    api_credential_controller_database();

    $excel = new ApiCredentialControllerExcelFake();
    app()->instance('excel', $excel);
    Facade::clearResolvedInstance('excel');

    $response = ApiCredentialController::export(ExportRequest::create('/int/v1/api-credentials/export', 'GET', [
        'format' => 'csv',
    ]));

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getContent())->toBe('api credential export')
        ->and($excel->export)->toBeInstanceOf(ApiCredentialExport::class)
        ->and($excel->filename)->toStartWith('api-credentials-')
        ->and($excel->filename)->toEndWith('.csv');
});

test('api credential controller roll rejects unauthenticated and missing tenant credentials', function () {
    api_credential_controller_database();
    Auth::swap(new ApiCredentialControllerAuthFake(false));
    $request = Request::create('/int/v1/api-credentials/credential-1/roll', 'POST', ['password' => 'wrong-password']);
    $request->setUserResolver(fn () => new ApiCredentialControllerUserStub('developer@example.test'));

    $authFailure = ApiCredentialController::roll('credential-1', $request);

    Auth::swap(new ApiCredentialControllerAuthFake(true));
    $missingRequest = Request::create('/int/v1/api-credentials/missing/roll', 'POST', [
        'password' => 'correct-password',
    ]);
    $missingRequest->setUserResolver(fn () => new ApiCredentialControllerUserStub('developer@example.test'));
    $missing = ApiCredentialController::roll('missing-credential', $missingRequest);

    expect($authFailure->getStatusCode())->toBe(401)
        ->and($authFailure->getData(true))->toBe(['errors' => ['Authentication required to roll key failed.']])
        ->and($missing->getStatusCode())->toBe(400)
        ->and($missing->getData(true))->toBe(['errors' => ['API credential attempted to roll could not be found.']]);
});

test('api credential controller roll returns a stable error when regenerated credentials cannot be saved', function () {
    api_credential_controller_database();
    EloquentModel::setEventDispatcher(new Dispatcher(Container::getInstance()));
    Auth::swap(new ApiCredentialControllerAuthFake(true));

    try {
        ApiCredential::saving(function (ApiCredential $credential) {
            if ($credential->uuid === 'credential-1') {
                throw new RuntimeException('credential save failed');
            }
        });

        $request = Request::create('/int/v1/api-credentials/credential-1/roll', 'POST', [
            'password' => 'correct-password',
        ]);
        $request->setUserResolver(fn () => new ApiCredentialControllerUserStub('developer@example.test'));

        $response = ApiCredentialController::roll('credential-1', $request);
    } finally {
        ApiCredential::flushEventListeners();
        EloquentModel::unsetEventDispatcher();
    }

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['Attempt to roll key failed.']]);
});

test('api credential controller roll regenerates keys and rewrites sandbox resource ownership keys', function () {
    $capsule = api_credential_controller_database();
    Auth::swap(new ApiCredentialControllerAuthFake(true));
    $request = Request::create('/int/v1/api-credentials/credential-1/roll', 'POST', [
        'password'   => 'correct-password',
        'expiration' => '2026-08-01 12:00:00',
    ]);
    $request->setUserResolver(fn () => new ApiCredentialControllerUserStub('developer@example.test'));

    $response = ApiCredentialController::roll('credential-1', $request);
    $record   = ApiCredential::where('uuid', 'credential-1')->firstOrFail();
    $orders   = $capsule->getConnection('sandbox')->table('orders')->orderBy('uuid')->pluck('_key', 'uuid')->all();
    $roleKey  = $capsule->getConnection('sandbox')->table('roles')->where('uuid', 'role-skip')->value('_key');

    expect($response->getStatusCode())->toBe(200)
        ->and($record->key)->toStartWith('flb_test_')
        ->and($record->key)->not->toBe('flb_test_oldkey')
        ->and($record->secret)->toBe('hashed:' . substr($record->key, strlen('flb_test_')))
        ->and($record->expires_at?->toDateTimeString())->toBe('2026-08-01 12:00:00')
        ->and($orders['order-uses-old-key'])->toBe($record->key)
        ->and($orders['order-uses-other-key'])->toBe('flb_test_other')
        ->and($roleKey)->toBe('flb_test_oldkey')
        ->and($response->getData(true)['apiCredential']['uuid'])->toBe('credential-1');
});
