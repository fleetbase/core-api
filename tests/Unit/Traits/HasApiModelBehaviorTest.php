<?php

use Fleetbase\Http\Filter\Filter;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class HasApiModelBehaviorCacheFake
{
    public array $store = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function lock(string $name, int $seconds): object
    {
        return new class {
            public function block(int $seconds, callable $callback): mixed
            {
                return $callback();
            }
        };
    }

    public function remember(string $key, mixed $ttl, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            $this->store[$key] = $callback();
        }

        return $this->store[$key];
    }

    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function increment(string $key): int
    {
        $this->store[$key] = ($this->store[$key] ?? 0) + 1;

        return $this->store[$key];
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->store[$key] = $value;

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

    public function __construct(private string $uri = 'api/v1/records')
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
        return ltrim($this->uri, '/');
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

class HasApiModelBehaviorDefaultSearchRecord extends HasApiModelBehaviorRecord
{
    protected $searchableColumns = [];
}

class HasApiModelBehaviorOptionRecord extends HasApiModelBehaviorRecord
{
    protected $option_key = 'uuid';

    protected $option_label = 'name';
}

class HasApiModelBehaviorInternalIdRecord extends HasApiModelBehaviorRecord
{
    protected $fillable = [
        'uuid',
        'public_id',
        'internal_id',
        'company_uuid',
        'name',
    ];
}

class HasApiModelBehaviorFilterParamRecord extends HasApiModelBehaviorRecord
{
    protected $filterParams = ['virtual_filter'];
}

class HasApiModelBehaviorFilteredRecord extends HasApiModelBehaviorRecord
{
    public function getFilter(): string
    {
        return HasApiModelBehaviorTestFilter::class;
    }
}

class HasApiModelBehaviorTestFilter extends Filter
{
    public function status(?string $status): void
    {
        if ($status) {
            $this->builder->where('status', $status);
        }
    }
}

class HasApiModelBehaviorAppendedRecord extends HasApiModelBehaviorRecord
{
    protected $appends = ['computed_label'];

    public function getComputedLabelAttribute(): string
    {
        return 'computed';
    }
}

class HasApiModelBehaviorCachedRecord extends HasApiModelBehaviorRecord
{
    use HasApiModelCache;

    public function shouldUseCacheForTest(): bool
    {
        return $this->shouldUseCache();
    }
}

class HasApiModelBehaviorSoftDeletingCachedRecord extends HasApiModelBehaviorRecord
{
    use HasApiModelCache;
    use SoftDeletes;
}

class HasApiModelBehaviorDisabledCachedRecord extends HasApiModelBehaviorCachedRecord
{
    public bool $disableApiCache = true;
}

class HasApiModelBehaviorProbeRecord extends HasApiModelBehaviorRecord
{
    public function applyOptimizedFiltersForTest(Request $request, $builder)
    {
        return $this->applyOptimizedFilters($request, $builder);
    }
}

class HasApiModelBehaviorCustomCreationRecord extends HasApiModelBehaviorRecord
{
    protected $creationMethod = 'createFromContract';

    public function createFromContract(array $input): HasApiModelBehaviorRecord
    {
        $input['status'] = 'custom-created';

        return static::create($input);
    }
}

class HasApiModelBehaviorFailingUpdateRecord extends HasApiModelBehaviorRecord
{
    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException('database update exploded');
    }
}

class HasApiModelBehaviorFailingBulkDeleteRecord extends HasApiModelBehaviorRecord
{
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        return new class {
            public function where($column, $operator = null, $value = null, $boolean = 'and'): self
            {
                return $this;
            }

            public function count(): int
            {
                return 1;
            }

            public function delete(): void
            {
                throw new RuntimeException('bulk delete exploded');
            }
        };
    }
}

class HasApiModelBehaviorSnakeRelationRecord extends Model
{
    use HasApiModelBehavior;

    protected $table = 'api_model_behavior_records';

    protected $guarded = [];

    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'name',
    ];

    public function child_items()
    {
        return $this->hasMany(HasApiModelBehaviorChild::class, 'record_uuid', 'uuid');
    }
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
        $table->string('internal_id')->nullable()->index();
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
    $request->setRouteResolver(fn () => new HasApiModelBehaviorRouteFake($uri));
    app()->instance('request', $request);

    return $request;
}

