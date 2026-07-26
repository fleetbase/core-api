<?php

use Fleetbase\Support\ApiModelCache;
use Fleetbase\Traits\HasApiModelCache;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;

class ApiModelCacheTestModel extends Model
{
    protected $table      = 'orders';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
}

class ApiModelCacheTraitTestModel extends Model
{
    use HasApiModelCache;

    protected $table      = 'orders';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $guarded    = [];

    public static array $mutatedRequestQueries = [];

    public function searchBuilder(Request $request, array $columns = ['*'])
    {
        $builder = static::query();

        if ($request->filled('status')) {
            $builder->where('status', $request->input('status'));
        }

        return $builder->orderBy('uuid');
    }

    public static function mutateModelWithRequest(Request $request, $result)
    {
        static::$mutatedRequestQueries[] = $request->query();

        return $result;
    }

    public function queryFromRequest(Request $request, ?Closure $queryCallback = null)
    {
        return $this->queryFromRequestWithoutCache($request, $queryCallback);
    }

    public function load($relations)
    {
        $this->setAttribute('loaded_relations', is_string($relations) ? [$relations] : $relations);

        return $this;
    }

    public function getCachedPayloadAttribute(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => 'payload for ' . $this->uuid,
        ];
    }
}

class ApiModelCacheTraitDisabledModel extends ApiModelCacheTraitTestModel
{
    public bool $disableApiCache = true;
}

class ApiModelCacheSoftDeletingTraitTestModel extends ApiModelCacheTraitTestModel
{
    use SoftDeletes;
}

class ApiModelCacheTestLock
{
    public function __construct(private ApiModelCacheTestStore $store)
    {
    }

    public function block(int $seconds, Closure $callback)
    {
        if ($this->store->lockReturnsNull) {
            return null;
        }

        return $callback();
    }
}

class ApiModelCacheTestTaggedStore
{
    public function __construct(private ApiModelCacheTestStore $store, private array $tags)
    {
    }

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        if (!$this->has($key)) {
            $this->store->putTagged($this->tags, $key, $callback());
        }

        return $this->get($key);
    }

    public function has(string $key): bool
    {
        return $this->store->hasTagged($this->tags, $key);
    }

    public function get(string $key): mixed
    {
        return $this->store->getTagged($this->tags, $key);
    }

    public function forget(string $key): bool
    {
        $this->store->forgotten[] = ['tags' => $this->tags, 'key' => $key];
        $this->store->forgetTagged($this->tags, $key);

        return true;
    }

    public function flush(): bool
    {
        $this->store->flushedTags[] = $this->tags;
        $this->store->flushTagged($this->tags);

        return true;
    }
}

class ApiModelCacheTestStore
{
    public array $values         = [];
    public array $taggedValues   = [];
    public array $flushedTags    = [];
    public array $forgotten      = [];
    public bool $throwOnTags     = false;
    public bool $lockReturnsNull = false;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function increment(string $key): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;

        return $this->values[$key];
    }

    public function lock(string $key, int $seconds): ApiModelCacheTestLock
    {
        return new ApiModelCacheTestLock($this);
    }

    public function tags(array $tags): ApiModelCacheTestTaggedStore
    {
        if ($this->throwOnTags) {
            throw new RuntimeException('tag backend unavailable');
        }

        return new ApiModelCacheTestTaggedStore($this, $tags);
    }

    public function putTagged(array $tags, string $key, mixed $value): void
    {
        $this->taggedValues[$this->tagKey($tags)][$key] = $value;
    }

    public function getTagged(array $tags, string $key): mixed
    {
        return $this->taggedValues[$this->tagKey($tags)][$key] ?? null;
    }

    public function hasTagged(array $tags, string $key): bool
    {
        return array_key_exists($key, $this->taggedValues[$this->tagKey($tags)] ?? []);
    }

    public function forgetTagged(array $tags, string $key): void
    {
        unset($this->taggedValues[$this->tagKey($tags)][$key]);
    }

    public function flushTagged(array $tags): void
    {
        unset($this->taggedValues[$this->tagKey($tags)]);
    }

    private function tagKey(array $tags): string
    {
        return implode('|', $tags);
    }
}

