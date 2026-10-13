<?php

use Fleetbase\Support\ApiModelCache;
use Fleetbase\Traits\HasApiModelCache;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
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

class ApiModelCacheDependencyPayload extends Model
{
    use HasApiModelCache;

    protected $table      = 'payloads';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $guarded    = [];
}

class ApiModelCacheDependencyTag extends Model
{
    protected $table      = 'tags';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $guarded    = [];
}

class ApiModelCacheDependencyOrderTag extends Pivot
{
    use HasApiModelCache;

    protected $table   = 'order_tags';
    public $timestamps = false;
}

class ApiModelCacheRelationsOrder extends ApiModelCacheTraitTestModel
{
    public static int $sideEffects = 0;

    public function payload(): BelongsTo
    {
        return $this->belongsTo(ApiModelCacheDependencyPayload::class, 'payload_uuid', 'uuid');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ApiModelCacheDependencyTag::class, 'order_tags', 'order_uuid', 'tag_uuid')
            ->using(ApiModelCacheDependencyOrderTag::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function tagPayloads(): HasManyThrough
    {
        return $this->hasManyThrough(ApiModelCacheDependencyPayload::class, ApiModelCacheDependencyTag::class, 'order_uuid', 'tag_uuid', 'uuid', 'uuid');
    }

    public function reconnectingPayload(): BelongsTo
    {
        $this->setConnection('mysql');

        return $this->belongsTo(ApiModelCacheDependencyPayload::class, 'payload_uuid', 'uuid');
    }

    public function summary(): string
    {
        return 'not a relation';
    }

    // An untyped relation: declarations may name it, client paths may not
    public function untypedPayload()
    {
        return $this->belongsTo(ApiModelCacheDependencyPayload::class, 'payload_uuid', 'uuid');
    }

    // Shaped like Order::dispatch(): public, no required arguments, side effects
    public function dispatch(bool $save = true)
    {
        static::$sideEffects++;

        return $this;
    }

    public function exploding(): BelongsTo
    {
        throw new RuntimeException('needs a loaded model');
    }
}

class ApiModelCacheDependencyOrder extends ApiModelCacheRelationsOrder
{
    protected $apiCacheDependencies = ['payload', 'tags', 'audit_logs'];
}

class ApiModelCacheUntypedDependencyOrder extends ApiModelCacheRelationsOrder
{
    protected $apiCacheDependencies = ['untypedPayload'];
}

class ApiModelCacheExplodingDependencyOrder extends ApiModelCacheRelationsOrder
{
    protected $apiCacheDependencies = ['tags', 'exploding'];
}

class ApiModelCacheMorphDependencyOrder extends ApiModelCacheRelationsOrder
{
    protected $apiCacheDependencies = ['subject'];
}

class ApiModelCacheUnresolvableDependencyOrder extends ApiModelCacheRelationsOrder
{
    protected $apiCacheDependencies = ['payload.customer'];
}

class ApiModelCacheNotRelationDependencyOrder extends ApiModelCacheRelationsOrder
{
    protected $apiCacheDependencies = ['summary'];
}

class ApiModelCachePartlyResolvableDependencyOrder extends ApiModelCacheRelationsOrder
{
    protected $apiCacheDependencies = ['tags', 'payload.customer'];
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
    public array $values                 = [];
    public array $taggedValues           = [];
    public array $flushedTags            = [];
    public array $forgotten              = [];
    public bool $throwOnTags             = false;
    public bool $throwOnMany             = false;
    public bool $throwOnIncrement        = false;
    public bool $lockReturnsNull         = false;
    public bool $flushRemovesValues      = true;
    public bool $incrementCreatesMissing = true;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function many(array $keys): array
    {
        if ($this->throwOnMany) {
            throw new RuntimeException('cache unavailable');
        }

        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->values[$key] ?? null;
        }

        return $values;
    }