function has_api_model_behavior_seed_records(Capsule $capsule): void
{
    $capsule->getConnection('mysql')->table('api_model_behavior_records')->insert([
        [
            'uuid'            => 'record-1',
            'public_id'       => 'record_alpha',
            'internal_id'     => 'internal_alpha',
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
            'internal_id'     => 'internal_beta',
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
            'internal_id'     => 'internal_gamma',
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

test('api model behavior query helpers support callbacks cache bypass and internal pagination', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);

    EloquentBuilder::macro('fastPaginate', function (int $perPage = 15, array $columns = ['*']) {
        $total = $this->count();
        $items = $this->limit($perPage)->get($columns)->all();

        return new class($items, $total) {
            public function __construct(private array $items, private int $total)
            {
            }

            public function items(): array
            {
                return $this->items;
            }

            public function total(): int
            {
                return $this->total;
            }
        };
    });

    $callbackRequest = has_api_model_behavior_request([
        'limit' => -1,
        'page'  => 2,
    ]);
    $callbackResults = HasApiModelBehaviorRecord::queryWithRequest($callbackRequest, function ($builder, Request $request) {
        $builder->where('company_uuid', 'company-a')
            ->where('amount', '>', 20);

        expect($request->integer('page'))->toBe(2);
    }, withoutCache: true);

    $withoutCacheResults = HasApiModelBehaviorRecord::withoutCache()->queryFromRequest(has_api_model_behavior_request([
        'company_uuid' => 'company-a',
        'limit'        => 1,
        'offset'       => 1,
    ]));

    $internal = (new HasApiModelBehaviorRecord())->queryFromRequest(has_api_model_behavior_request([
        'company_uuid' => 'company-a',
        'limit'        => 1,
    ], '/int/v1/records'));

    expect($callbackResults->pluck('uuid')->all())->toBe(['record-2'])
        ->and($withoutCacheResults->pluck('uuid')->all())->toBe(['record-2'])
        ->and($internal->items())->toHaveCount(1)
        ->and($internal->total())->toBe(2);
});

test('api model behavior scopes reads updates and bulk deletion to the session company', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);
    session(['user' => 'session-user', 'company' => 'company-a']);

    $model           = new HasApiModelBehaviorRecord();
    $request         = has_api_model_behavior_request();
    $foundByUuid     = $model->getById('record-1', null, $request);
    $blockedByUuid   = $model->getById('record-3', null, $request);
    $foundByPublic   = $model->getById('record_beta', null, $request);
    $callbackSeen    = false;
    $foundByCallback = $model->getById('record_alpha', function ($builder, Request $callbackRequest) use (&$callbackSeen) {
        $callbackSeen = $callbackRequest->is('api/v1/records');
        $builder->where('status', 'active');
    }, $request);
    $update        = $model->updateRecordFromRequest(has_api_model_behavior_request([
        'name'         => 'Updated Alpha',
        'slug'         => 'malicious-slug',
        'company_uuid' => 'company-b',
        'updated_at'   => '2020-01-01 00:00:00',
    ], method: 'PATCH'), 'record_alpha', options: ['return_object' => true]);
    $deleteCount   = $model->bulkRemove(['record-2', 'record-3']);
    $missingUpdate = null;

    try {
        $model->updateRecordFromRequest(has_api_model_behavior_request(['name' => 'Hidden'], method: 'PATCH'), 'record_gamma');
    } catch (Exception $exception) {
        $missingUpdate = $exception;
    }

    expect($foundByUuid?->uuid)->toBe('record-1')
        ->and($blockedByUuid)->toBeNull()
        ->and($foundByPublic?->uuid)->toBe('record-2')
        ->and($callbackSeen)->toBeTrue()
        ->and($foundByCallback?->uuid)->toBe('record-1')
        ->and($update->name)->toBe('Updated Alpha')
        ->and($update->slug)->toBe('alpha')
        ->and($update->company_uuid)->toBe('company-a')
        ->and($update->updated_by_uuid)->toBe('session-user')
        ->and($deleteCount)->toBe(1)
        ->and($capsule->getConnection('mysql')->table('api_model_behavior_records')->where('uuid', 'record-2')->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('api_model_behavior_records')->where('uuid', 'record-3')->whereNull('deleted_at')->exists())->toBeTrue()
        ->and($missingUpdate)->toBeInstanceOf(Exception::class)
        ->and($missingUpdate->getMessage())->toBe('API Model Behavior Records not found');
});

