<?php

use Fleetbase\Expansions\Arr as ArrExpansion;
use Fleetbase\Expansions\Blade as BladeExpansion;
use Fleetbase\Expansions\Builder as BuilderExpansion;
use Fleetbase\Expansions\Carbon as CarbonExpansion;
use Fleetbase\Expansions\Request as RequestExpansion;
use Fleetbase\Expansions\Response as ResponseExpansion;
use Fleetbase\Expansions\Str as StrExpansion;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class CoreExpansionBuilderModel extends EloquentModel
{
    protected $connection = 'mysql';
    protected $table      = 'builder_expansion_records';
    protected $guarded    = [];
}

class CoreExpansionResponseFactoryFake
{
    public static function json(array $data, int $statusCode = 200, array $headers = [], int $options = 0): JsonResponse
    {
        return new JsonResponse($data, $statusCode, $headers, $options);
    }
}

afterEach(function () {
    HttpRequest::flushMacros();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
    Carbon::setTestNow();
});

function core_expansion_builder_database(): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstance('db.schema');

    $schema->create('builder_expansion_records', function ($table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
    });

    $capsule->getConnection('mysql')->table('builder_expansion_records')->insert([
        ['name' => 'Alpha Fleet', 'email' => 'alpha@example.test', 'status' => 'active', 'created_at' => '2026-07-17 10:00:00', 'updated_at' => '2026-07-17 10:00:00'],
        ['name' => 'Beta Dispatch', 'email' => 'beta@example.test', 'status' => 'inactive', 'created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00'],
        ['name' => 'Gamma Fleet', 'email' => 'gamma@example.test', 'status' => 'active', 'created_at' => '2026-07-19 10:00:00', 'updated_at' => '2026-07-19 10:00:00'],
    ]);

    return $capsule;
}

test('string carbon and blade expansions preserve formatting contracts', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:30:00'));

    $strExpansion    = new StrExpansion();
    $carbonExpansion = new CarbonExpansion();
    $bladeExpansion  = new BladeExpansion();

    $humanize   = $strExpansion->humanize();
    $domain     = $strExpansion->domain();
    $fromString = $carbonExpansion->fromString()->bindTo(null, Carbon::class);

    expect(\Fleetbase\Expansions\args(' created_at , "Y-m-d" '))->toBe(['created_at', '"Y-m-d"'])
        ->and($humanize('apiCredentialID'))->toBe('API credential i d')
        ->and($humanize('apiCredentialID', false))->toBe('API credential i d')
        ->and($humanize(null))->toBe('')
        ->and($domain('https://console.fleetbase.io/auth/login'))->toBe('fleetbase.io')
        ->and($fromString('first day of quarter')->toDateString())->toBe('2026-04-01')
        ->and($fromString('last day of quarter')->toDateString())->toBe('2026-06-30')
        ->and($fromString('start of decade')->toDateTimeString())->toBe('2020-01-01 00:00:00')
        ->and($fromString('end of decade')->toDateTimeString())->toBe('2029-12-31 23:59:59')
        ->and($fromString('2026-07-17 12:45:00')->toDateTimeString())->toBe('2026-07-17 12:45:00')
        ->and(($bladeExpansion->toTimeString())('2026-07-17 12:45:00'))->toBe('12:45:00')
        ->and(($bladeExpansion->toDateTimeString())('2026-07-17 12:45:00'))->toBe('2026-07-17 12:45:00')
        ->and(($bladeExpansion->formatFromCarbon())('created_at, "Y-m-d"'))->toBe('<?= \Illuminate\Support\Carbon::parse(created_at)->format("Y-m-d") ?>')
        ->and(($bladeExpansion->getFromCarbonParse())('created_at, timestamp'))->toBe('<?= \Illuminate\Support\Carbon::parse(created_at)->{timestamp} ?>');
});