class ApiModelCacheUserResolver
{
    public function __construct(public ?string $company_uuid)
    {
    }

    public function company_uuid(): ?string
    {
        return $this->company_uuid;
    }
}

class ApiModelCacheTestLogger
{
    public array $errors = [];

    public function error(string $message, array $context = []): void
    {
        $this->errors[] = compact('message', 'context');
    }

    public function warning(string $message, array $context = []): void
    {
    }
}

function api_model_cache_fixture(array $config = []): ApiModelCacheTestStore
{
    $store = new ApiModelCacheTestStore();

    bind_test_container(array_replace([
        'api.cache.enabled'          => true,
        'api.cache.ttl.query'        => 111,
        'api.cache.ttl.model'        => 222,
        'api.cache.ttl.relationship' => 333,
        'cache.default'              => 'array',
    ], $config));

    app()->instance('cache', $store);
    Facade::clearResolvedInstance('cache');
    ApiModelCache::resetCacheStatus();

    return $store;
}

function api_model_cache_model(string $uuid = 'order-1', ?string $companyUuid = 'company-1'): ApiModelCacheTestModel
{
    $model = new ApiModelCacheTestModel();
    $model->setAttribute('uuid', $uuid);
    if ($companyUuid !== null) {
        $model->setAttribute('company_uuid', $companyUuid);
    }
    $model->exists = true;

    return $model;
}

function api_model_cache_request(array $query = [], mixed $company = 'company-1'): Request
{
    $request = Request::create('/int/v1/orders', 'GET', $query);
    $session = new Store('testing', new ApiModelCacheArraySessionHandler());
    if ($company !== null) {
        $session->put('company', $company);
    }
    $request->setLaravelSession($session);

    return $request;
}

function api_model_cache_internal_request(array $query = [], mixed $company = 'company-1'): Request
{
    $request = api_model_cache_request($query, $company);
    $request->setRouteResolver(fn () => new class {
        public array $action = [];

        public function uri(): string
        {
            return 'int/v1/orders';
        }
    });

    return $request;
}

function api_model_cache_trait_database(): Capsule
{
    Model::clearBootedModels();
    Model::unsetEventDispatcher();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'          => true,
        'api.cache.ttl.query'        => 111,
        'api.cache.ttl.model'        => 222,
        'api.cache.ttl.relationship' => 333,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $store = new ApiModelCacheTestStore();
    $container->instance('cache', $store);
    Facade::clearResolvedInstance('cache');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Model::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');
    ApiModelCache::resetCacheStatus();
    ApiModelCacheTraitTestModel::$mutatedRequestQueries = [];

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    $capsule->getConnection('mysql')->table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_public_1', 'company_uuid' => 'company-1', 'status' => 'active'],
        ['uuid' => 'order-2', 'public_id' => 'order_public_2', 'company_uuid' => 'company-1', 'status' => 'active'],
        ['uuid' => 'order-3', 'public_id' => 'order_public_3', 'company_uuid' => 'company-1', 'status' => 'inactive'],
    ]);

    return $capsule;
}

class ApiModelCacheArraySessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return '';
    }

    public function write(string $id, string $data): bool
    {
        return true;
    }

    public function destroy(string $id): bool
    {
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }
}