test('api model behavior create and update callbacks can return response contracts', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);
    session(['user' => 'session-user', 'company' => 'company-a']);

    $model = new HasApiModelBehaviorRecord();

    $beforeCreate = $model->createRecordFromRequest(
        has_api_model_behavior_request(['name' => 'Blocked create'], method: 'POST'),
        fn () => response()->json(['blocked' => 'before-create'], 409)
    );
    $afterCreate = $model->createRecordFromRequest(
        has_api_model_behavior_request([
            'uuid'      => 'record-after-create',
            'public_id' => 'record_after_create',
            'name'      => 'After create',
        ], method: 'POST'),
        null,
        fn () => response()->json(['blocked' => 'after-create'], 202)
    );
    $customCreated = (new HasApiModelBehaviorCustomCreationRecord())->createRecordFromRequest(
        has_api_model_behavior_request([
            'uuid'      => 'record-custom',
            'public_id' => 'record_custom',
            'name'      => 'Custom create',
        ], method: 'POST'),
        options: ['return_object' => true]
    );
    $beforeUpdate = $model->updateRecordFromRequest(
        has_api_model_behavior_request(['name' => 'Blocked update'], method: 'PATCH'),
        'record_alpha',
        fn () => response()->json(['blocked' => 'before-update'], 409)
    );
    $afterUpdate = $model->updateRecordFromRequest(
        has_api_model_behavior_request(['name' => 'After update'], method: 'PATCH'),
        'record_alpha',
        null,
        fn () => response()->json(['blocked' => 'after-update'], 202)
    );

    expect($beforeCreate)->toBeInstanceOf(JsonResponse::class)
        ->and($beforeCreate->getStatusCode())->toBe(409)
        ->and($beforeCreate->getData(true))->toBe(['blocked' => 'before-create'])
        ->and($afterCreate)->toBeInstanceOf(JsonResponse::class)
        ->and($afterCreate->getStatusCode())->toBe(202)
        ->and($afterCreate->getData(true))->toBe(['blocked' => 'after-create'])
        ->and($customCreated->uuid)->toBe('record-custom')
        ->and($customCreated->status)->toBe('custom-created')
        ->and($customCreated->company_uuid)->toBe('company-a')
        ->and($beforeUpdate)->toBeInstanceOf(JsonResponse::class)
        ->and($beforeUpdate->getStatusCode())->toBe(409)
        ->and($beforeUpdate->getData(true))->toBe(['blocked' => 'before-update'])
        ->and($afterUpdate)->toBeInstanceOf(JsonResponse::class)
        ->and($afterUpdate->getStatusCode())->toBe(202)
        ->and($afterUpdate->getData(true))->toBe(['blocked' => 'after-update'])
        ->and($capsule->getConnection('mysql')->table('api_model_behavior_records')->where('uuid', 'record-1')->value('name'))->toBe('After update');
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

    $model         = new HasApiModelBehaviorRecord();
    $found         = HasApiModelBehaviorRecord::findRecordOrFail('record_alpha');
    $safeMissing   = null;
    $invalidUpdate = null;

    try {
        HasApiModelBehaviorRecord::findRecordOrFail('record_beta');
    } catch (Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
        $safeMissing = $exception;
    }

    try {
        $model->updateRecordFromRequest(has_api_model_behavior_request(['unexpected' => 'blocked'], method: 'PATCH'), 'record_alpha');
    } catch (Exception $exception) {
        $invalidUpdate = $exception;
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
        ->and($safeMissing->getModel())->toBe(HasApiModelBehaviorRecord::class)
        ->and($invalidUpdate)->toBeInstanceOf(Exception::class)
        ->and($invalidUpdate->getMessage())->toBe('Invalid param "unexpected" in update request!');
});