test('array expansion helpers preserve key order and search semantics', function () {
    $expansion = new ArrExpansion();

    $every = $expansion->every();
    $insertAfterKey = $expansion->insertAfterKey();
    $search = $expansion->search();
    $map = $expansion->map();

    expect($expansion::target())->toBe(\Illuminate\Support\Arr::class)
        ->and($every([2, 4, 6], fn (int $number) => $number % 2 === 0))->toBeTrue()
        ->and($every([2, 3, 6], fn (int $number) => $number % 2 === 0))->toBeFalse()
        ->and($insertAfterKey(['first' => 1, 'second' => 2], ['middle' => 9], 'first'))->toBe([
            'first' => 1,
            'middle' => 9,
            'second' => 2,
        ])
        ->and($insertAfterKey(['first' => 1], ['fallback' => 2], 'missing'))->toBe([
            'first' => 1,
            'fallback' => 2,
        ])
        ->and($search(['alpha' => 10, 'beta' => 20], 20))->toBe('beta')
        ->and($search(['alpha' => 10, 'beta' => 20], fn (int $value) => $value > 15))->toBe('beta')
        ->and($search(['alpha' => 10], fn (int $value) => $value > 15))->toBeNull()
        ->and($map(['a' => 1, 'b' => 2], fn (int $value, string $key) => $key . ':' . ($value * 2)))->toBe([
            'a:2',
            'b:4',
        ]);
});

test('request expansion helpers normalize parameters and global filter payloads', function () {
    $expansion = new RequestExpansion();
    HttpRequest::macro('or', $expansion->or());

    $request = HttpRequest::create('/int/v1/test', 'POST', [
        'ids'         => 'one,two,three',
        'tags'        => ['fragile', 'cold'],
        'count'       => '12',
        'uuid'        => '11111111-1111-4111-8111-111111111111',
        'query'       => 'Fleetbase%20API',
        'sort'        => '-created_at',
        'page'        => 2,
        'status'      => 'active',
        'custom'      => 'keep',
        'tenant_only' => 'drop',
    ]);

    expect($expansion->or()->call($request, ['missing', 'status'], 'fallback'))->toBe('active')
        ->and($expansion->or()->call($request, ['missing'], 'fallback'))->toBe('fallback')
        ->and($expansion->array()->call($request, 'ids'))->toBe(['one', 'two', 'three'])
        ->and($expansion->array()->call($request, 'tags'))->toBe(['fragile', 'cold'])
        ->and($expansion->isString()->call($request, 'status'))->toBeTrue()
        ->and($expansion->isUuid()->call($request, 'uuid'))->toBeTrue()
        ->and($expansion->isArray()->call($request, 'tags'))->toBeTrue()
        ->and($expansion->inArray()->call($request, 'tags', 'cold'))->toBeTrue()
        ->and($expansion->integer()->call($request, 'count'))->toBe(12)
        ->and($expansion->searchQuery()->call($request))->toBe('fleetbase api')
        ->and($expansion->getFilters()->call($request, ['tenant_only']))->toBe([
            'ids'    => 'one,two,three',
            'tags'   => ['fragile', 'cold'],
            'count'  => '12',
            'uuid'   => '11111111-1111-4111-8111-111111111111',
            'status' => 'active',
            'custom' => 'keep',
        ]);

    $expansion->removeParam()->call($request, 'status');

    expect($request->has('status'))->toBeFalse();
});

test('builder expansion search where applies strict and fuzzy search contracts', function () {
    core_expansion_builder_database();

    $builderExpansion = new BuilderExpansion();
    EloquentBuilder::macro('searchWhere', $builderExpansion->searchWhere());

    $fuzzyNames = CoreExpansionBuilderModel::query()
        ->searchWhere(['name', 'email'], 'fleet')
        ->pluck('name')
        ->all();

    sort($fuzzyNames);

    $strictNames = CoreExpansionBuilderModel::query()
        ->searchWhere(['status', 'name'], 'active', true)
        ->pluck('name')
        ->all();

    sort($strictNames);

    $commaAndDotSearch = CoreExpansionBuilderModel::query()
        ->searchWhere('email', 'alpha.example')
        ->pluck('name')
        ->all();

    expect($fuzzyNames)->toBe(['Alpha Fleet', 'Gamma Fleet'])
        ->and($strictNames)->toBe(['Alpha Fleet', 'Gamma Fleet'])
        ->and($commaAndDotSearch)->toBe(['Alpha Fleet']);
});

