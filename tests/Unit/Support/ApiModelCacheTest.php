<?php

use Fleetbase\Support\ApiModelCache;
use Fleetbase\Traits\HasApiModelCache;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
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

class ApiModelCacheTestLock
{
    public function block(int $seconds, Closure $callback)
    {
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
    public array $values       = [];
    public array $taggedValues = [];
    public array $flushedTags  = [];
    public array $forgotten    = [];
    public bool $throwOnTags   = false;

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
        return new ApiModelCacheTestLock();
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

test('has api model cache wraps id public id relationship invalidation and stats helpers', function () {
    $capsule = api_model_cache_trait_database();
    $store   = app('cache');

    $byId = ApiModelCacheTraitTestModel::findCached('order-1');
    $capsule->getConnection('mysql')->table('orders')->where('uuid', 'order-1')->delete();
    $cachedById = ApiModelCacheTraitTestModel::findCached('order-1');

    $byPublicId = ApiModelCacheTraitTestModel::findByPublicIdCached('order_public_2');
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

    expect($byId?->uuid)->toBe('order-1')
        ->and($cachedById?->uuid)->toBe('order-1')
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