    public function put(string $key, mixed $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function add(string $key, mixed $value): bool
    {
        if (array_key_exists($key, $this->values)) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
    }

    public function increment(string $key): int|false
    {
        if ($this->throwOnIncrement) {
            throw new RuntimeException('cache unavailable');
        }

        if (!$this->incrementCreatesMissing && !array_key_exists($key, $this->values)) {
            return false;
        }

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
        // Laravel 10 RedisTaggedCache on phpredis >= 6.1 drops the tag sets but not the values
        if ($this->flushRemovesValues) {
            unset($this->taggedValues[$this->tagKey($tags)]);
        }
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
        'api.cache.scope.session'    => null,
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

function api_model_cache_scoped_request(string $path, array $session, array $query = []): Request
{
    $request = Request::create($path, 'GET', $query);
    $store   = new Store('testing', new ApiModelCacheArraySessionHandler());
    $store->put($session);
    $request->setLaravelSession($store);

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

function api_model_cache_dependency_database(): Capsule
{
    $capsule = api_model_cache_trait_database();
    $schema  = $capsule->getConnection('mysql')->getSchemaBuilder();

    $schema->table('orders', fn ($table) => $table->string('payload_uuid')->nullable());
    $schema->create('payloads', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
    $schema->create('tags', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
    });
    $schema->create('order_tags', function ($table) {
        $table->string('order_uuid');
        $table->string('tag_uuid');
    });

    return $capsule;
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
        ->and($first)->toStartWith('{api_query}:orders:company_company-1:v0.7:')
        ->and(ApiModelCache::generateModelCacheKey($model, 'order-1', ['customer', 'payload']))
        ->toBe('{api_model}:orders:v0:order-1:' . md5(json_encode(['customer', 'payload'])))
        ->and(ApiModelCache::generateRelationshipCacheKey($model, 'payload'))
        ->toBe('{api_relation}:orders:v0:order-1:payload')
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
        ->and(ApiModelCache::getCacheKey())->toStartWith('{api_query}:orders:company_company-1:v0.0:');

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
        ->and(ApiModelCache::getCacheKey())->toStartWith('{api_model}:orders:v0:order-1:');

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
        ->and(ApiModelCache::getCacheKey())->toBe('{api_relation}:orders:v0:order-1:payload');
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
        ->and($store->get('api_model_version:orders'))->toBe(1)
        ->and($store->flushedTags)->toContain(['api_cache', 'api_model:orders', 'company:company-1'])
        ->and($store->flushedTags)->toContain(['api_cache', 'api_model:orders', 'api_query:orders', 'company:company-1']);

    ApiModelCache::invalidateQueryCache($model, $request);

    expect($store->forgotten[0]['tags'])->toBe(['api_cache', 'api_model:orders', 'api_query:orders', 'company:company-1'])
        ->and($store->forgotten[0]['key'])->toStartWith('{api_query}:orders:company_company-1:v0.1:');

    ApiModelCache::invalidateCompanyCache('company-1');

    expect($store->flushedTags)->toContain(['company:company-1']);
});

test('api model cache does not serve a list cached before the first invalidation when the tag flush leaves it behind', function () {
    $store                     = api_model_cache_fixture();
    $store->flushRemovesValues = false;
    $model                     = api_model_cache_model();
    $request                   = api_model_cache_request(['status' => 'active']);

    expect(ApiModelCache::cacheQueryResult($model, $request, fn () => collect(['stale'])))->toEqual(collect(['stale']));
    $keyBeforeInvalidation = ApiModelCache::getCacheKey();

    ApiModelCache::invalidateModelCache($model, 'company-1');

    expect(ApiModelCache::cacheQueryResult($model, $request, fn () => collect(['fresh'])))->toEqual(collect(['fresh']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and(ApiModelCache::getCacheKey())->not->toBe($keyBeforeInvalidation)
        ->and($store->get('api_query_version:orders:company-1'))->toBe(1);

    ApiModelCache::invalidateModelCache($model, 'company-1');

    expect(ApiModelCache::cacheQueryResult($model, $request, fn () => collect(['fresher'])))->toEqual(collect(['fresher']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and($store->get('api_query_version:orders:company-1'))->toBe(2);
});

test('api model cache does not serve model or relationship lookups cached before an invalidation when the tag flush leaves them behind', function () {
    $store                     = api_model_cache_fixture();
    $store->flushRemovesValues = false;
    $model                     = api_model_cache_model();

    ApiModelCache::cacheModel($model, 'order-1', fn () => ['status' => 'stale'], ['payload']);
    ApiModelCache::cacheRelationship($model, 'payload', fn () => ['name' => 'stale']);
    $modelKeyBefore    = ApiModelCache::generateModelCacheKey($model, 'order-1', ['payload']);
    $relationKeyBefore = ApiModelCache::generateRelationshipCacheKey($model, 'payload');

    ApiModelCache::invalidateModelCache($model, 'company-1');

    expect(ApiModelCache::cacheModel($model, 'order-1', fn () => ['status' => 'fresh'], ['payload']))->toBe(['status' => 'fresh'])
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and(ApiModelCache::getCacheKey())->not->toBe($modelKeyBefore)
        ->and(ApiModelCache::cacheRelationship($model, 'payload', fn () => ['name' => 'fresh']))->toBe(['name' => 'fresh'])
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and(ApiModelCache::getCacheKey())->not->toBe($relationKeyBefore)
        ->and($store->get('api_model_version:orders'))->toBe(1);

    // Lookups know no company, so a write in any company moves the table's model version
    ApiModelCache::invalidateModelCache($model, null);

    expect(ApiModelCache::cacheModel($model, 'order-1', fn () => ['status' => 'fresher'], ['payload']))->toBe(['status' => 'fresher'])
        ->and(ApiModelCache::cacheRelationship($model, 'payload', fn () => ['name' => 'fresher']))->toBe(['name' => 'fresher'])
        ->and($store->get('api_model_version:orders'))->toBe(2);
});

test('api model cache resolves declared dependencies to related pivot and raw table names', function () {
    api_model_cache_dependency_database();
    config(['api.cache.strict_dependencies' => true]);

    expect(ApiModelCache::dependencyTables(new ApiModelCacheDependencyOrder()))->toBe(['audit_logs', 'order_tags', 'payloads', 'tags'])
        ->and(ApiModelCache::dependencyTables(new ApiModelCacheRelationsOrder()))->toBe([])
        // Lookup paths skip what they cannot resolve instead of failing the lookup
        ->and(ApiModelCache::dependencyTables(new ApiModelCacheRelationsOrder(), ['payload', 'subject', 'missing', 'tags.missing']))
        ->toBe(['order_tags', 'payloads', 'tags'])
        ->and(fn () => ApiModelCache::dependencyTables(new ApiModelCacheMorphDependencyOrder()))
        ->toThrow(LogicException::class, 'entry "subject" cannot be resolved at "subject"')
        ->and(fn () => ApiModelCache::dependencyTables(new ApiModelCacheUnresolvableDependencyOrder()))
        ->toThrow(LogicException::class, 'entry "payload.customer" cannot be resolved at "customer"')
        ->and(fn () => ApiModelCache::dependencyTables(new ApiModelCacheNotRelationDependencyOrder()))
        ->toThrow(LogicException::class, 'entry "summary" cannot be resolved at "summary"');
});

test('api model cache logs and skips an unresolvable declaration unless strict', function () {
    api_model_cache_dependency_database();
    config(['api.cache.strict_dependencies' => false]);
    $logger = new ApiModelCacheTestLogger();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');

    expect(ApiModelCache::dependencyTables(new ApiModelCachePartlyResolvableDependencyOrder()))->toBe(['order_tags', 'payloads', 'tags'])
        ->and($logger->errors)->toHaveCount(1)
        ->and($logger->errors[0]['message'])->toContain('entry "payload.customer" cannot be resolved at "customer"');
});

test('api model cache never calls a method a client names unless it is declared to return a relation', function () {
    api_model_cache_dependency_database();
    config(['api.cache.strict_dependencies' => false]);
    ApiModelCacheRelationsOrder::$sideEffects = 0;
    $order                                    = new ApiModelCacheRelationsOrder();

    expect(ApiModelCache::dependencyTables($order, ['dispatch', 'untypedPayload', 'untyped_payload', 'exploding', 'payload']))->toBe(['payloads'])
        ->and(ApiModelCacheRelationsOrder::$sideEffects)->toBe(0)
        // A declaration comes from the model's own code and may name an untyped relation
        ->and(ApiModelCache::dependencyTables(new ApiModelCacheUntypedDependencyOrder()))->toBe(['payloads']);

    // A list request naming them still caches and versions what it can
    $request = api_model_cache_request(['with' => ['dispatch', 'exploding', 'payload']]);
    expect(ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['listed'])))->toEqual(collect(['listed']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and(ApiModelCacheRelationsOrder::$sideEffects)->toBe(0);
});

test('api model cache contains a declared relation that throws on a blank model', function () {
    api_model_cache_dependency_database();
    config(['api.cache.strict_dependencies' => false]);
    $logger = new ApiModelCacheTestLogger();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');

    expect(ApiModelCache::dependencyTables(new ApiModelCacheExplodingDependencyOrder()))->toBe(['order_tags', 'tags'])
        ->and($logger->errors)->toHaveCount(1)
        ->and($logger->errors[0]['message'])->toContain('entry "exploding" cannot be resolved');
});

test('api model cache invalidation never fails the write when the cache is unavailable', function () {
    $store                   = api_model_cache_fixture();
    $store->throwOnIncrement = true;
    $store->throwOnTags      = true;
    $logger                  = new ApiModelCacheTestLogger();
    app()->instance('log', $logger);
    Facade::clearResolvedInstance('log');

    ApiModelCache::invalidateModelCache(api_model_cache_model(), 'company-1');

    expect($logger->errors)->toHaveCount(2)
        ->and($logger->errors[0]['message'])->toBe('Failed to advance API cache versions')
        ->and($logger->errors[0]['context']['keys'])->toBe(['api_query_version:orders:company-1', 'api_model_version:orders'])
        ->and($logger->errors[1]['message'])->toBe('Failed to invalidate cache');
});

test('api model cache falls back to the query when reading versions fails', function () {
    $store              = api_model_cache_fixture();
    $store->throwOnMany = true;
    $model              = api_model_cache_model();

    expect(ApiModelCache::cacheQueryResult($model, api_model_cache_request(['status' => 'active']), fn () => collect(['direct'])))->toEqual(collect(['direct']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and($store->taggedValues)->toBe([]);
});

test('api model cache retires a list cached while the invalidating write was uncommitted', function () {
    $capsule                   = api_model_cache_dependency_database();
    $store                     = app('cache');
    $store->flushRemovesValues = false;
    $connection                = $capsule->getConnection('mysql');
    $transactions              = new DatabaseTransactionsManager();
    $connection->setTransactionManager($transactions);
    app()->instance('db.transactions', $transactions);
    $order   = new ApiModelCacheDependencyOrder();
    $request = api_model_cache_request(['status' => 'active']);

    $connection->beginTransaction();
    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyPayload(), 'company-1');

    // Another request reads before the commit and caches the old rows under the new version
    ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['uncommitted view']));
    expect($store->get('api_query_version:payloads:company-1'))->toBe(1);

    $connection->commit();

    expect($store->get('api_query_version:payloads:company-1'))->toBe(2)
        ->and(ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['committed'])))->toEqual(collect(['committed']));

    // The transaction may be on another connection than the written model's
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'other');
    $other = $capsule->getConnection('other');
    $other->setTransactionManager($transactions);
    $other->beginTransaction();
    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyPayload(), 'company-1');
    ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['uncommitted elsewhere']));
    $other->commit();

    expect(ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['committed elsewhere'])))->toEqual(collect(['committed elsewhere']));

    // A rolled back write leaves only its immediate bump
    $connection->beginTransaction();
    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyPayload(), 'company-1');
    $connection->rollBack();

    expect($store->get('api_query_version:payloads:company-1'))->toBe(5);

    app()->forgetInstance('db.transactions');
});