test('builder expansion removes only matching basic where clauses and bindings', function () {
    core_expansion_builder_database();

    $builderExpansion = new BuilderExpansion();
    EloquentBuilder::macro('removeWhereFromQuery', $builderExpansion->removeWhereFromQuery());

    $query = CoreExpansionBuilderModel::query()
        ->where('status', 'active')
        ->where('name', 'Alpha Fleet')
        ->where('email', 'alpha@example.test');

    $query->removeWhereFromQuery('name', 'Alpha Fleet');

    expect($query->getQuery()->wheres)->toHaveCount(2)
        ->and($query->getBindings())->toBe(['active', 'alpha@example.test'])
        ->and($query->pluck('name')->all())->toBe(['Alpha Fleet']);

    $query->removeWhereFromQuery('missing', 'value');

    expect($query->getQuery()->wheres)->toHaveCount(2)
        ->and($query->getBindings())->toBe(['active', 'alpha@example.test']);
});

test('builder expansion applies request sort aliases and explicit directions', function () {
    core_expansion_builder_database();

    $requestExpansion = new RequestExpansion();
    HttpRequest::macro('or', $requestExpansion->or());

    $builderExpansion = new BuilderExpansion();
    EloquentBuilder::macro('applySortFromRequest', $builderExpansion->applySortFromRequest());

    $latest = CoreExpansionBuilderModel::query()
        ->applySortFromRequest(HttpRequest::create('/int/v1/test', 'GET', ['sort' => 'latest']))
        ->pluck('name')
        ->all();

    $oldest = CoreExpansionBuilderModel::query()
        ->applySortFromRequest(HttpRequest::create('/int/v1/test', 'GET', ['sort' => 'oldest']))
        ->pluck('name')
        ->all();

    $explicit = CoreExpansionBuilderModel::query()
        ->applySortFromRequest(HttpRequest::create('/int/v1/test', 'GET', ['sort' => 'status:desc,-name']))
        ->pluck('name')
        ->all();

    $default = CoreExpansionBuilderModel::query()
        ->applySortFromRequest(HttpRequest::create('/int/v1/test', 'GET'))
        ->pluck('name')
        ->all();

    expect($latest)->toBe(['Gamma Fleet', 'Beta Dispatch', 'Alpha Fleet'])
        ->and($oldest)->toBe(['Alpha Fleet', 'Beta Dispatch', 'Gamma Fleet'])
        ->and($explicit)->toBe(['Beta Dispatch', 'Gamma Fleet', 'Alpha Fleet'])
        ->and($default)->toBe(['Gamma Fleet', 'Beta Dispatch', 'Alpha Fleet']);
});

test('response expansion helpers keep internal and public error response shapes stable', function () {
    $factory           = new CoreExpansionResponseFactoryFake();
    $responseExpansion = new ResponseExpansion();

    $error    = $responseExpansion->error()->bindTo($factory, CoreExpansionResponseFactoryFake::class);
    $apiError = $responseExpansion->apiError()->bindTo($factory, CoreExpansionResponseFactoryFake::class);

    $internalResponse = $error('Unable to continue', 409, ['code' => 'conflict']);
    $publicResponse   = $apiError(['message' => 'Invalid request'], 422, ['request_id' => 'req_123']);

    expect($internalResponse->getStatusCode())->toBe(409)
        ->and($internalResponse->getData(true))->toBe([
            'errors' => ['Unable to continue'],
            'code'   => 'conflict',
        ])
        ->and($publicResponse->getStatusCode())->toBe(422)
        ->and($publicResponse->getData(true))->toBe([
            'error'      => ['message' => 'Invalid request'],
            'request_id' => 'req_123',
        ]);
});
