<?php

use Fleetbase\Expansions\Arr as ArrExpansion;
use Fleetbase\Expansions\Blade as BladeExpansion;
use Fleetbase\Expansions\Builder as BuilderExpansion;
use Fleetbase\Expansions\Carbon as CarbonExpansion;
use Fleetbase\Expansions\PendingResourceRegistration as PendingResourceRegistrationExpansion;
use Fleetbase\Expansions\Request as RequestExpansion;
use Fleetbase\Expansions\Response as ResponseExpansion;
use Fleetbase\Expansions\Str as StrExpansion;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

class CoreExpansionBuilderModel extends EloquentModel
{
    protected $connection = 'mysql';
    protected $table      = 'builder_expansion_records';
    protected $guarded    = [];

    public function scopeOrderByDistance(EloquentBuilder $query): EloquentBuilder
    {
        return $query->orderBy('name');
    }
}

class CoreExpansionResponseFactoryFake
{
    public static function json(array $data, int $statusCode = 200, array $headers = [], int $options = 0): JsonResponse
    {
        return new JsonResponse($data, $statusCode, $headers, $options);
    }
}

class CoreExpansionResponseControllerStub
{
    public function getResourceSingularName(): string
    {
        return 'api_credential';
    }
}

class CoreExpansionDirectiveControllerStub
{
    public function index(): void
    {
    }
}

class CoreExpansionRouteControllerStub extends Illuminate\Routing\Controller
{
}

class CoreExpansionValidatorFake implements ValidatorContract
{
    public function __construct(private array $messages)
    {
    }

    public function validate()
    {
        return [];
    }

    public function validated()
    {
        return [];
    }

    public function fails()
    {
        return $this->errors()->isNotEmpty();
    }

    public function failed()
    {
        return [];
    }

    public function sometimes($attribute, $rules, callable $callback)
    {
        return $this;
    }

    public function after($callback)
    {
        return $this;
    }

    public function errors(): MessageBag
    {
        return new MessageBag($this->messages);
    }

    public function getMessageBag(): MessageBag
    {
        return $this->errors();
    }

    public function getTranslator()
    {
        // Real Illuminate\Validation\ValidationException::summarize() asks for a translator.
        return new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
    }
}

afterEach(function () {
    HttpRequest::flushMacros();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
    Carbon::setTestNow();
});

function core_expansion_request(string $uri = '/int/v1/api-credentials', string $method = 'GET', ?object $controller = null): HttpRequest
{
    $request           = HttpRequest::create($uri, $method);
    $route             = new Route([$method], ltrim($uri, '/'), ['controller' => CoreExpansionResponseControllerStub::class . '@deleteRecord']);
    $route->controller = $controller ?? new CoreExpansionResponseControllerStub();

    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    if (!HttpRequest::hasMacro('getController')) {
        HttpRequest::macro('getController', fn () => $this->route()?->controller);
    }

    return $request;
}

function core_expansion_validator(array $messages): ValidatorContract
{
    return new CoreExpansionValidatorFake($messages);
}

function core_expansion_define_validation_exception(): void
{
    if (class_exists(ValidationException::class)) {
        return;
    }

    eval('namespace Illuminate\\Validation; class ValidationException extends \\Exception { public function __construct(public mixed $validator = null, private mixed $response = null) { parent::__construct("The given data was invalid."); } public function getResponse(): mixed { return $this->response; } }');
}

function core_expansion_validation_response(ValidationException $exception): mixed
{
    return method_exists($exception, 'getResponse') ? $exception->getResponse() : $exception->response;
}

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
        'auth.defaults.guard'        => 'web',
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
        $table->text('meta')->nullable();
        $table->timestamps();
    });

    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('disk')->nullable();
        $table->string('path')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('content_type')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->timestamps();
    });

    $schema->create('directives', function ($table) {
        $table->string('uuid')->primary();
        $table->string('permission_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->text('rules')->nullable();
        $table->dateTime('deleted_at')->nullable();
        $table->timestamps();
    });

    $capsule->getConnection('mysql')->table('builder_expansion_records')->insert([
        ['name' => 'Alpha Fleet', 'email' => 'alpha@example.test', 'status' => 'active', 'meta' => '{"owner":"Ada"}', 'created_at' => '2026-07-17 10:00:00', 'updated_at' => '2026-07-17 10:00:00'],
        ['name' => 'Beta Dispatch', 'email' => 'beta@example.test', 'status' => 'inactive', 'meta' => '{"owner":"Grace"}', 'created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00'],
        ['name' => 'Gamma Fleet', 'email' => 'gamma@example.test', 'status' => 'active', 'meta' => '{"owner":"Katherine"}', 'created_at' => '2026-07-19 10:00:00', 'updated_at' => '2026-07-19 10:00:00'],
    ]);

    return $capsule;
}

