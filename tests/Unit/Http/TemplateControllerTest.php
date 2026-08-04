<?php

use Fleetbase\Http\Controllers\Internal\v1\TemplateController;
use Fleetbase\Models\Template;
use Fleetbase\Models\TemplateQuery;
use Fleetbase\Services\TemplateRenderService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class TemplateControllerRenderServiceFake extends TemplateRenderService
{
    public ?Template $lastHtmlTemplate = null;
    public ?Model $lastHtmlSubject     = null;
    public ?Template $lastPdfTemplate  = null;
    public ?Model $lastPdfSubject      = null;
    public ?string $lastPdfFilename    = null;

    public function __construct(private array $schemas = [])
    {
    }

    public function getContextSchemas(): array
    {
        return $this->schemas;
    }

    public function renderToHtml(Template $template, ?Model $subject = null): string
    {
        $this->lastHtmlTemplate = $template;
        $this->lastHtmlSubject  = $subject;

        return '<main>preview:' . $template->name . ':' . ($subject?->uuid ?? 'none') . '</main>';
    }

    public function renderToPdf(Template $template, ?Model $subject = null): TemplateControllerPdfFake
    {
        $this->lastPdfTemplate = $template;
        $this->lastPdfSubject  = $subject;

        return new TemplateControllerPdfFake($this);
    }
}

class TemplateControllerPdfFake
{
    public function __construct(private TemplateControllerRenderServiceFake $service)
    {
    }