test('api model cache generates tenant scoped stable query model and relationship keys', function () {
    $store = api_model_cache_fixture();
    $store->put('api_query_version:orders:company-1', 7);
    $model = api_model_cache_model();

    $first = ApiModelCache::generateQueryCacheKey($model, api_model_cache_request([
        'status' => 'active',
        'limit'  => '50',
        '_'      => 'cache-bust',
        'empty'  => null,
    ]), ['callback' => true]);

    $second = ApiModelCache::generateQueryCacheKey($model, api_model_cache_request([
        'limit'     => '50',
        'timestamp' => 'ignore-me',
        'status'    => 'active',
        'nocache'   => 1,
    ]), ['callback' => true]);

    expect($first)->toBe($second)
        ->and($first)->toStartWith('{api_query}:orders:company_company-1:v7:')
        ->and(ApiModelCache::generateModelCacheKey($model, 'order-1', ['customer', 'payload']))
        ->toBe('{api_model}:orders:order-1:' . md5(json_encode(['customer', 'payload'])))
        ->and(ApiModelCache::generateRelationshipCacheKey($model, 'payload'))
        ->toBe('{api_relation}:orders:order-1:payload')
        ->and(ApiModelCache::generateCacheTags($model, 'company-1', true))
        ->toBe(['api_cache', 'api_model:orders', 'api_query:orders', 'company:company-1']);
});

test('api model cache stores query results with miss hit status and disabled bypass behavior', function () {
    api_model_cache_fixture();
    $calls   = 0;
    $model   = api_model_cache_model();
    $request = api_model_cache_request(['status' => 'active']);

    $first = ApiModelCache::cacheQueryResult($model, $request, function () use (&$calls) {
        $calls++;

        return collect(['fresh-result']);
    });

    expect($first->all())->toBe(['fresh-result'])
        ->and($calls)->toBe(1)
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and(ApiModelCache::getCacheKey())->toStartWith('{api_query}:orders:company_company-1:v1:');

    $second = ApiModelCache::cacheQueryResult($model, $request, function () use (&$calls) {
        $calls++;

        return collect(['unexpected']);
    });

    expect($second->all())->toBe(['fresh-result'])
        ->and($calls)->toBe(1)
        ->and(ApiModelCache::getCacheStatus())->toBe('HIT');

    api_model_cache_fixture(['api.cache.enabled' => false]);
    $bypassed = ApiModelCache::cacheQueryResult($model, $request, fn () => null);

    expect($bypassed->all())->toBe([]);
});

test('api model cache handles query lock timeouts from cached fallback and direct callback', function () {
    $store   = api_model_cache_fixture();
    $model   = api_model_cache_model();
    $request = api_model_cache_request(['status' => 'active']);
    $key     = ApiModelCache::generateQueryCacheKey($model, $request);
    $tags    = ApiModelCache::generateCacheTags($model, 'company-1', true);

    $store->lockReturnsNull = true;
    $store->putTagged($tags, $key, collect(['from-existing-cache']));
    $calls = 0;

    $cached = ApiModelCache::cacheQueryResult($model, $request, function () use (&$calls) {
        $calls++;

        return collect(['unexpected']);
    });

    expect($cached->all())->toBe(['from-existing-cache'])
        ->and($calls)->toBe(0)
        ->and(ApiModelCache::getCacheStatus())->toBe('HIT');

    $uncachedRequest = api_model_cache_request(['status' => 'inactive']);
    $missed          = ApiModelCache::cacheQueryResult($model, $uncachedRequest, function () use (&$calls) {
        $calls++;

        return null;
    });

    expect($missed->all())->toBe([])
        ->and($calls)->toBe(1)
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS');
});

