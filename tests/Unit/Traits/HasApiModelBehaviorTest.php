<?php

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class HasApiModelBehaviorCacheFake
{
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
        return $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return true;
    }

    public function forget(string $key): bool
    {
        return true;
    }
}

class HasApiModelBehaviorResponseCacheFake
{
    public function clear(): bool
    {
        return true;
    }
}

class HasApiModelBehaviorControllerFake extends Controller
{
    public function index(): void
    {
    }
}

class HasApiModelBehaviorRouteFake
{
    public array $action = [
        'namespace' => '',
    ];

    public object $controller;

    public function __construct()
    {
        $this->controller = new HasApiModelBehaviorControllerFake();
    }

    public function getAction(?string $key = null): string|array|null
    {
        $action = [
            'controller' => HasApiModelBehaviorControllerFake::class . '@index',
        ];

        return $key ? ($action[$key] ?? null) : $action;
    }

    public function getActionMethod(): string
    {
        return 'index';
    }

    public function uri(): string
    {
        return 'api/v1/records';
    }
}

class HasApiModelBehaviorRecord extends Model
{
    use HasApiModelBehavior;

    protected $table = 'api_model_behavior_records';

    protected $guarded = [];

    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'user_uuid',
        'created_by_uuid',
        'updated_by_uuid',
        'name',
        'status',
        'amount',
        'slug',
    ];

    protected $searchableColumns = [
        'uuid',
        'public_id',
        'company_uuid',
        'name',
        'status',
        'amount',
        'created_at',
        'updated_at',
    ];

    public function childItems()
    {
        return $this->hasMany(HasApiModelBehaviorChild::class, 'record_uuid', 'uuid');
    }
}

class HasApiModelBehaviorPayloadRecord extends HasApiModelBehaviorRecord
{
    protected $payloadKey = 'apiModelBehaviorRecord';
}

class HasApiModelBehaviorNamedRecord extends HasApiModelBehaviorRecord
{
    protected $pluralName = 'contract records';

    protected $singularName = 'contract record';
}

class HasApiModelBehaviorSessionAgnosticRecord extends HasApiModelBehaviorRecord
{
    protected $sessionAgnosticColumns = ['company_uuid'];
}

class HasApiModelBehaviorChild extends Model
{
    protected $table = 'api_model_behavior_children';

    protected $guarded = [];

    public $timestamps = false;
}