test('api model behavior reports update persistence failures and propagates delete failures', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);
    session(['company' => 'company-a']);

    config(['app.debug' => true]);
    $debugFailure = null;
    try {
        (new HasApiModelBehaviorFailingUpdateRecord())->updateRecordFromRequest(
            has_api_model_behavior_request(['name' => 'Failed update'], method: 'PATCH'),
            'record_alpha'
        );
    } catch (Exception $exception) {
        $debugFailure = $exception;
    }

    config(['app.debug' => false]);
    $productionFailure = null;
    try {
        (new HasApiModelBehaviorFailingUpdateRecord())->updateRecordFromRequest(
            has_api_model_behavior_request(['name' => 'Failed update'], method: 'PATCH'),
            'record_alpha'
        );
    } catch (Exception $exception) {
        $productionFailure = $exception;
    }

    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('api_model_behavior_records');

    $bulkDeleteFailure = null;
    try {
        (new HasApiModelBehaviorFailingBulkDeleteRecord())->bulkRemove(['record_alpha']);
    } catch (Exception $exception) {
        $bulkDeleteFailure = $exception;
    }

    expect($debugFailure)->toBeInstanceOf(Exception::class)
        ->and($debugFailure->getMessage())->toBe('database update exploded')
        ->and($productionFailure)->toBeInstanceOf(Exception::class)
        ->and($productionFailure->getMessage())->toBe('Failed to update API Model Behavior Records')
        ->and($bulkDeleteFailure)->toBeInstanceOf(Exception::class)
        ->and($bulkDeleteFailure->getMessage())->toBe('bulk delete exploded')
        ->and(fn () => (new HasApiModelBehaviorRecord())->remove('record_alpha'))->toThrow(Exception::class);
});

test('api model behavior exposes default searchable fields options and no-op query branches', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);
    $capsule->getConnection('mysql')->table('api_model_behavior_records')->insert([
        [
            'uuid'            => 'record-blank',
            'public_id'       => 'record_blank',
            'company_uuid'    => 'company-a',
            'user_uuid'       => null,
            'created_by_uuid' => null,
            'updated_by_uuid' => null,
            'name'            => null,
            'status'          => null,
            'amount'          => 0,
            'slug'            => null,
            'deleted_at'      => null,
            'created_at'      => '2026-07-18 09:00:00',
            'updated_at'      => '2026-07-18 09:00:00',
        ],
    ]);

    $defaultSearch = new HasApiModelBehaviorDefaultSearchRecord();
    $model         = new HasApiModelBehaviorRecord();

    $plainBuilder = $model->searchBuilder(has_api_model_behavior_request());
    $sameBuilder  = $model->withRelationships(has_api_model_behavior_request(), HasApiModelBehaviorRecord::query());
    $countBuilder = $model->withCounts(has_api_model_behavior_request(), HasApiModelBehaviorRecord::query());
    $sortBuilder  = $model->applySorts(has_api_model_behavior_request(['sort' => ['', 'latest', 'oldest', 'amount:desc']]), HasApiModelBehaviorRecord::query());

    expect($defaultSearch->searcheableFields())->toContain('uuid', 'public_id', 'company_uuid', 'name', 'created_at', 'updated_at')
        ->and((new HasApiModelBehaviorOptionRecord())->getOptions())->toBe([
            ['value' => 'record-1', 'label' => 'Alpha Dispatch'],
            ['value' => 'record-2', 'label' => 'Beta Dispatch'],
            ['value' => 'record-3', 'label' => 'Gamma Dispatch'],
        ])
        ->and($plainBuilder->getQuery()->orders)->toBeNull()
        ->and($sameBuilder->getEagerLoads())->toBe([])
        ->and($countBuilder->getEagerLoads())->toBe([])
        ->and(array_map(fn ($order) => [$order['column'], $order['direction']], $sortBuilder->getQuery()->orders))->toBe([
            ['api_model_behavior_records.created_at', 'desc'],
            ['api_model_behavior_records.created_at', 'asc'],
            ['api_model_behavior_records.amount', 'desc'],
        ]);
});