test('api model cache stores model and relationship lookups with status tracking', function () {
    api_model_cache_fixture();
    $model      = api_model_cache_model();
    $modelCalls = 0;

    $firstModel = ApiModelCache::cacheModel($model, 'order-1', function () use (&$modelCalls) {
        $modelCalls++;

        return ['uuid' => 'order-1'];
    }, ['payload']);

    $secondModel = ApiModelCache::cacheModel($model, 'order-1', function () use (&$modelCalls) {
        $modelCalls++;

        return ['uuid' => 'unexpected'];
    }, ['payload']);

    expect($firstModel)->toBe(['uuid' => 'order-1'])
        ->and($secondModel)->toBe(['uuid' => 'order-1'])
        ->and($modelCalls)->toBe(1)
        ->and(ApiModelCache::getCacheStatus())->toBe('HIT')
        ->and(ApiModelCache::getCacheKey())->toStartWith('{api_model}:orders:order-1:');

    ApiModelCache::resetCacheStatus();
    $relationshipCalls = 0;
    $firstRelation     = ApiModelCache::cacheRelationship($model, 'payload', function () use (&$relationshipCalls) {
        $relationshipCalls++;

        return ['uuid' => 'payload-1'];
    });
    $secondRelation = ApiModelCache::cacheRelationship($model, 'payload', function () use (&$relationshipCalls) {
        $relationshipCalls++;

        return ['uuid' => 'unexpected'];
    });

    expect($firstRelation)->toBe(['uuid' => 'payload-1'])
        ->and($secondRelation)->toBe(['uuid' => 'payload-1'])
        ->and($relationshipCalls)->toBe(1)
        ->and(ApiModelCache::getCacheStatus())->toBe('HIT')
        ->and(ApiModelCache::getCacheKey())->toBe('{api_relation}:orders:order-1:payload');
});

test('api model cache bypasses model relationship and invalidation work when disabled', function () {
    $store             = api_model_cache_fixture(['api.cache.enabled' => false]);
    $model             = api_model_cache_model();
    $modelCalls        = 0;
    $relationshipCalls = 0;

    $modelResult = ApiModelCache::cacheModel($model, 'order-1', function () use (&$modelCalls) {
        $modelCalls++;

        return ['uuid' => 'direct-model'];
    });

    $relationshipResult = ApiModelCache::cacheRelationship($model, 'payload', function () use (&$relationshipCalls) {
        $relationshipCalls++;

        return ['uuid' => 'direct-relation'];
    });

    ApiModelCache::invalidateModelCache($model, 'company-1');
    ApiModelCache::invalidateQueryCache($model, api_model_cache_request(['status' => 'active']));
    ApiModelCache::invalidateCompanyCache('company-1');
    ApiModelCache::warmCache($model, api_model_cache_request(['status' => 'active']), fn () => collect(['ignored']));

    expect($modelResult)->toBe(['uuid' => 'direct-model'])
        ->and($relationshipResult)->toBe(['uuid' => 'direct-relation'])
        ->and($modelCalls)->toBe(1)
        ->and($relationshipCalls)->toBe(1)
        ->and($store->values)->toBe([])
        ->and($store->taggedValues)->toBe([])
        ->and($store->flushedTags)->toBe([])
        ->and($store->forgotten)->toBe([]);
});

test('api model cache invalidates scoped query and model caches and advances query versions', function () {
    $store   = api_model_cache_fixture();
    $model   = api_model_cache_model();
    $request = api_model_cache_request(['status' => 'active']);

    ApiModelCache::cacheQueryResult($model, $request, fn () => collect(['cached']));
    expect(ApiModelCache::getCacheStatus())->toBe('MISS');

    ApiModelCache::invalidateModelCache($model, 'company-1');

    expect(ApiModelCache::getCacheStatus())->toBeNull()
        ->and(ApiModelCache::getCacheKey())->toBeNull()
        ->and($store->get('api_query_version:orders:company-1'))->toBe(1)
        ->and($store->flushedTags)->toContain(['api_cache', 'api_model:orders', 'company:company-1'])
        ->and($store->flushedTags)->toContain(['api_cache', 'api_model:orders', 'api_query:orders', 'company:company-1']);

    ApiModelCache::invalidateQueryCache($model, $request);

    expect($store->forgotten[0]['tags'])->toBe(['api_cache', 'api_model:orders', 'api_query:orders', 'company:company-1'])
        ->and($store->forgotten[0]['key'])->toStartWith('{api_query}:orders:company_company-1:v1:');

    ApiModelCache::invalidateCompanyCache('company-1');

    expect($store->flushedTags)->toContain(['company:company-1']);
});