test('api model cache resolves request style relation paths without side effects on the model', function () {
    api_model_cache_dependency_database();
    $order = new ApiModelCacheRelationsOrder();

    expect(ApiModelCache::dependencyTables($order, ['tag_payloads']))->toBe(['payloads', 'tags'])
        // Framework methods named by a client are never called
        ->and(ApiModelCache::dependencyTables($order, ['delete', 'push', 'touch', 'getTable']))->toBe([])
        ->and(ApiModelCache::dependencyTables($order, ['reconnecting_payload']))->toBe(['payloads'])
        ->and($order->getConnectionName())->toBeNull();
});

test('api model cache versions a list by the relations its request eager loads', function () {
    api_model_cache_dependency_database();
    $store                     = app('cache');
    $store->flushRemovesValues = false;
    $order                     = new ApiModelCacheRelationsOrder();
    $withPayload               = api_model_cache_request(['with' => ['payload']]);
    $expandTags                = api_model_cache_request(['expand' => 'tags']);
    $plain                     = api_model_cache_request();

    ApiModelCache::cacheQueryResult($order, $withPayload, fn () => collect(['stale payload']));
    ApiModelCache::cacheQueryResult($order, $expandTags, fn () => collect(['stale tags']));
    ApiModelCache::cacheQueryResult($order, $plain, fn () => collect(['plain']));

    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyPayload(), 'company-1');

    expect(ApiModelCache::cacheQueryResult($order, $withPayload, fn () => collect(['fresh payload'])))->toEqual(collect(['fresh payload']))
        ->and(ApiModelCache::cacheQueryResult($order, $expandTags, fn () => collect(['unexpected'])))->toEqual(collect(['stale tags']))
        ->and(ApiModelCache::cacheQueryResult($order, $plain, fn () => collect(['unexpected'])))->toEqual(collect(['plain']));

    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyOrderTag(), null);

    expect(ApiModelCache::cacheQueryResult($order, $expandTags, fn () => collect(['fresh tags'])))->toEqual(collect(['fresh tags']))
        ->and(ApiModelCache::cacheQueryResult($order, $plain, fn () => collect(['unexpected'])))->toEqual(collect(['plain']));
});