function has_api_model_behavior_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetConnectionResolver();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new HasApiModelBehaviorCacheFake());
    $container->instance('responsecache', new HasApiModelBehaviorResponseCacheFake());
    Cache::swap($container->make('cache'));

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('schema');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('api_model_behavior_records', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('user_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('status')->nullable();
        $table->integer('amount')->default(0);
        $table->string('slug')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    $schema->create('api_model_behavior_children', function ($table) {
        $table->increments('id');
        $table->string('record_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('directives', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('permission_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $capsule;
}

function has_api_model_behavior_request(array $input = [], string $uri = '/api/v1/records', string $method = 'GET'): Request
{
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

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            if (is_string($value) && str_contains($value, ',')) {
                return explode(',', $value);
            }

            return (array) $value;
        });
    }

    Request::macro('getController', function (): object {
        return new class {
        };
    });

    if (!Request::hasMacro('getFilters')) {
        Request::macro('getFilters', function (?array $additionalFilters = []): array {
            $filters = [
                'within',
                'with',
                'without',
                'without_relations',
                'coords',
                'boundary',
                'page',
                'offset',
                'limit',
                'per_page',
                'query',
                'searchQuery',
                'columns',
                'distinct',
                'sort',
                'before',
                'after',
                'on',
                'global',
            ];

            return $this->except(array_merge($filters, $additionalFilters ?? []));
        });
    }

    $request = Request::create($uri, $method, $input);
    $request->setRouteResolver(fn () => new HasApiModelBehaviorRouteFake());
    app()->instance('request', $request);

    return $request;
}

function has_api_model_behavior_seed_records(Capsule $capsule): void
{
    $capsule->getConnection('mysql')->table('api_model_behavior_records')->insert([
        [
            'uuid'            => 'record-1',
            'public_id'       => 'record_alpha',
            'company_uuid'    => 'company-a',
            'user_uuid'       => 'user-a',
            'created_by_uuid' => 'creator-a',
            'updated_by_uuid' => null,
            'name'            => 'Alpha Dispatch',
            'status'          => 'active',
            'amount'          => 15,
            'slug'            => 'alpha',
            'deleted_at'      => null,
            'created_at'      => '2026-07-18 10:00:00',
            'updated_at'      => '2026-07-18 10:00:00',
        ],
        [
            'uuid'            => 'record-2',
            'public_id'       => 'record_beta',
            'company_uuid'    => 'company-a',
            'user_uuid'       => 'user-b',
            'created_by_uuid' => 'creator-a',
            'updated_by_uuid' => null,
            'name'            => 'Beta Dispatch',
            'status'          => 'inactive',
            'amount'          => 25,
            'slug'            => 'beta',
            'deleted_at'      => null,
            'created_at'      => '2026-07-18 11:00:00',
            'updated_at'      => '2026-07-18 11:00:00',
        ],
        [
            'uuid'            => 'record-3',
            'public_id'       => 'record_gamma',
            'company_uuid'    => 'company-b',
            'user_uuid'       => 'user-c',
            'created_by_uuid' => 'creator-b',
            'updated_by_uuid' => null,
            'name'            => 'Gamma Dispatch',
            'status'          => 'active',
            'amount'          => 35,
            'slug'            => 'gamma',
            'deleted_at'      => null,
            'created_at'      => '2026-07-18 12:00:00',
            'updated_at'      => '2026-07-18 12:00:00',
        ],
    ]);

    $capsule->getConnection('mysql')->table('api_model_behavior_children')->insert([
        ['record_uuid' => 'record-1', 'name' => 'Alpha child one', 'deleted_at' => null],
        ['record_uuid' => 'record-1', 'name' => 'Alpha child two', 'deleted_at' => null],
        ['record_uuid' => 'record-2', 'name' => 'Beta child', 'deleted_at' => null],
    ]);
}

afterEach(function () {
    session()->flush();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('api model behavior exposes naming searchability and payload contracts', function () {
    has_api_model_behavior_database();
    session(['user' => 'session-user', 'company' => 'session-company']);

    $record          = new HasApiModelBehaviorRecord();
    $payloadRecord   = new HasApiModelBehaviorPayloadRecord();
    $namedRecord     = new HasApiModelBehaviorNamedRecord();
    $agnosticRecord  = new HasApiModelBehaviorSessionAgnosticRecord();
    $payloadRequest  = has_api_model_behavior_request([
        'apiModelBehaviorRecord' => [
            'name'            => 'Payload name',
            'company_uuid'    => 'attacker-company',
            'created_by_uuid' => 'attacker-user',
            'updated_by_uuid' => 'attacker-user',
            'uploader_uuid'   => 'attacker-user',
        ],
    ], method: 'POST');
    $fallbackRequest = has_api_model_behavior_request([
        'name'         => 'Fallback name',
        'company_uuid' => 'attacker-company',
    ], method: 'POST');

    expect($record->getQualifiedPublicId())->toBe('public_id')
        ->and($record->getPluralName())->toBe('api_model_behavior_records')
        ->and($record->getSingularName())->toBe('api_model_behavior_record')
        ->and($payloadRecord->getPluralName())->toBe('apiModelBehaviorRecords')
        ->and($payloadRecord->getSingularName())->toBe('apiModelBehaviorRecord')
        ->and($namedRecord->getPluralName())->toBe('contract records')
        ->and($namedRecord->getSingularName())->toBe('contract record')
        ->and($record->searcheableFields())->toContain('status', 'amount', 'updated_at')
        ->and($payloadRecord->getApiPayloadFromRequest($payloadRequest))->toBe(['name' => 'Payload name'])
        ->and($record->getApiPayloadFromRequest($fallbackRequest))->toBe(['name' => 'Fallback name'])
        ->and($record->fillSessionAttributes(['name' => 'Session fill']))->toMatchArray([
            'company_uuid'    => 'session-company',
            'user_uuid'       => 'session-user',
            'created_by_uuid' => 'session-user',
            'updated_by_uuid' => 'session-user',
        ])
        ->and($record->fillSessionAttributes(['user_uuid' => 'explicit-user'], ['company_uuid']))->not->toHaveKey('company_uuid')
        ->and($record->fillSessionAttributes([], [], ['updated_by_uuid']))->toBe(['updated_by_uuid' => 'session-user'])
        ->and($agnosticRecord->fillSessionAttributes([]))->not->toHaveKey('company_uuid');
});

test('api model behavior applies optimized filters sorting pagination and relation mutations', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);

    $request = has_api_model_behavior_request([
        'company_uuid' => 'company-a',
        'status'       => 'active',
        'amount_gte'   => '10',
        'amount_lte'   => '20',
        'name_like'    => 'Alpha',
        'ignored'      => 'should-not-filter',
        'sort'         => '-amount',
        'limit'        => 200,
        'page'         => 1,
        'with'         => 'child_items',
        'with_count'   => 'child_items',
        'without'      => ['slug'],
    ]);

    $results = (new HasApiModelBehaviorRecord())->queryFromRequest($request);
    $record  = $results->first();

    expect($results)->toHaveCount(1)
        ->and($record->uuid)->toBe('record-1')
        ->and($record->relationLoaded('childItems'))->toBeTrue()
        ->and($record->child_items_count)->toBe(2)
        ->and($record->getHidden())->toContain('slug')
        ->and($record->childItems)->toHaveCount(2);
});

test('api model behavior scopes reads updates and bulk deletion to the session company', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);
    session(['user' => 'session-user', 'company' => 'company-a']);

    $model         = new HasApiModelBehaviorRecord();
    $request       = has_api_model_behavior_request();
    $foundByUuid   = $model->getById('record-1', null, $request);
    $blockedByUuid = $model->getById('record-3', null, $request);
    $foundByPublic = $model->getById('record_beta', null, $request);
    $update        = $model->updateRecordFromRequest(has_api_model_behavior_request([
        'name'         => 'Updated Alpha',
        'slug'         => 'malicious-slug',
        'company_uuid' => 'company-b',
        'updated_at'   => '2020-01-01 00:00:00',
    ], method: 'PATCH'), 'record_alpha', options: ['return_object' => true]);
    $deleteCount = $model->bulkRemove(['record-2', 'record-3']);

    expect($foundByUuid?->uuid)->toBe('record-1')
        ->and($blockedByUuid)->toBeNull()
        ->and($foundByPublic?->uuid)->toBe('record-2')
        ->and($update->name)->toBe('Updated Alpha')
        ->and($update->slug)->toBe('alpha')
        ->and($update->company_uuid)->toBe('company-a')
        ->and($update->updated_by_uuid)->toBe('session-user')
        ->and($deleteCount)->toBe(1)
        ->and($capsule->getConnection('mysql')->table('api_model_behavior_records')->where('uuid', 'record-2')->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('api_model_behavior_records')->where('uuid', 'record-3')->whereNull('deleted_at')->exists())->toBeTrue();
});

