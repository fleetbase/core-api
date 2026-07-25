<?php

use Fleetbase\Traits\HasCacheableAttributes;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasPresence;
use Fleetbase\Traits\HasSessionAttributes;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class ModelAttributeTraitsRecord extends Model
{
    use HasMetaAttributes;
    use HasOptionsAttributes;
    use HasSessionAttributes;

    protected $table      = 'trait_records';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;
    protected $guarded    = [];
    protected $casts      = [
        'meta'    => 'array',
        'options' => 'array',
    ];
}

class ModelAttributeTraitsPresenceRecord
{
    use HasPresence;

    public function __construct(private string $key)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }
}

class ModelAttributeTraitsCacheableRecord extends Model
{
    use HasCacheableAttributes;

    protected $table      = 'cacheable_trait_records';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;
    protected $guarded    = [];
}

class ModelAttributeTraitsCacheFake
{
    public array $values  = [];
    public array $deleted = [];

    public function put(string $key, mixed $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function delete(string $key): bool
    {
        $this->deleted[] = $key;
        unset($this->values[$key]);

        return true;
    }
}

class ModelAttributeTraitsTaggedCacheFake
{
    public array $values    = [];
    public array $tags      = [];
    public array $puts      = [];
    public array $forever   = [];
    public array $forgotten = [];
    public array $flushed   = [];

    private array $activeTags = [];