test('api model cache does not serve a list after a write to a declared dependency of the same company or of no company', function () {
    api_model_cache_dependency_database();
    $store                     = app('cache');
    $store->flushRemovesValues = false;
    $order                     = new ApiModelCacheDependencyOrder();
    $request                   = api_model_cache_request(['status' => 'active']);

    ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['stale']));

    // Another company's write leaves this company's list alone
    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyPayload(), 'company-2');

    expect(ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['unexpected'])))->toEqual(collect(['stale']))
        ->and(ApiModelCache::getCacheStatus())->toBe('HIT');

    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyPayload(), 'company-1');

    expect(ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['fresh'])))->toEqual(collect(['fresh']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS');

    // Pivots carry no company, so their writes move the table wide version every company reads
    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyOrderTag(), null);

    expect(ApiModelCache::cacheQueryResult($order, $request, fn () => collect(['fresher'])))->toEqual(collect(['fresher']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        // orders, then audit_logs, order_tags, payloads, tags; each table wide then company-1
        ->and(ApiModelCache::getCacheKey())->toStartWith('{api_query}:orders:company_company-1:v0.0.0.0.1.0.0.1.0.0:');
});

test('has api model cache invalidates a parent list when a dependency or custom pivot is written through eloquent', function () {
    api_model_cache_dependency_database();
    $store                     = app('cache');
    $store->flushRemovesValues = false;

    Model::setEventDispatcher(new Dispatcher(app()));
    Model::clearBootedModels();

    $request = api_model_cache_request(['status' => 'active']);
    $read    = fn (string $value) => ApiModelCache::cacheQueryResult(new ApiModelCacheDependencyOrder(), $request, fn () => collect([$value]));

    $read('before payload write');
    $payload = ApiModelCacheDependencyPayload::create(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'name' => 'Payload']);

    expect($read('after payload create'))->toEqual(collect(['after payload create']));

    $payload->update(['name' => 'Renamed']);

    expect($read('after payload update'))->toEqual(collect(['after payload update']));

    $order = ApiModelCacheDependencyOrder::find('order-1');
    $order->tags()->attach('tag-1');

    expect($read('after attach'))->toEqual(collect(['after attach']));

    $order->tags()->detach('tag-1');

    expect($read('after detach'))->toEqual(collect(['after detach']))
        ->and($read('unexpected'))->toEqual(collect(['after detach']));

    Model::unsetEventDispatcher();
});