    public function download(string $filename): Response
    {
        $this->service->lastPdfFilename = $filename;

        return new Response('pdf:' . $filename, 200, [
            'content-type'        => 'application/pdf',
            'content-disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}

class TemplateControllerTaggedCacheFake
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

class TemplateControllerResponseCacheFake
{
    public function clear(): void
    {
    }
}

class TemplateControllerSubject extends Model
{
    protected $table = 'template_controller_subjects';

    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'name',
    ];
}

if (!function_exists('event')) {
    function event(mixed $event = null): mixed
    {
        if (array_key_exists('webhook_events_observer_events', $GLOBALS)) {
            $GLOBALS['webhook_events_observer_events'][] = $event;
        }

        if (array_key_exists('trigger_public_notification_broadcast_events', $GLOBALS)) {
            $GLOBALS['trigger_public_notification_broadcast_events'][] = $event;
        }

        return $event;
    }
}

function template_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');

    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    $container->instance('responsecache', new TemplateControllerResponseCacheFake());
    Cache::swap(new TemplateControllerTaggedCacheFake());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');
    Facade::clearResolvedInstance('schema');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-1',
    ]);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('templates', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('context_type')->nullable();
        $table->string('unit')->nullable();
        $table->float('width')->nullable();
        $table->float('height')->nullable();
        $table->string('orientation')->nullable();
        $table->json('margins')->nullable();
        $table->string('background_color')->nullable();
        $table->string('background_image_uuid')->nullable();
        $table->json('content')->nullable();
        $table->json('element_schemas')->nullable();
        $table->boolean('is_default')->default(false);
        $table->boolean('is_system')->default(false);
        $table->boolean('is_public')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('template_queries', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('template_uuid')->nullable()->index();
        $table->string('created_by_uuid')->nullable();
        $table->string('model_type')->nullable();
        $table->string('variable_name')->nullable();
        $table->string('label')->nullable();
        $table->json('conditions')->nullable();
        $table->json('sort')->nullable();
        $table->integer('limit')->nullable();
        $table->json('with')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('template_controller_subjects', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('templates')->insert([
        [
            'uuid'         => 'template-1',
            'public_id'    => 'template_public_1',
            'company_uuid' => 'company-1',
            'name'         => 'Invoice Template',
            'context_type' => 'coverage_subject',
            'content'      => json_encode([]),
            'margins'      => json_encode([]),
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => 'template-other',
            'public_id'    => 'template_public_other',
            'company_uuid' => 'company-2',
            'name'         => 'Other Template',
            'context_type' => 'coverage_subject',
            'content'      => json_encode([]),
            'margins'      => json_encode([]),
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ]);
    $capsule->getConnection('mysql')->table('template_queries')->insert([
        [
            'uuid'          => 'query-keep',
            'public_id'     => 'tq_keep',
            'company_uuid'  => 'company-1',
            'template_uuid' => 'template-1',
            'model_type'    => TemplateControllerSubject::class,
            'variable_name' => 'subjects',
            'label'         => 'Subjects',
            'conditions'    => json_encode([['field' => 'name', 'operator' => 'like', 'value' => 'Acme']]),
            'sort'          => json_encode([]),
            'with'          => json_encode([]),
            'limit'         => 3,
            'created_at'    => $now,
            'updated_at'    => $now,
        ],
        [
            'uuid'          => 'query-remove',
            'public_id'     => 'tq_remove',
            'company_uuid'  => 'company-1',
            'template_uuid' => 'template-1',
            'model_type'    => TemplateControllerSubject::class,
            'variable_name' => 'removed_subjects',
            'label'         => 'Removed Subjects',
            'conditions'    => json_encode([]),
            'sort'          => json_encode([]),
            'with'          => json_encode([]),
            'limit'         => null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ],
    ]);
    $capsule->getConnection('mysql')->table('template_controller_subjects')->insert([
        ['uuid' => 'subject-1', 'public_id' => 'subject_public_1', 'company_uuid' => 'company-1', 'name' => 'Acme Subject', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'subject-other', 'public_id' => 'subject_public_other', 'company_uuid' => 'company-2', 'name' => 'Other Subject', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function template_controller_service(): TemplateControllerRenderServiceFake
{
    return new TemplateControllerRenderServiceFake([
        'coverage_subject' => [
            'label'     => 'Coverage Subject',
            'model'     => TemplateControllerSubject::class,
            'variables' => [],
        ],
    ]);
}

function template_controller(TemplateControllerRenderServiceFake $service): TemplateController
{
    return new TemplateController($service);
}

function template_controller_payload(JsonResponse $response): array
{
    return $response->getData(true);
}

function template_controller_reflect(TemplateController $controller, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(TemplateController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

afterEach(function () {
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

test('template controller previews unsaved templates with transient queries and allowed tenant scoped subjects', function () {
    template_controller_database();
    $service    = template_controller_service();
    $controller = template_controller($service);

    $response = $controller->previewUnsaved(Request::create('/int/v1/templates/preview', 'POST', [
        'template' => [
            'name'         => 'Unsaved Preview',
            'content'      => [['type' => 'text', 'content' => 'Hello']],
            'context_type' => 'coverage_subject',
            'queries'      => [
                [
                    'label'         => 'Preview Subjects',
                    'variable_name' => 'preview_subjects',
                    'model_type'    => TemplateControllerSubject::class,
                    'conditions'    => [['field' => 'name', 'operator' => 'like', 'value' => 'Acme']],
                    'sort'          => [['field' => 'name', 'direction' => 'asc']],
                    'limit'         => 5,
                    'with'          => [],
                ],
            ],
        ],
        'subject_type' => TemplateControllerSubject::class,
        'subject_id'   => 'subject_public_1',
    ]));

    $template = $service->lastHtmlTemplate;
    $query    = $template->queries->first();

    expect(template_controller_payload($response))->toBe(['html' => '<main>preview:Unsaved Preview:subject-1</main>'])
        ->and($template->exists)->toBeFalse()
        ->and($template->name)->toBe('Unsaved Preview')
        ->and($template->context_type)->toBe('coverage_subject')
        ->and($template->queries)->toHaveCount(1)
        ->and($query)->toBeInstanceOf(TemplateQuery::class)
        ->and($query->company_uuid)->toBe('company-1')
        ->and($query->variable_name)->toBe('preview_subjects')
        ->and($query->limit)->toBe(5)
        ->and($service->lastHtmlSubject?->uuid)->toBe('subject-1');
});

test('template controller rejects disallowed and cross tenant preview subjects', function () {
    template_controller_database();
    $service    = template_controller_service();
    $controller = template_controller($service);

    $disallowedSubject = template_controller_reflect($controller, 'resolveTemplateSubject', Template::class, 'template_public_1');
    $crossTenant       = template_controller_reflect($controller, 'resolveTemplateSubject', TemplateControllerSubject::class, 'subject_public_other');
    $allowedSubject    = template_controller_reflect($controller, 'resolveTemplateSubject', TemplateControllerSubject::class, 'subject_public_1');

    expect($disallowedSubject)->toBeNull()
        ->and($crossTenant)->toBeNull()
        ->and($allowedSubject)->toBeInstanceOf(TemplateControllerSubject::class)
        ->and($allowedSubject->uuid)->toBe('subject-1');
});

test('template controller previews and renders only active company templates', function () {
    template_controller_database();
    $service    = template_controller_service();
    $controller = template_controller($service);

    $preview = $controller->preview('template_public_1', Request::create('/int/v1/templates/template_public_1/preview', 'POST', [
        'subject_type' => TemplateControllerSubject::class,
        'subject_id'   => 'subject_public_1',
    ]));
    $download = $controller->render('template_public_1', Request::create('/int/v1/templates/template_public_1/render', 'POST', [
        'subject_type' => TemplateControllerSubject::class,
        'subject_id'   => 'subject_public_1',
        'filename'     => 'invoice-preview',
    ]));
    $foreignTemplate = template_controller_reflect($controller, 'templateQuery', 'template_public_other')->first();

    expect(template_controller_payload($preview))->toBe(['html' => '<main>preview:Invoice Template:subject-1</main>'])
        ->and($download)->toBeInstanceOf(Response::class)
        ->and($download->getContent())->toBe('pdf:invoice-preview.pdf')
        ->and($service->lastPdfFilename)->toBe('invoice-preview.pdf')
        ->and($service->lastPdfTemplate?->uuid)->toBe('template-1')
        ->and($service->lastPdfSubject?->uuid)->toBe('subject-1')
        ->and($foreignTemplate)->toBeNull();
});

test('template controller exposes context schemas from the render service', function () {
    template_controller_database();
    $controller = template_controller(template_controller_service());

    $response = $controller->contextSchemas();

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and(template_controller_payload($response))->toBe([
            'schemas' => [
                'coverage_subject' => [
                    'label'     => 'Coverage Subject',
                    'model'     => TemplateControllerSubject::class,
                    'variables' => [],
                ],
            ],
        ]);
});

test('template controller create hook syncs nested queries and reloads the relation', function () {
    template_controller_database();
    $controller = template_controller(template_controller_service());
    $template   = Template::where('uuid', 'template-1')->firstOrFail();

    $controller->onAfterCreate(Request::create('/int/v1/templates', 'POST', [
        'queries' => [
            [
                'label'         => 'Created On Hook',
                'variable_name' => 'created_on_hook',
                'model_type'    => TemplateControllerSubject::class,
                'conditions'    => [],
                'sort'          => [],
                'limit'         => 4,
                'with'          => [],
            ],
        ],
    ]), $template, []);

    expect($template->relationLoaded('queries'))->toBeTrue()
        ->and($template->queries)->toHaveCount(1)
        ->and($template->queries->first()->variable_name)->toBe('created_on_hook')
        ->and($template->queries->first()->company_uuid)->toBe('company-1');
});

test('template controller syncs nested queries by updating keeping creating and soft deleting removed queries', function () {
    template_controller_database();
    $service    = template_controller_service();
    $controller = template_controller($service);
    $template   = Template::where('uuid', 'template-1')->firstOrFail();

    $controller->onAfterUpdate(Request::create('/int/v1/templates/template_public_1', 'PUT', [
        'queries' => [
            [
                'uuid'          => 'query-keep',
                'label'         => 'Updated Subjects',
                'variable_name' => 'updated_subjects',
                'model_type'    => TemplateControllerSubject::class,
                'conditions'    => [['field' => 'name', 'operator' => '=', 'value' => 'Acme Subject']],
                'sort'          => [['field' => 'name', 'direction' => 'desc']],
                'limit'         => 9,
                'with'          => [],
            ],
            [
                'uuid'          => '_new_client_row',
                'label'         => 'Created Subjects',
                'variable_name' => 'created_subjects',
                'model_type'    => TemplateControllerSubject::class,
                'conditions'    => [],
                'sort'          => [],
                'limit'         => 2,
                'with'          => [],
            ],
        ],
    ]), $template, []);

    $kept    = TemplateQuery::where('uuid', 'query-keep')->firstOrFail();
    $created = TemplateQuery::where('template_uuid', 'template-1')->where('variable_name', 'created_subjects')->firstOrFail();
    $removed = TemplateQuery::withTrashed()->where('uuid', 'query-remove')->firstOrFail();

    expect($kept->label)->toBe('Updated Subjects')
        ->and($kept->variable_name)->toBe('updated_subjects')
        ->and($kept->conditions)->toBe([['field' => 'name', 'operator' => '=', 'value' => 'Acme Subject']])
        ->and($kept->sort)->toBe([['field' => 'name', 'direction' => 'desc']])
        ->and($kept->limit)->toBe(9)
        ->and($created->uuid)->not->toBe('_new_client_row')
        ->and($created->company_uuid)->toBe('company-1')
        ->and($created->created_by_uuid)->toBe('user-1')
        ->and($created->limit)->toBe(2)
        ->and($removed->trashed())->toBeTrue();
});