    public function tags(array|string $tags): self
    {
        $this->activeTags = (array) $tags;
        $this->tags[]     = $this->activeTags;

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->puts[]       = [$this->activeTags, $key, $value, $ttl];

        return true;
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        $this->forever[] = [$this->activeTags, $key];

        return $this->values[$key];
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;
        unset($this->values[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->flushed[] = $this->activeTags;

        return true;
    }
}

function model_attribute_traits_database(): Capsule
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'             => 'testing',
        'database.connections.testing' => $connectionConfig,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('testing')->getSchemaBuilder()->create('trait_records', function ($table) {
        $table->string('uuid')->primary();
        $table->text('meta')->nullable();
        $table->text('options')->nullable();
    });

    return $capsule;
}

test('has meta attributes manages nested values booleans defaults and selected subsets', function () {
    $record = new ModelAttributeTraitsRecord();

    expect($record->getAllMeta())->toBe([])
        ->and($record->getMeta('missing', 'fallback'))->toBe('fallback')
        ->and($record->hasMeta('customer.id'))->toBeFalse()
        ->and($record->missingMeta('customer.id'))->toBeTrue()
        ->and($record->doesntHaveMeta('customer.id'))->toBeTrue();

    $record->setMeta('customer.id', 1846473);
    $record->setMeta([
        'flags.billable' => true,
        'flags.reviewed' => false,
        'labels.check'   => 'Fleetbase ✓',
    ]);

    expect($record->getMeta())->toBe($record->getAllMeta())
        ->and($record->getMeta('customer.id'))->toBe(1846473)
        ->and($record->getMeta('flags.billable'))->toBeTrue()
        ->and($record->getMeta('labels.check'))->toBe('Fleetbase ✓')
        ->and($record->isMeta('flags.billable'))->toBeTrue()
        ->and($record->isMeta('flags.reviewed'))->toBeFalse()
        ->and($record->hasMeta(['customer.id', 'flags.billable']))->toBeTrue()
        ->and($record->hasMeta(['customer.id', 'missing']))->toBeFalse()
        ->and($record->getMetaAttributes(['customer.id', 'flags.billable']))->toBe([
            'customer' => ['id' => 1846473],
            'flags'    => ['billable' => true],
        ]);
});

test('has meta attributes updates database meta properties without discarding existing keys', function () {
    model_attribute_traits_database();

    $record = new ModelAttributeTraitsRecord([
        'uuid'    => 'record-1',
        'meta'    => ['existing' => 'yes', 'count' => 1],
        'options' => [],
    ]);
    $record->save();

    expect($record->updateMetaProperties(['count' => 2, 'new' => 'value']))->toBeTrue();

    $rawMeta = Capsule::connection('testing')
        ->table('trait_records')
        ->where('uuid', 'record-1')
        ->value('meta');

    expect(json_decode($rawMeta, true))->toBe([
        'existing' => 'yes',
        'count'    => 2,
        'new'      => 'value',
    ]);

    expect($record->updateMeta('nested.flag', true))->toBeTrue();

    $record->refresh();

    expect($record->getMeta('nested.flag'))->toBeTrue();
});

test('has options attributes manages nested options and boolean checks', function () {
    $record = new ModelAttributeTraitsRecord();

    expect($record->getAllOptions())->toBe([])
        ->and($record->getOption('missing', 'fallback'))->toBe('fallback')
        ->and($record->hasOption('enabled'))->toBeFalse()
        ->and($record->missingOption('enabled'))->toBeTrue();

    $record->setOption('enabled', true)
        ->setOption('customer.name', 'Acme')
        ->setOption([
            'dispatch.window'   => 'morning',
            'dispatch.priority' => 'high',
        ], null);

    expect($record->getOption())->toBe([
        'enabled'  => true,
        'customer' => ['name' => 'Acme'],
        'dispatch' => [
            'window'   => 'morning',
            'priority' => 'high',
        ],
    ])
        ->and($record->getOption('dispatch.priority'))->toBe('high')
        ->and($record->hasOption('enabled'))->toBeTrue()
        ->and($record->hasOption('dispatch.priority'))->toBeFalse()
        ->and($record->isOption('enabled'))->toBeTrue()
        ->and($record->isOption('dispatch.priority'))->toBeFalse();
});

test('has options attributes quietly updates persisted nested option values', function () {
    model_attribute_traits_database();

    $record = new ModelAttributeTraitsRecord([
        'uuid'    => 'record-options-1',
        'meta'    => [],
        'options' => ['dispatch' => ['priority' => 'normal']],
    ]);
    $record->save();

    expect($record->updateOption('dispatch.priority', 'urgent'))->toBeTrue()
        ->and($record->getOption('dispatch.priority'))->toBe('urgent');

    $rawOptions = Capsule::connection('testing')
        ->table('trait_records')
        ->where('uuid', 'record-options-1')
        ->value('options');

    expect(json_decode($rawOptions, true))->toBe([
        'dispatch' => ['priority' => 'urgent'],
    ]);
});

test('has session attributes tracks strict session agnostic columns', function () {
    $record = new ModelAttributeTraitsRecord();

    expect($record->getSessionAgnosticColumns())->toBe([])
        ->and($record->isSessionAgnosticColumn('company_uuid'))->toBeFalse()
        ->and($record->setSessionAgnosticColumns(['company_uuid', 'created_by_uuid']))->toBe($record)
        ->and($record->getSessionAgnosticColumns())->toBe(['company_uuid', 'created_by_uuid'])
        ->and($record->isSessionAgnosticColumn('company_uuid'))->toBeTrue()
        ->and($record->isSessionAgnosticColumn('Company_UUID'))->toBeFalse();
});

test('has cacheable attributes reads writes forever remembers and flushes model attribute cache', function () {
    bind_test_container();
    $cache = new ModelAttributeTraitsTaggedCacheFake();
    Cache::swap($cache);

    $record = new ModelAttributeTraitsCacheableRecord([
        'uuid' => 'record-cache-1',
        'name' => 'Alpha',
    ]);

    $cacheKey         = 'model_attribute_cache:connection:cacheable_trait_records:record-cache-1:name';
    $computedCacheKey = 'model_attribute_cache:connection:cacheable_trait_records:record-cache-1:computed';
    $tag              = 'model_attribute_cache:connection:cacheable_trait_records:record-cache-1';

    expect($record->fromCache('name'))->toBe('Alpha')
        ->and($cache->values[$cacheKey])->toBe('Alpha');

    $record->name = 'Beta';

    expect($record->rememberAttribute('name'))->toBe('Alpha')
        ->and($record->rememberAttributeForever('computed', fn () => 'Forever'))->toBe('Forever')
        ->and($cache->forever)->toBe([[[$tag], $computedCacheKey]])
        ->and($record->forgetAttribute('name'))->toBeTrue()
        ->and($cache->forgotten)->toBe([$cacheKey])
        ->and($record->flushAttributesCache())->toBeTrue()
        ->and($cache->flushed)->toContain([$tag]);
});

test('has presence records last seen state and evaluates online windows', function () {
    bind_test_container();
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00', 'UTC'));
    $cache = new ModelAttributeTraitsCacheFake();
    Cache::swap($cache);

    $record = new ModelAttributeTraitsPresenceRecord('user-1');

    expect($record->getPresenceCacheKey())->toBe('last-seen-at:user-1')
        ->and($record->lastSeenAt())->toBeNull()
        ->and($record->isPresent())->toBeFalse()
        ->and($record->rememberPresence())->toBeTrue()
        ->and($cache->values['last-seen-at:user-1']->toISOString())->toBe('2026-07-18T12:00:00.000000Z')
        ->and($record->lastSeenAt()->toISOString())->toBe('2026-07-18T12:00:00.000000Z')
        ->and($record->isPresent())->toBeTrue()
        ->and($record->isOnline())->toBeTrue();

    $cache->values['last-seen-at:user-1'] = Carbon::parse('2026-07-18 11:57:00', 'UTC');

    expect($record->isPresent())->toBeFalse()
        ->and($record->forgetPresence())->toBeTrue()
        ->and($cache->deleted)->toBe(['last-seen-at:user-1'])
        ->and($record->lastSeenAt())->toBeNull();
});