test('api model cache does not serve relationship or eager loaded lookups after a write to the related table', function () {
    api_model_cache_dependency_database();
    $store                     = app('cache');
    $store->flushRemovesValues = false;
    $order                     = new ApiModelCacheRelationsOrder(['uuid' => 'order-1']);

    ApiModelCache::cacheRelationship($order, 'payload', fn () => ['name' => 'stale']);
    ApiModelCache::cacheModel($order, 'order-1', fn () => ['payload' => 'stale'], ['payload:uuid,name']);
    ApiModelCache::cacheModel($order, 'order-1', fn () => ['status' => 'cached']);

    ApiModelCache::invalidateModelCache(new ApiModelCacheDependencyPayload(), 'company-1');

    expect(ApiModelCache::cacheRelationship($order, 'payload', fn () => ['name' => 'fresh']))->toBe(['name' => 'fresh'])
        ->and(ApiModelCache::getCacheKey())->toBe('{api_relation}:orders:v0.1:order-1:payload')
        ->and(ApiModelCache::cacheModel($order, 'order-1', fn () => ['payload' => 'fresh'], ['payload:uuid,name']))->toBe(['payload' => 'fresh'])
        // A lookup that does not load the payload keeps its cache
        ->and(ApiModelCache::cacheModel($order, 'order-1', fn () => ['status' => 'unexpected']))->toBe(['status' => 'cached']);
});