test('api model behavior validates update parameters and find record scoping contracts', function () {
    has_api_model_behavior_database();
    HasApiModelBehaviorRecord::create([
        'uuid'         => 'record-1',
        'public_id'    => 'record_alpha',
        'company_uuid' => 'company-a',
        'name'         => 'Alpha Dispatch',
        'status'       => 'active',
    ]);
    HasApiModelBehaviorRecord::create([
        'uuid'         => 'record-2',
        'public_id'    => 'record_beta',
        'company_uuid' => 'company-b',
        'name'         => 'Beta Dispatch',
        'status'       => 'active',
    ]);
    session(['company' => 'company-a']);

    $model       = new HasApiModelBehaviorRecord();
    $found       = HasApiModelBehaviorRecord::findRecordOrFail('record_alpha');
    $safeMissing = null;

    try {
        HasApiModelBehaviorRecord::findRecordOrFail('record_beta');
    } catch (Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
        $safeMissing = $exception;
    }

    expect($model->isColumn('company_uuid'))->toBeTrue()
        ->and($model->isColumn('missing_column'))->toBeFalse()
        ->and($model->shouldQualifyColumn('uuid'))->toBeTrue()
        ->and($model->shouldQualifyColumn('name'))->toBeFalse()
        ->and($model->isInvalidUpdateParam('name'))->toBeFalse()
        ->and($model->isInvalidUpdateParam('uuid'))->toBeFalse()
        ->and($model->isInvalidUpdateParam('unexpected'))->toBeTrue()
        ->and($model->getApiHumanReadableName())->toBe('API Model Behavior Records')
        ->and($found->uuid)->toBe('record-1')
        ->and($safeMissing)->toBeInstanceOf(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and($safeMissing->getModel())->toBe(HasApiModelBehaviorRecord::class);
});