test('string carbon and blade expansions preserve formatting contracts', function () {
    bind_test_container([
        'filesystems.disks.s3.region' => null,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-05-15 08:30:00'));

    $strExpansion    = new StrExpansion();
    $carbonExpansion = new CarbonExpansion();
    $bladeExpansion  = new BladeExpansion();

    $humanize   = $strExpansion->humanize();
    $domain     = $strExpansion->domain();
    $fromString = $carbonExpansion->fromString()->bindTo(null, Carbon::class);

    expect(BladeExpansion::target())->toBe(Illuminate\Support\Facades\Blade::class)
        ->and(CarbonExpansion::target())->toBe(Carbon::class)
        ->and(PendingResourceRegistrationExpansion::target())->toBe(Illuminate\Routing\PendingResourceRegistration::class)
        ->and(StrExpansion::target())->toBe(Illuminate\Support\Str::class)
        ->and(\Fleetbase\Expansions\args(' created_at , "Y-m-d" '))->toBe(['created_at', '"Y-m-d"'])
        ->and(\Fleetbase\Expansions\args(['created_at', 'timestamp']))->toBe(['created_at', 'timestamp'])
        ->and($humanize('apiCredentialID'))->toBe('API credential i d')
        ->and($humanize('apiCredentialID', false))->toBe('API credential i d')
        ->and($humanize(null))->toBe('')
        ->and($domain('https://console.fleetbase.io/auth/login'))->toBe('fleetbase.io')
        ->and($domain('http://localhost:4200'))->toBe('localhost')
        ->and($domain('localhost'))->toBe('localhost')
        ->and($fromString('first day of quarter')->toDateString())->toBe('2026-04-01')
        ->and($fromString('last day of quarter')->toDateString())->toBe('2026-06-30')
        ->and($fromString('start of decade')->toDateTimeString())->toBe('2020-01-01 00:00:00')
        ->and($fromString('end of decade')->toDateTimeString())->toBe('2029-12-31 23:59:59')
        ->and($fromString('2026-07-17 12:45:00')->toDateTimeString())->toBe('2026-07-17 12:45:00')
        ->and(($bladeExpansion->assetFromS3())('icons/logo.png'))->toBe('https://flb-assets.amazonaws.com/icons/logo.png')
        ->and(($bladeExpansion->fontFromS3())('inter.woff2'))->toBe('https://flb-assets.amazonaws.com/fonts/inter.woff2')
        ->and(($bladeExpansion->toTimeString())('2026-07-17 12:45:00'))->toBe('12:45:00')
        ->and(($bladeExpansion->toDateTimeString())('2026-07-17 12:45:00'))->toBe('2026-07-17 12:45:00')
        ->and(($bladeExpansion->formatFromCarbon())('created_at, "Y-m-d"'))->toBe('<?= \Illuminate\Support\Carbon::parse(created_at)->format("Y-m-d") ?>')
        ->and(($bladeExpansion->getFromCarbonParse())('created_at, timestamp'))->toBe('<?= \Illuminate\Support\Carbon::parse(created_at)->{timestamp} ?>');
});

test('array expansion helpers preserve key order and search semantics', function () {
    $expansion = new ArrExpansion();

    $every          = $expansion->every();
    $insertAfterKey = $expansion->insertAfterKey();
    $search         = $expansion->search();
    $map            = $expansion->map();

    expect($expansion::target())->toBe(Illuminate\Support\Arr::class)
        ->and($every([2, 4, 6], fn (int $number) => $number % 2 === 0))->toBeTrue()
        ->and($every([2, 3, 6], fn (int $number) => $number % 2 === 0))->toBeFalse()
        ->and($insertAfterKey(['first' => 1, 'second' => 2], ['middle' => 9], 'first'))->toBe([
            'first'  => 1,
            'middle' => 9,
            'second' => 2,
        ])
        ->and($insertAfterKey(['first' => 1], ['fallback' => 2], 'missing'))->toBe([
            'first'    => 1,
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
    core_expansion_builder_database();

    $expansion = new RequestExpansion();
    HttpRequest::macro('or', $expansion->or());
    HttpRequest::macro('array', $expansion->array());

    app('db')->connection('mysql')->table('companies')->insert([
        'uuid'       => 'company-1',
        'public_id'  => 'company_public',
        'name'       => 'Acme Logistics',
        'created_at' => '2026-07-19 00:00:00',
        'updated_at' => '2026-07-19 00:00:00',
    ]);

    app('db')->connection('mysql')->table('files')->insert([
        [
            'uuid'              => '11111111-1111-4111-8111-111111111111',
            'public_id'         => 'file_first',
            'company_uuid'      => 'company-1',
            'disk'              => 'local',
            'path'              => 'uploads/first.pdf',
            'original_filename' => 'first.pdf',
            'content_type'      => 'application/pdf',
            'created_at'        => '2026-07-19 00:00:00',
            'updated_at'        => '2026-07-19 00:00:00',
        ],
        [
            'uuid'              => '22222222-2222-4222-8222-222222222222',
            'public_id'         => 'file_second',
            'company_uuid'      => 'company-1',
            'disk'              => 'local',
            'path'              => 'uploads/second.pdf',
            'original_filename' => 'second.pdf',
            'content_type'      => 'application/pdf',
            'created_at'        => '2026-07-19 00:00:00',
            'updated_at'        => '2026-07-19 00:00:00',
        ],
    ]);

    $request = HttpRequest::create('/int/v1/test', 'POST', [
        'ids'         => 'one,two,three',
        'files'       => '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222',
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

    $routeController          = new CoreExpansionRouteControllerStub();
    $route                    = new Route(['POST'], 'int/v1/test', ['controller' => CoreExpansionRouteControllerStub::class . '@index']);
    $route->controller        = $routeController;
    $request->setRouteResolver(fn () => $route);
    $request->setLaravelSession(new Illuminate\Session\Store('testing', new Illuminate\Session\ArraySessionHandler(120)));
    $request->session()->put('company', 'company-1');

    expect(RequestExpansion::target())->toBe(Illuminate\Support\Facades\Request::class)
        ->and($expansion->company()->call($request)?->name)->toBe('Acme Logistics')
        ->and($expansion->getController()->call($request))->toBe($routeController)
        ->and($expansion->resolveFilesFromIds()->call($request)->pluck('uuid')->all())->toBe([
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
        ])
        ->and($expansion->or()->call($request, ['missing', 'status'], 'fallback'))->toBe('active')
        ->and($expansion->or()->call($request, ['missing'], 'fallback'))->toBe('fallback')
        ->and($expansion->array()->call($request, 'ids'))->toBe(['one', 'two', 'three'])
        ->and($expansion->array()->call($request, 'tags'))->toBe(['fragile', 'cold'])
        ->and($expansion->array()->call($request, 'missing'))->toBe([])
        ->and($expansion->isString()->call($request, 'status'))->toBeTrue()
        ->and($expansion->isUuid()->call($request, 'uuid'))->toBeTrue()
        ->and($expansion->isArray()->call($request, 'tags'))->toBeTrue()
        ->and($expansion->inArray()->call($request, 'tags', 'cold'))->toBeTrue()
        ->and($expansion->integer()->call($request, 'count'))->toBe(12)
        ->and($expansion->searchQuery()->call($request))->toBe('fleetbase api')
        ->and($expansion->getFilters()->call($request, ['tenant_only']))->toBe([
            'ids'    => 'one,two,three',
            'files'  => '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222',
            'tags'   => ['fragile', 'cold'],
            'count'  => '12',
            'uuid'   => '11111111-1111-4111-8111-111111111111',
            'status' => 'active',
            'custom' => 'keep',
        ]);

    $expansion->removeParam()->call($request, 'status');

    expect($request->has('status'))->toBeFalse();

    $request->session()->forget('company');
    $arraySearchRequest = HttpRequest::create('/int/v1/test', 'GET', [
        'query' => ['nested'],
    ]);

    expect($expansion->company()->call($request))->toBeNull()
        ->and($expansion->searchQuery()->call($arraySearchRequest))->toBe(['nested']);
});

test('builder expansion search where applies strict and fuzzy search contracts', function () {
    core_expansion_builder_database();

    $builderExpansion = new BuilderExpansion();
    EloquentBuilder::macro('searchWhere', $builderExpansion->searchWhere());

    expect(BuilderExpansion::target())->toBe(EloquentBuilder::class);

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

    $strictEmail = CoreExpansionBuilderModel::query()
        ->searchWhere('email', 'beta@example.test', true)
        ->pluck('name')
        ->all();

    $jsonSearch = CoreExpansionBuilderModel::query()
        ->searchWhere(['meta->owner', 'name'], 'ada')
        ->toSql();

    $singleJsonSearch = CoreExpansionBuilderModel::query()
        ->searchWhere('meta->owner', 'ada')
        ->toSql();

    $invalidArrayJsonSearch = CoreExpansionBuilderModel::query()
        ->searchWhere(['meta->nested->owner', 'name'], 'ada')
        ->toSql();

    $invalidJsonSearch = CoreExpansionBuilderModel::query()
        ->searchWhere('meta->nested->owner', 'ada');

    expect($fuzzyNames)->toBe(['Alpha Fleet', 'Gamma Fleet'])
        ->and($strictNames)->toBe(['Alpha Fleet', 'Gamma Fleet'])
        ->and($commaAndDotSearch)->toBe(['Alpha Fleet'])
        ->and($strictEmail)->toBe(['Beta Dispatch'])
        ->and($jsonSearch)->toContain("json_extract(meta, '$.owner')")
        ->and($singleJsonSearch)->toContain("json_extract(meta, '$.owner')")
        ->and($invalidArrayJsonSearch)->not->toContain("json_extract(meta, '$.nested')")
        ->and($invalidJsonSearch)->toBeNull();
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

    $distance = CoreExpansionBuilderModel::query()
        ->applySortFromRequest(HttpRequest::create('/int/v1/test', 'GET', ['sort' => 'distance']))
        ->pluck('name')
        ->all();

    $unsortedQuery = CoreExpansionBuilderModel::query()
        ->applySortFromRequest(HttpRequest::create('/int/v1/test', 'GET', ['sort' => '']));

    $default = CoreExpansionBuilderModel::query()
        ->applySortFromRequest(HttpRequest::create('/int/v1/test', 'GET'))
        ->pluck('name')
        ->all();

    $applySort = $builderExpansion->applySortFromRequest();

    $arraySortQuery = CoreExpansionBuilderModel::query();
    $arraySort      = $applySort
        ->call($arraySortQuery, HttpRequest::create('/int/v1/test', 'GET', [
            'nestedSort' => [['status:desc', 'name']],
        ]))
        ->pluck('name')
        ->all();

    $nestedDescendingQuery = CoreExpansionBuilderModel::query();
    $nestedDescending      = $applySort
        ->call($nestedDescendingQuery, HttpRequest::create('/int/v1/test', 'GET', [
            'nestedSort' => [['-name', 'status:asc']],
        ]))
        ->pluck('name')
        ->all();

    $arrayDescendingQuery = CoreExpansionBuilderModel::query();
    $arrayDescendingSql   = $applySort
        ->call($arrayDescendingQuery, HttpRequest::create('/int/v1/test', 'GET', [
            'nestedSort' => ['-name'],
        ]))
        ->toSql();

    expect($latest)->toBe(['Gamma Fleet', 'Beta Dispatch', 'Alpha Fleet'])
        ->and($oldest)->toBe(['Alpha Fleet', 'Beta Dispatch', 'Gamma Fleet'])
        ->and($explicit)->toBe(['Beta Dispatch', 'Gamma Fleet', 'Alpha Fleet'])
        ->and($distance)->toBe(['Alpha Fleet', 'Beta Dispatch', 'Gamma Fleet'])
        ->and($unsortedQuery->getQuery()->orders)->toBeNull()
        ->and($default)->toBe(['Gamma Fleet', 'Beta Dispatch', 'Alpha Fleet'])
        ->and($arraySort)->toBe(['Beta Dispatch', 'Alpha Fleet', 'Gamma Fleet'])
        ->and($nestedDescending)->toBe(['Gamma Fleet', 'Beta Dispatch', 'Alpha Fleet'])
        ->and($arrayDescendingSql)->toContain('order by "name" desc');
});

test('builder expansion directive macros preserve builders when no directives resolve', function () {
    core_expansion_builder_database();
    session()->flush();

    $builderExpansion = new BuilderExpansion();
    EloquentBuilder::macro('applyDirectives', $builderExpansion->applyDirectives());
    EloquentBuilder::macro('applyDirectivesForPermissions', $builderExpansion->applyDirectivesForPermissions());

    $request           = HttpRequest::create('/int/v1/builder-records', 'GET');
    $route             = new Route(['GET'], 'int/v1/builder-records', ['controller' => CoreExpansionDirectiveControllerStub::class . '@index']);
    $route->controller = new CoreExpansionDirectiveControllerStub();
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    if (!HttpRequest::hasMacro('getController')) {
        HttpRequest::macro('getController', fn () => $this->route()?->controller);
    }

    $requestScoped = CoreExpansionBuilderModel::query()->where('status', 'active');
    $namedScoped   = CoreExpansionBuilderModel::query()->where('status', 'active');

    expect($requestScoped->applyDirectives())->toBe($requestScoped)
        ->and($requestScoped->pluck('name')->all())->toBe(['Alpha Fleet', 'Gamma Fleet'])
        ->and($namedScoped->applyDirectivesForPermissions('core list builder-record'))->toBe($namedScoped)
        ->and($namedScoped->pluck('name')->all())->toBe(['Alpha Fleet', 'Gamma Fleet']);
});

test('response expansion helpers keep internal and public error response shapes stable', function () {
    $factory           = new CoreExpansionResponseFactoryFake();
    $responseExpansion = new ResponseExpansion();

    $error              = $responseExpansion->error()->bindTo($factory, CoreExpansionResponseFactoryFake::class);
    $apiError           = $responseExpansion->apiError()->bindTo($factory, CoreExpansionResponseFactoryFake::class);
    $authorizationError = $responseExpansion->authorizationError()->bindTo($factory, CoreExpansionResponseFactoryFake::class);
    $compressedJson     = $responseExpansion->compressedJson()->bindTo($factory, CoreExpansionResponseFactoryFake::class);

    expect(ResponseExpansion::target())->toBe(Illuminate\Support\Facades\Response::class);

    $internalResponse = $error('Unable to continue', 409, ['code' => 'conflict']);
    $messageBagError  = $error(new MessageBag(['email' => ['Email is required.'], 'name' => ['Name is required.']]), 422);
    $publicResponse   = $apiError(['message' => 'Invalid request'], 422, ['request_id' => 'req_123']);
    $apiBagResponse   = $apiError(new MessageBag(['token' => ['Token expired.']]), 401);
    $compressed       = $compressedJson(['name' => 'Fleetbase', 'enabled' => true], 202, ['X-Trace' => 'trace_123']);

    core_expansion_request('/int/v1/api-credentials', 'DELETE');
    $authResponse = $authorizationError(['required' => true]);

    expect($internalResponse->getStatusCode())->toBe(409)
        ->and($internalResponse->getData(true))->toBe([
            'errors' => ['Unable to continue'],
            'code'   => 'conflict',
        ])
        ->and($messageBagError->getData(true))->toBe([
            'errors' => ['Email is required.', 'Name is required.'],
        ])
        ->and($publicResponse->getStatusCode())->toBe(422)
        ->and($publicResponse->getData(true))->toBe([
            'error'      => ['message' => 'Invalid request'],
            'request_id' => 'req_123',
        ])
        ->and($apiBagResponse->getData(true))->toBe([
            'error' => ['Token expired.'],
        ])
        ->and($authResponse->getStatusCode())->toBe(401)
        ->and($authResponse->getData(true))->toBe([
            'errors'   => ['User is not authorized to delete api-credential'],
            'required' => true,
        ])
        ->and($compressed->getStatusCode())->toBe(202)
        ->and($compressed->headers->get('X-Compressed-Json'))->toBe('1')
        ->and($compressed->headers->get('X-Trace'))->toBe('trace_123')
        ->and($compressed->getData(true))->toBe([
            ['{"name":"Fleetbase","enabled":true}'],
            '0',
        ]);
});

test('response expansion validation errors distinguish internal and public api response shapes', function () {
    core_expansion_define_validation_exception();

    $factory           = new CoreExpansionResponseFactoryFake();
    $responseExpansion = new ResponseExpansion();
    $validationError   = $responseExpansion->validationError()->bindTo($factory, CoreExpansionResponseFactoryFake::class);

    core_expansion_request('/int/v1/users');

    try {
        $validationError(core_expansion_validator([
            'email' => ['Email is required.'],
            'name'  => ['Name is required.'],
        ]));
    } catch (ValidationException $exception) {
        $internal = core_expansion_validation_response($exception);
    }

    core_expansion_request('/v1/users');

    try {
        $validationError(core_expansion_validator([
            'email' => ['Email is required.'],
            'name'  => ['Name is required.'],
        ]));
    } catch (ValidationException $exception) {
        $public = core_expansion_validation_response($exception);
    }

    expect($internal)->toBeInstanceOf(JsonResponse::class)
        ->and($internal->getStatusCode())->toBe(422)
        ->and($internal->getData(true))->toBe([
            'errors' => ['Email is required.', 'Name is required.'],
        ])
        ->and($public)->toBeInstanceOf(JsonResponse::class)
        ->and($public->getStatusCode())->toBe(422)
        ->and($public->getData(true))->toBe([
            'error'  => 'Email is required.',
            'errors' => ['Email is required.', 'Name is required.'],
        ]);
});