test('api model cache resolves tenant scope from authenticated user and request input fallbacks', function () {
    api_model_cache_fixture();
    $model = api_model_cache_model();

    $userRequest = api_model_cache_request(['status' => 'active'], null);
    $userRequest->setUserResolver(fn () => new ApiModelCacheUserResolver('company-from-user'));

    $inputRequest = api_model_cache_request([
        'status'       => 'active',
        'company_uuid' => 'company-from-input',
    ], null);

    expect(ApiModelCache::generateQueryCacheKey($model, $userRequest))
        ->toStartWith('{api_query}:orders:company_company-from-user:v1:')
        ->and(ApiModelCache::generateQueryCacheKey($model, $inputRequest))
        ->toStartWith('{api_query}:orders:company_company-from-input:v1:');
});

test('api model cache invalidation methods swallow tagged cache backend failures', function () {
    $store              = api_model_cache_fixture();
    $store->throwOnTags = true;
    $model              = api_model_cache_model();
    $request            = api_model_cache_request(['status' => 'active']);

    ApiModelCache::invalidateModelCache($model, 'company-1');
    ApiModelCache::invalidateQueryCache($model, $request);
    ApiModelCache::invalidateCompanyCache('company-1');

    expect($store->get('api_query_version:orders:company-1'))->toBe(1)
        ->and($store->flushedTags)->toBe([])
        ->and($store->forgotten)->toBe([]);
});

test('api model cache falls back to callbacks when tagged cache operations fail', function () {
    $store              = api_model_cache_fixture();
    $store->throwOnTags = true;
    $model              = api_model_cache_model();

    $queryResult = ApiModelCache::cacheQueryResult(
        $model,
        api_model_cache_request(['status' => 'active']),
        fn () => collect(['fallback'])
    );

    expect($queryResult->all())->toBe(['fallback'])
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS');

    $modelResult = ApiModelCache::cacheModel($model, 'order-1', fn () => ['fallback' => true]);

    expect($modelResult)->toBe(['fallback' => true])
        ->and(ApiModelCache::getCacheStatus())->toBe('ERROR')
        ->and(ApiModelCache::getCacheKey())->toBe('{api_model}:orders:order-1');

    $relationshipResult = ApiModelCache::cacheRelationship($model, 'payload', fn () => ['relationship' => true]);

    expect($relationshipResult)->toBe(['relationship' => true])
        ->and(ApiModelCache::getCacheStatus())->toBe('ERROR')
        ->and(ApiModelCache::getCacheKey())->toBe('{api_relation}:orders:order-1:payload');
});

test('api model cache warmup logs failures without leaking exceptions', function () {
    api_model_cache_fixture();
    $logger = new ApiModelCacheTestLogger();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');

    $model = api_model_cache_model();

    ApiModelCache::warmCache($model, api_model_cache_request(['status' => 'active']), function () {
        throw new RuntimeException('warmup failed');
    });

    expect($logger->errors)->toHaveCount(1)
        ->and($logger->errors[0])->toBe([
            'message' => 'Failed to warm up cache',
            'context' => [
                'model' => ApiModelCacheTestModel::class,
                'error' => 'warmup failed',
            ],
        ]);
});

test('has api model cache caches request queries with callback markers page offsets and mutation hooks', function () {
    api_model_cache_trait_database();
    $model   = new ApiModelCacheTraitTestModel();
    $request = api_model_cache_request([
        'status' => 'active',
        'limit'  => 1,
        'page'   => 2,
    ]);
    $callbackCalls = 0;

    $first = $model->queryFromRequestCached($request, function ($builder) use (&$callbackCalls) {
        $callbackCalls++;
        $builder->where('company_uuid', 'company-1');
    });
    $second = ApiModelCacheTraitTestModel::queryWithRequestCached($request, function ($builder) use (&$callbackCalls) {
        $callbackCalls++;
        $builder->whereRaw('1 = 0');
    });

    expect($first->pluck('uuid')->all())->toBe(['order-2'])
        ->and($second->pluck('uuid')->all())->toBe(['order-2'])
        ->and($callbackCalls)->toBe(1)
        ->and(ApiModelCache::getCacheStatus())->toBe('HIT')
        ->and(ApiModelCache::getCacheKey())->toStartWith('{api_query}:orders:company_company-1:v1:')
        ->and(ApiModelCacheTraitTestModel::$mutatedRequestQueries)->toHaveCount(1)
        ->and(ApiModelCacheTraitTestModel::$mutatedRequestQueries[0])->toMatchArray([
            'status' => 'active',
            'limit'  => 1,
            'page'   => 2,
        ]);
});