test('api model cache seeds versions on stores whose increment does not create missing keys', function () {
    $store                          = api_model_cache_fixture();
    $store->flushRemovesValues      = false;
    $store->incrementCreatesMissing = false;
    $model                          = api_model_cache_model();
    $request                        = api_model_cache_request(['status' => 'active']);

    ApiModelCache::cacheQueryResult($model, $request, fn () => collect(['stale']));
    ApiModelCache::invalidateModelCache($model, 'company-1');

    expect($store->get('api_query_version:orders:company-1'))->toBe(1)
        ->and($store->get('api_model_version:orders'))->toBe(1)
        ->and(ApiModelCache::cacheQueryResult($model, $request, fn () => collect(['fresh'])))->toEqual(collect(['fresh']));

    ApiModelCache::invalidateModelCache($model, 'company-1');

    expect($store->get('api_query_version:orders:company-1'))->toBe(2)
        ->and($store->get('api_model_version:orders'))->toBe(2);
});

test('api model cache keeps lists of storefront stores and networks of the same company apart', function () {
    api_model_cache_fixture();
    $model    = api_model_cache_model();
    $query    = ['limit' => '10'];
    $callback = ['has_callback' => true];
    $storeA   = api_model_cache_scoped_request('/storefront/v1/products', ['company' => 'company-1', 'storefront_store' => 'store-a', 'api_credential' => 'store_key_a'], $query);
    $storeB   = api_model_cache_scoped_request('/storefront/v1/products', ['company' => 'company-1', 'storefront_store' => 'store-b', 'api_credential' => 'store_key_b'], $query);
    $network  = api_model_cache_scoped_request('/storefront/v1/products', ['company' => 'company-1', 'storefront_network' => 'network-a', 'api_credential' => 'network_key_a'], $query);

    expect(ApiModelCache::cacheQueryResult($model, $storeA, fn () => collect(['store a product']), $callback))->toEqual(collect(['store a product']));
    $storeAKey = ApiModelCache::getCacheKey();

    expect(ApiModelCache::cacheQueryResult($model, $storeB, fn () => collect(['store b product']), $callback))->toEqual(collect(['store b product']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and(ApiModelCache::cacheQueryResult($model, $network, fn () => collect(['network product']), $callback))->toEqual(collect(['network product']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and(ApiModelCache::cacheQueryResult($model, $storeA, fn () => collect(['not reached']), $callback))->toEqual(collect(['store a product']))
        ->and(ApiModelCache::getCacheStatus())->toBe('HIT')
        ->and($storeAKey)->toStartWith('{api_query}:orders:company_company-1:v0.0:')
        ->and($storeAKey)->not->toContain('store_key_a');
});

test('api model cache keeps internal and public lists of the same company and query apart', function () {
    api_model_cache_fixture();
    $model    = api_model_cache_model();
    $query    = ['limit' => '30'];
    $callback = ['has_callback' => true];
    $internal = api_model_cache_scoped_request('/storefront/int/v1/catalogs', ['company' => 'company-1'], $query);
    $public   = api_model_cache_scoped_request('/storefront/v1/catalogs', ['company' => 'company-1', 'storefront_store' => 'store-a', 'api_credential' => 'store_key_a'], $query);
    $console  = api_model_cache_scoped_request('/storefront/int/v1/catalogs', ['company' => 'company-1'], $query);

    ApiModelCache::cacheQueryResult($model, $internal, fn () => 'internal paginator with editor relations', $callback);

    expect(ApiModelCache::cacheQueryResult($model, $public, fn () => collect(['store a catalog']), $callback))->toEqual(collect(['store a catalog']))
        ->and(ApiModelCache::getCacheStatus())->toBe('MISS')
        ->and(ApiModelCache::cacheQueryResult($model, $console, fn () => 'not reached', $callback))->toBe('internal paginator with editor relations')
        ->and(ApiModelCache::getCacheStatus())->toBe('HIT');
});

test('api model cache keeps public lists of the same company and query on different endpoints apart', function () {
    api_model_cache_fixture();
    $model   = api_model_cache_model();
    $session = ['company' => 'company-1', 'api_credential' => 'credential-1'];
    $key     = fn (string $path) => ApiModelCache::generateQueryCacheKey($model, api_model_cache_scoped_request($path, $session, ['limit' => '10']), ['has_callback' => true]);

    expect($key('/v1/customers/customer_a/places'))->not->toBe($key('/v1/customers/customer_b/places'))
        ->and($key('/v1/customers/customer_a/places'))->toBe($key('/v1/customers/customer_a/places'));
});

test('api model cache scopes list keys by the configured session keys only', function () {
    api_model_cache_fixture(['api.cache.scope.session' => ['customer']]);
    $model = api_model_cache_model();
    $key   = fn (array $session) => ApiModelCache::generateQueryCacheKey($model, api_model_cache_scoped_request('/v1/orders', ['company' => 'company-1', ...$session]));

    expect($key(['customer' => 'customer-a']))->not->toBe($key(['customer' => 'customer-b']))
        ->and($key(['customer' => 'customer-a', 'storefront_store' => 'store-a']))->toBe($key(['customer' => 'customer-a', 'storefront_store' => 'store-b']));
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
        ->toStartWith('{api_query}:orders:company_company-from-user:v0.0:')
        ->and(ApiModelCache::generateQueryCacheKey($model, $inputRequest))
        ->toStartWith('{api_query}:orders:company_company-from-input:v0.0:');
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
        ->and(ApiModelCache::getCacheKey())->toBe('{api_model}:orders:v0:order-1');

    $relationshipResult = ApiModelCache::cacheRelationship($model, 'payload', fn () => ['relationship' => true]);

    expect($relationshipResult)->toBe(['relationship' => true])
        ->and(ApiModelCache::getCacheStatus())->toBe('ERROR')
        ->and(ApiModelCache::getCacheKey())->toBe('{api_relation}:orders:v0:order-1:payload');
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
        ->and(ApiModelCache::getCacheKey())->toStartWith('{api_query}:orders:company_company-1:v0.0:')
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