test('api model behavior applies explicit filter operators and relation normalization branches', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);
    $capsule->getConnection('mysql')->table('api_model_behavior_records')->where('uuid', 'record-3')->update(['status' => null]);

    $model = new HasApiModelBehaviorRecord();

    $notInactive = $model->applyFilters(
        has_api_model_behavior_request(['filters' => ['status' => '_not:inactive']]),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->sort()->values()->all();

    $inAmounts = $model->applyFilters(
        has_api_model_behavior_request(['filters' => ['amount' => '_in:15,35']]),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->sort()->values()->all();

    $notInAmounts = $model->applyFilters(
        has_api_model_behavior_request(['filters' => ['amount' => '_notIn:25,35']]),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->sort()->values()->all();

    $directStatus = $model->applyFilters(
        has_api_model_behavior_request(['filters' => ['status' => 'active', 'unknown' => 'ignored']]),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->sort()->values()->all();

    $qualifiedUuid = $model->applyFilters(
        has_api_model_behavior_request(['filters' => ['uuid' => 'record-1']]),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->all();

    $nullStatuses = $model->buildSearchParams(
        has_api_model_behavior_request(['status_isNull' => '1', 'name' => '', 'unknown' => 'ignored']),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->sort()->values()->all();

    $notNullStatuses = $model->buildSearchParams(
        has_api_model_behavior_request(['status_isNotNull' => '1']),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->sort()->values()->all();

    $likeNames = $model->buildSearchParams(
        has_api_model_behavior_request(['name_like' => 'Alpha']),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->sort()->values()->all();

    $relationshipBuilder = $model->withRelationships(
        has_api_model_behavior_request([
            'with'    => ['child_items', 'child_items.grand_children'],
            'without' => ['child_items'],
        ]),
        HasApiModelBehaviorRecord::query()
    );

    $snakeRelationBuilder = (new HasApiModelBehaviorSnakeRelationRecord())->withRelationships(
        has_api_model_behavior_request(['with' => ['child_items']]),
        HasApiModelBehaviorSnakeRelationRecord::query()
    );

    $countBuilder = $model->withCounts(
        has_api_model_behavior_request(['with_count' => 'child_items']),
        HasApiModelBehaviorRecord::query()
    );

    expect($notInactive)->toBe(['record-1'])
        ->and($inAmounts)->toBe(['record-1', 'record-3'])
        ->and($notInAmounts)->toBe(['record-1'])
        ->and($directStatus)->toBe(['record-1'])
        ->and($qualifiedUuid)->toBe(['record-1'])
        ->and($nullStatuses)->toBe(['record-3'])
        ->and($notNullStatuses)->toBe(['record-1', 'record-2'])
        ->and($likeNames)->toBe(['record-1'])
        ->and(array_keys($relationshipBuilder->getEagerLoads()))->toBe(['childItems', 'childItems.grandChildren'])
        ->and($relationshipBuilder->getQuery()->columns)->toBeNull()
        ->and(array_keys($snakeRelationBuilder->getEagerLoads()))->toBe(['child_items'])
        ->and($countBuilder->toSql())->toContain('api_model_behavior_children', 'child_items_count');
});

test('api model behavior covers cached queries and create update response relation loading', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);
    config(['api.cache.enabled' => true]);
    session(['user' => 'session-user', 'company' => 'company-a']);

    $cachedRequest = has_api_model_behavior_request([
        'company_uuid' => 'company-a',
        'limit'        => 1,
    ]);
    $cachedRequest->setLaravelSession(new Store('api-model-cache', new ArraySessionHandler(120)));
    $cachedRequest->session()->put('company', 'company-a');

    $cachedResults = (new HasApiModelBehaviorCachedRecord())->queryFromRequest($cachedRequest);

    $created = (new HasApiModelBehaviorRecord())->createRecordFromRequest(has_api_model_behavior_request([
        'api_model_behavior_record' => [
            'uuid'      => 'record-created',
            'public_id' => 'record_created',
            'name'      => 'Created with relations',
        ],
        'with'                      => 'child_items',
        'with_count'                => ['childItems'],
    ], method: 'POST'));

    $updated = (new HasApiModelBehaviorRecord())->updateRecordFromRequest(has_api_model_behavior_request([
        'api_model_behavior_record' => [
            'name' => 'Updated with relations',
            'slug' => 'updated-slug',
        ],
        'with'                      => 'child_items',
        'with_count'                => ['childItems'],
    ], method: 'PATCH'), 'record_alpha', options: ['allow_slug_update' => true]);

    expect($cachedResults->pluck('uuid')->all())->toBe(['record-1'])
        ->and($created->uuid)->toBe('record-created')
        ->and($created->relationLoaded('childItems'))->toBeTrue()
        ->and($created->child_items_count)->toBe(0)
        ->and($updated->name)->toBe('Updated with relations')
        ->and($updated->slug)->toBe('updated-slug')
        ->and($updated->relationLoaded('childItems'))->toBeTrue()
        ->and($updated->child_items_count)->toBe(2);
});

test('api model behavior covers search remove internal id and validation branch contracts', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);

    EloquentBuilder::macro('fastPaginate', function (int $perPage = 15, array $columns = ['*']) {
        $total = $this->count();
        $items = $this->limit($perPage)->get($columns)->all();

        return new class($items, $total) {
            public function __construct(private array $items, private int $total)
            {
            }

            public function items(): array
            {
                return $this->items;
            }

            public function total(): int
            {
                return $this->total;
            }
        };
    });

    $model          = new HasApiModelBehaviorRecord();
    $searchResponse = $model->searchRecordFromRequest(has_api_model_behavior_request([
        'company_uuid' => 'company-a',
        'limit'        => 2,
    ]));
    $deleteCount    = $model->remove('record_alpha');
    $foundInternal  = HasApiModelBehaviorInternalIdRecord::findRecordOrFail('internal_beta', [], null, function ($query) {
        $query->where('company_uuid', 'company-a');
    });
    $emptyColumnFound = HasApiModelBehaviorInternalIdRecord::findRecordOrFail('record_beta', [], [], function ($query) {
        $query->where('company_uuid', 'company-a');
    });
    $sortBuilder = $model->applySorts(
        has_api_model_behavior_request(['sort' => ['records.name', 'count(name)', 'custom alias']]),
        HasApiModelBehaviorRecord::query()
    );

    expect($searchResponse->items())->toHaveCount(2)
        ->and($searchResponse->total())->toBe(2)
        ->and($deleteCount)->toBe(1)
        ->and($capsule->getConnection('mysql')->table('api_model_behavior_records')->where('uuid', 'record-1')->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and($foundInternal->uuid)->toBe('record-2')
        ->and($emptyColumnFound->uuid)->toBe('record-2')
        ->and((new HasApiModelBehaviorFilterParamRecord())->isInvalidUpdateParam('virtual_filter'))->toBeFalse()
        ->and((new HasApiModelBehaviorAppendedRecord())->isInvalidUpdateParam('computed_label'))->toBeFalse()
        ->and(array_map(fn ($order) => [$order['column'], $order['direction']], $sortBuilder->getQuery()->orders))->toBe([
            ['records.name', 'asc'],
            ['count(name)', 'asc'],
            ['custom alias', 'asc'],
        ]);
});

test('api model behavior covers cache gating direct counts and optimized filter edge contracts', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);
    $capsule->getConnection('mysql')->table('api_model_behavior_records')->where('uuid', 'record-3')->update(['status' => null]);
    config(['api.cache.enabled' => true]);

    $model          = new HasApiModelBehaviorRecord();
    $cachedModel    = new HasApiModelBehaviorCachedRecord();
    $disabledCached = new HasApiModelBehaviorDisabledCachedRecord();
    $probe          = new HasApiModelBehaviorProbeRecord();

    $noFilterBuilder = $probe->applyOptimizedFiltersForTest(
        has_api_model_behavior_request([]),
        HasApiModelBehaviorRecord::query()
    );
    $optimized = $probe->applyOptimizedFiltersForTest(
        has_api_model_behavior_request([
            'name'       => '',
            'status'     => '',
            'amount_gte' => '30',
            'unknown'    => 'ignored',
        ]),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->all();
    $directSearch = $model->buildSearchParams(
        has_api_model_behavior_request(['name' => 'Beta Dispatch']),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->all();
    $filteredCount           = $model->count(has_api_model_behavior_request(['status' => 'active']));
    $relationStrippedBuilder = $model->applyCustomFilters(
        has_api_model_behavior_request([
            'with'    => ['child_items'],
            'without' => ['child_items'],
        ]),
        HasApiModelBehaviorRecord::query()
    );

    expect($cachedModel->shouldUseCacheForTest())->toBeTrue()
        ->and($disabledCached->shouldUseCacheForTest())->toBeFalse()
        ->and($noFilterBuilder->toSql())->toBe(HasApiModelBehaviorRecord::query()->toSql())
        ->and($optimized)->toBe(['record-3'])
        ->and($directSearch)->toBe(['record-2'])
        ->and($filteredCount)->toBe(1)
        ->and($relationStrippedBuilder->getEagerLoads())->toBe([]);

    expect(fn () => $model->applyCustomFilters(
        has_api_model_behavior_request(['without_relations' => true]),
        HasApiModelBehaviorRecord::query()
    ))->toThrow(BadMethodCallException::class);
});

test('api model behavior invalidates tagged cache when soft deleted records are restored', function () {
    has_api_model_behavior_database();
    config(['api.cache.enabled' => true]);

    $cache  = app('cache');
    $record = HasApiModelBehaviorSoftDeletingCachedRecord::query()->create([
        'uuid'         => 'record-soft-delete-cache',
        'public_id'    => 'record_soft_delete_cache',
        'company_uuid' => 'company-1',
        'name'         => 'Soft delete cache',
    ]);

    $cache->put('api_model_behavior_records:model:record-soft-delete-cache', 'cached');

    $record->delete();
    $cache->put('api_model_behavior_records:model:record-soft-delete-cache', 'cached-again');
    $record->restore();

    expect($cache->has('api_model_behavior_records:model:record-soft-delete-cache'))->toBeFalse();
});

test('api model behavior covers custom filter precedence count and distance sort hooks', function () {
    $capsule = has_api_model_behavior_database();
    has_api_model_behavior_seed_records($capsule);

    EloquentBuilder::macro('filter', function (Filter $filter) {
        return $filter->apply($this);
    });
    EloquentBuilder::macro('orderByDistance', function () {
        return $this->orderBy('amount', 'desc');
    });

    $model      = new HasApiModelBehaviorRecord();
    $filtered   = (new HasApiModelBehaviorFilteredRecord())->applyCustomFilters(
        has_api_model_behavior_request(['status' => 'inactive']),
        HasApiModelBehaviorFilteredRecord::query()
    )->pluck('uuid')->all();
    $prioritized = (new HasApiModelBehaviorFilteredRecord())->prioritizedCustomColumnFilter(
        has_api_model_behavior_request(['status' => 'active']),
        HasApiModelBehaviorFilteredRecord::query(),
        'status'
    );
    $prioritizedFilterResult = (new HasApiModelBehaviorFilteredRecord())->applyFilters(
        has_api_model_behavior_request(['filters' => ['status' => 'active']]),
        HasApiModelBehaviorFilteredRecord::query()
    )->pluck('uuid')->all();
    $countBuilder = $model->withCounts(
        has_api_model_behavior_request(['with_count' => 'childItems']),
        HasApiModelBehaviorRecord::query()
    );
    $optimizedBuilder = $model->optimizeQuery(
        HasApiModelBehaviorRecord::query()
            ->where('status', 'active')
            ->where('status', 'active')
    );
    $distanceSort = $model->applySorts(
        has_api_model_behavior_request(['sort' => ['distance']]),
        HasApiModelBehaviorRecord::query()
    )->pluck('uuid')->all();

    expect($filtered)->toBe(['record-2'])
        ->and($prioritized)->toBeTrue()
        ->and($prioritizedFilterResult)->toBe(['record-1', 'record-2', 'record-3'])
        ->and($countBuilder->toSql())->toContain('child_items_count')
        ->and(substr_count($optimizedBuilder->toSql(), '"status" = ?'))->toBe(1)
        ->and($distanceSort)->toBe(['record-3', 'record-2', 'record-1']);
});