test('has api model cache preserves explicit unlimited limits for public collection queries', function () {
    api_model_cache_trait_database();

    $result = (new ApiModelCacheTraitTestModel())->queryFromRequestCached(api_model_cache_request([
        'status' => 'active',
        'limit'  => -1,
    ]));

    expect($result->pluck('uuid')->all())->toBe(['order-1', 'order-2'])
        ->and(ApiModelCacheTraitTestModel::$mutatedRequestQueries[0])->toMatchArray([
            'status' => 'active',
            'limit'  => -1,
        ]);
});

test('has api model cache covers disabled lookups internal pagination relation no ops and warmup bypasses', function () {
    $capsule = api_model_cache_trait_database();
    api_model_cache_fixture(['api.cache.enabled' => false]);

    EloquentBuilder::macro('fastPaginate', function (int $perPage = 15, array $columns = ['*']) {
        $items = $this->limit($perPage)->get($columns)->all();

        return new class($items, $perPage) {
            public function __construct(private array $items, private int $perPage)
            {
            }

            public function items(): array
            {
                return $this->items;
            }

            public function perPage(): int
            {
                return $this->perPage;
            }
        };
    });

    $byId       = ApiModelCacheTraitTestModel::findCached('order-1', ['cached_payload']);
    $byPublicId = ApiModelCacheTraitTestModel::findByPublicIdCached('order_public_2', ['cached_payload']);

    $model = new ApiModelCacheTraitTestModel([
        'uuid'         => 'order-disabled',
        'company_uuid' => 'company-1',
    ]);
    $model->exists = true;
    $model->loadCached('cached_payload');
    $model->loadMultipleCached('cached_payload', 'other_payload');
    $model->invalidateQueryCache(api_model_cache_request(['status' => 'active']));
    ApiModelCacheTraitTestModel::warmUpCache(api_model_cache_request(['status' => 'active']));

    expect($byId?->uuid)->toBe('order-1')
        ->and($byId?->loaded_relations)->toBe(['cached_payload'])
        ->and($byPublicId?->uuid)->toBe('order-2')
        ->and($byPublicId?->loaded_relations)->toBe(['cached_payload'])
        ->and($model->loaded_relations)->toBe(['cached_payload'])
        ->and(app('cache')->taggedValues)->toBe([]);

    api_model_cache_fixture();
    $internal = (new ApiModelCacheTraitTestModel())->queryFromRequestCached(api_model_cache_internal_request([
        'status' => 'active',
        'limit'  => 1,
        'page'   => 2,
    ]));

    $loaded = new ApiModelCacheTraitTestModel([
        'uuid'         => 'order-loaded',
        'company_uuid' => 'company-1',
    ]);
    $loaded->exists = true;
    $loaded->setRelation('cached_payload', ['already' => true]);

    expect($internal->items()[0]->uuid)->toBe('order-2')
        ->and($internal->perPage())->toBe(1)
        ->and($loaded->loadCached('cached_payload'))->toBe($loaded)
        ->and($loaded->getRelation('cached_payload'))->toBe(['already' => true]);

    $capsule->getConnection('mysql')->disconnect();
});

test('has api model cache boot hooks invalidate cache on model lifecycle events', function () {
    api_model_cache_trait_database();
    $store = app('cache');

    Model::setEventDispatcher(new Dispatcher(app()));
    Model::clearBootedModels();

    $model = ApiModelCacheTraitTestModel::create([
        'uuid'         => 'order-event',
        'public_id'    => 'order_public_event',
        'company_uuid' => 'company-1',
        'status'       => 'active',
    ]);

    $model->status = 'inactive';
    $model->save();
    $model->delete();

    $softDeletingModel = ApiModelCacheSoftDeletingTraitTestModel::create([
        'uuid'         => 'order-restored-event',
        'public_id'    => 'order_public_restored_event',
        'company_uuid' => 'company-1',
        'status'       => 'active',
    ]);
    $softDeletingModel->delete();
    $softDeletingModel->restore();

    expect($store->flushedTags)->toContain(['api_cache', 'api_model:orders', 'company:company-1'])
        ->and($store->flushedTags)->toContain(['api_cache', 'api_model:orders', 'api_query:orders', 'company:company-1']);

    Model::unsetEventDispatcher();
});

test('has api model cache wraps id public id relationship invalidation and stats helpers', function () {
    $capsule = api_model_cache_trait_database();
    $store   = app('cache');

    $byIdWithRelation = ApiModelCacheTraitTestModel::findCached('order-1', ['cached_payload']);
    $byId             = ApiModelCacheTraitTestModel::findCached('order-1');
    $capsule->getConnection('mysql')->table('orders')->where('uuid', 'order-1')->delete();
    $cachedById = ApiModelCacheTraitTestModel::findCached('order-1');

    $byPublicIdWithRelation = ApiModelCacheTraitTestModel::findByPublicIdCached('order_public_2', ['cached_payload']);
    $byPublicId             = ApiModelCacheTraitTestModel::findByPublicIdCached('order_public_2');
    $capsule->getConnection('mysql')->table('orders')->where('uuid', 'order-2')->delete();
    $cachedByPublicId = ApiModelCacheTraitTestModel::findByPublicIdCached('order_public_2');

    $model = new ApiModelCacheTraitTestModel([
        'uuid'         => 'order-relationship',
        'company_uuid' => 'company-1',
    ]);
    $model->exists = true;
    $model->loadCached('cached_payload');
    $firstRelation = $model->getRelation('cached_payload');
    $model->unsetRelation('cached_payload');
    $model->loadMultipleCached(['cached_payload']);

    $model->invalidateApiCache();
    $model->invalidateQueryCache(api_model_cache_request(['status' => 'active']));
    ApiModelCacheTraitTestModel::invalidateApiCacheManually('company-1');
    ApiModelCacheTraitTestModel::warmUpCache(api_model_cache_request(['status' => 'inactive']));

    expect($byIdWithRelation?->loaded_relations)->toBe(['cached_payload'])
        ->and($byId?->uuid)->toBe('order-1')
        ->and($cachedById?->uuid)->toBe('order-1')
        ->and($byPublicIdWithRelation?->loaded_relations)->toBe(['cached_payload'])
        ->and($byPublicId?->uuid)->toBe('order-2')
        ->and($cachedByPublicId?->uuid)->toBe('order-2')
        ->and($firstRelation)->toBe([
            'uuid' => 'order-relationship',
            'name' => 'payload for order-relationship',
        ])
        ->and($model->getRelation('cached_payload'))->toBe($firstRelation)
        ->and($store->flushedTags)->toContain(['api_cache', 'api_model:orders', 'company:company-1'])
        ->and($store->flushedTags)->toContain(['api_cache', 'api_model:orders', 'api_query:orders', 'company:company-1'])
        ->and($store->forgotten[0]['key'])->toStartWith('{api_query}:orders:company_company-1:')
        ->and(ApiModelCacheTraitTestModel::getCacheStats())->toMatchArray([
            'enabled' => true,
            'driver'  => 'array',
            'ttl'     => [
                'query'        => 111,
                'model'        => 222,
                'relationship' => 333,
            ],
        ])
        ->and((new ApiModelCacheTraitTestModel())->isCachingEnabled())->toBeTrue()
        ->and((new ApiModelCacheTraitDisabledModel())->isCachingEnabled())->toBeFalse();
});
