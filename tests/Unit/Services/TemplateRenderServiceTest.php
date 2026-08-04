<?php

use Fleetbase\Models\Template;
use Fleetbase\Services\TemplateRenderService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Spatie\LaravelPdf\Facades\Pdf;

class TemplateRenderServiceSessionFake
{
    public function get(string $key, mixed $default = null): mixed
    {
        return session($key, $default);
    }
}

class TemplateRenderServiceAuthFake
{
    public static mixed $user = null;

    public function user(): mixed
    {
        return self::$user;
    }
}

class TemplateRenderServiceOrder extends Model
{
    public function toArray(): array
    {
        return [
            'number'   => 'T-001',
            'customer' => [
                'name' => 'Acme Logistics',
            ],
            'subtotal'   => 100,
            'line_items' => [
                ['name' => 'Freight', 'amount' => 75],
                ['name' => 'Handling', 'amount' => 25],
            ],
        ];
    }
}

class TemplateRenderServiceUser extends Model
{
    public function toArray(): array
    {
        return [
            'name'  => 'Render User',
            'email' => 'render@example.test',
        ];
    }
}

class TemplateRenderServiceLineItem extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public function toArray(): array
    {
        return $this->attributesToArray();
    }
}

class TemplateRenderServiceQueryFake
{
    public function __construct(public string $variable_name, private Collection $results)
    {
    }

    public function execute(): Collection
    {
        return $this->results;
    }
}

class TemplateRenderServiceLazyTemplate extends Template
{
    public bool $loadMissingCalled = false;

    public function loadMissing($relations)
    {
        if ($relations === 'queries') {
            $this->loadMissingCalled = true;
            $this->setRelation('queries', new Collection([
                new TemplateRenderServiceQueryFake('lazy_items', new Collection([
                    new TemplateRenderServiceLineItem(['name' => 'Lazy Freight', 'amount' => 44]),
                ])),
            ]));

            return $this;
        }

        return parent::loadMissing($relations);
    }
}

class TemplateRenderServiceArithmeticFailure extends TemplateRenderService
{
    public function evaluateForTest(string $expression): string
    {
        return $this->evaluateArithmetic($expression);
    }

    protected function parseExpression(string $expr): float
    {
        throw new RuntimeException('parser unavailable');
    }
}

class TemplateRenderServiceParserProbe extends TemplateRenderService
{
    public function parseForTest(string $expression): float
    {
        return $this->parseExpression($expression);
    }
}

function template_render_service_container(): void
{
    $container = bind_test_container([
        'fleetbase.template_query_models'        => [],
        'fleetbase.template_global_query_models' => [],
    ]);

    session()->flush();
    TemplateRenderServiceAuthFake::$user = null;
    $container->instance('session', new TemplateRenderServiceSessionFake());
    $container->instance('auth', new TemplateRenderServiceAuthFake());
    Facade::clearResolvedInstance('session');
    Facade::clearResolvedInstance('auth');
}

function template_render_service_template(array $content): Template
{
    $template = new Template([
        'context_type'     => 'order',
        'width'            => 210,
        'height'           => 297,
        'unit'             => 'mm',
        'background_color' => '#fafafa',
        'content'          => $content,
    ]);
    $template->setRelation('queries', new Collection());

    return $template;
}

function template_render_service_database(): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = app();
    $container['config']->set('database.default', 'mysql');
    $container['config']->set('database.connections.mysql', $connection);
    $container['config']->set('fleetbase.connection.db', 'mysql');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('mysql')->getSchemaBuilder()->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    return $capsule;
}

test('template render service registers context schemas and query model allowlists', function () {
    template_render_service_container();

    TemplateRenderService::registerContextType('coverage_order', [
        'label'       => 'Coverage Order',
        'description' => 'Order context used by coverage tests.',
        'model'       => TemplateRenderServiceOrder::class,
        'variables'   => [
            ['name' => 'Order Number', 'path' => 'coverage_order.number', 'type' => 'string'],
        ],
    ]);

    $service = new TemplateRenderService();
    $schemas = $service->getContextSchemas();

    expect($schemas['coverage_order']['label'])->toBe('Coverage Order')
        ->and($schemas['coverage_order']['global_variables'])->toContain([
            'name'        => 'Current Year',
            'path'        => 'year',
            'type'        => 'integer',
            'description' => 'Current 4-digit year.',
        ])
        ->and(TemplateRenderService::getTemplateQueryModels())->toContain(TemplateRenderServiceOrder::class)
        ->and(TemplateRenderService::isTemplateQueryModelAllowed(TemplateRenderServiceOrder::class))->toBeTrue()
        ->and(TemplateRenderService::isTemplateQueryModelAllowed(Template::class))->toBeFalse();
});

test('template render service filters invalid query model config and honors global query allowlists', function () {
    template_render_service_container();

    app('config')->set('fleetbase.template_query_models', [
        TemplateRenderServiceOrder::class,
        Template::class,
        'NotAClass',
        42,
        TemplateRenderServiceOrder::class,
    ]);
    app('config')->set('fleetbase.template_global_query_models', [
        TemplateRenderServiceOrder::class,
    ]);

    $models = TemplateRenderService::getTemplateQueryModels();

    expect($models)->toContain(TemplateRenderServiceOrder::class)
        ->and($models)->not->toContain('NotAClass')
        ->and($models)->not->toContain(42)
        ->and(array_count_values($models)[TemplateRenderServiceOrder::class])->toBe(1)
        ->and(TemplateRenderService::isTemplateQueryModelAllowed(null))->toBeFalse()
        ->and(TemplateRenderService::isTemplateQueryModelAllowed(TemplateRenderServiceOrder::class))->toBeTrue()
        ->and(TemplateRenderService::isTemplateQueryModelGloballyQueryable(TemplateRenderServiceUser::class))->toBeFalse();
});

test('template render service renders variables formulas loops tables and document wrapper', function () {
    template_render_service_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:34:56'));

    $template = template_render_service_template([
        [
            'type'    => 'heading',
            'x'       => 10,
            'y'       => 20,
            'width'   => 180,
            'height'  => 24,
            'styles'  => ['fontSize' => '18px', 'fontWeight' => '700'],
            'content' => 'Order {order.number} for {order.customer.name} on {today}',
        ],
        [
            'type'    => 'text',
            'x'       => 10,
            'y'       => 50,
            'width'   => 180,
            'content' => 'Taxed total: [{ {order.subtotal} * 1.1 }]',
        ],
        [
            'type'    => 'paragraph',
            'x'       => 10,
            'y'       => 80,
            'width'   => 180,
            'content' => '{{#each order.line_items}}{loop.index}:{this.name}:{this.amount}:{loop.first}:{loop.last};{{/each}}',
        ],
        [
            'type'    => 'table',
            'x'       => 10,
            'y'       => 120,
            'width'   => 180,
            'columns' => [
                ['label' => 'Item', 'key' => 'name', 'width' => '70%'],
                ['label' => 'Amount', 'key' => 'amount'],
            ],
            'data_source' => 'order.line_items',
        ],
    ]);

    $html = (new TemplateRenderService())->renderToHtml($template, new TemplateRenderServiceOrder());

    expect($html)->toContain('<!DOCTYPE html>')
        ->and($html)->toContain('width: 210mm;')
        ->and($html)->toContain('height: 297mm;')
        ->and($html)->toContain('background: #fafafa;')
        ->and($html)->toContain('font-size: 18px; font-weight: 700;')
        ->and($html)->toContain('Order T-001 for Acme Logistics on 2026-07-17')
        ->and($html)->toContain('Taxed total: 110')
        ->and($html)->toContain('0:Freight:75:true:false;1:Handling:25:false:true;')
        ->and($html)->toContain('<th style="width:70%">Item</th>')
        ->and($html)->toContain('<td>Freight</td>')
        ->and($html)->toContain('<td>25</td>');

    Carbon::setTestNow();
});

test('template render service renders pdf builder with template dimensions and margins', function () {
    template_render_service_container();
    Pdf::fake();

    $template          = template_render_service_template([
        [
            'type'    => 'paragraph',
            'content' => 'PDF order {order.number}',
        ],
    ]);
    $template->width   = 8.5;
    $template->height  = 11;
    $template->unit    = 'in';
    $template->margins = [
        'top'    => 0.25,
        'right'  => 0.5,
        'bottom' => 0.75,
        'left'   => 1.0,
    ];

    $pdf = (new TemplateRenderService())->renderToPdf($template, new TemplateRenderServiceOrder());

    expect($pdf->html)->toContain('PDF order T-001')
        ->and($pdf->paperSize)->toBe([
            'width'  => 8.5,
            'height' => 11.0,
            'unit'   => 'in',
        ])
        ->and($pdf->margins)->toBe([
            'top'    => 0.25,
            'right'  => 0.5,
            'bottom' => 0.75,
            'left'   => 1.0,
            'unit'   => 'in',
        ]);
});

test('template render service renders alternate elements static tables and defensive template branches', function () {
    template_render_service_container();

    $template = template_render_service_template([
        [
            'type'     => 'image',
            'x'        => 5,
            'y'        => 6,
            'width'    => '50%',
            'height'   => 'auto',
            'rotation' => 15,
            'src'      => 'https://fleetbase.test/logo.png',
            'styles'   => ['backgroundColor' => '', 'borderColor' => null],
        ],
        [
            'type'   => 'line',
            'x'      => 10,
            'y'      => 15,
            'width'  => 140,
            'styles' => ['borderTop' => '2px dashed #333'],
        ],
        [
            'type'   => 'rectangle',
            'x'      => 20,
            'y'      => 25,
            'width'  => 30,
            'height' => 40,
        ],
        [
            'type'  => 'qr_code',
            'x'     => 30,
            'y'     => 35,
            'value' => 'order:T-001',
        ],
        [
            'type'  => 'barcode',
            'x'     => 40,
            'y'     => 45,
            'value' => 'B-001',
        ],
        [
            'type'    => 'unknown_widget',
            'x'       => 50,
            'y'       => 55,
            'content' => 'Fallback {missing.value}',
        ],
        [
            'type'    => 'paragraph',
            'x'       => 60,
            'y'       => 65,
            'content' => 'Empty loop:{{#each order.missing_items}}should disappear{{/each}}',
        ],
        [
            'type'    => 'paragraph',
            'x'       => 70,
            'y'       => 75,
            'content' => 'Array variable suppressed: {order.line_items}',
        ],
        [
            'type'    => 'paragraph',
            'x'       => 80,
            'y'       => 85,
            'content' => 'Fallback arithmetic: [{ (10 - 3) / 0 + -2 + abc }]',
        ],
        [
            'type'    => 'table',
            'x'       => 10,
            'y'       => 90,
            'width'   => 180,
            'columns' => [
                ['label' => 'Item', 'key' => 'name'],
                ['label' => 'Amount', 'key' => 'amount'],
            ],
            'rows' => [
                ['name' => 'Accessorial', 'amount' => 15],
                ['name' => 'Fuel', 'amount' => 35],
            ],
        ],
    ]);

    $html = (new TemplateRenderService())->renderToHtml($template, new TemplateRenderServiceOrder());

    expect($html)->toContain('<img src="https://fleetbase.test/logo.png"')
        ->and($html)->toContain('width: 50%;')
        ->and($html)->toContain('transform: rotate(15deg);')
        ->and($html)->toContain('<hr style="position: absolute; left: 10px; top: 15px; width: 140px; border-top: 2px dashed #333;"')
        ->and($html)->toContain('data-qr="order:T-001"')
        ->and($html)->toContain('data-barcode="B-001"')
        ->and($html)->toContain('Fallback ')
        ->and($html)->toContain('Empty loop:')
        ->and($html)->not->toContain('should disappear')
        ->and($html)->toContain('Array variable suppressed: ')
        ->and($html)->toContain('Fallback arithmetic: #ERR')
        ->and($html)->toContain('<th>Item</th>')
        ->and($html)->toContain('<td>Accessorial</td>')
        ->and($html)->toContain('<td>35</td>');
});

test('template render service guesses generic subject keys and renders query result collections', function () {
    template_render_service_container();

    $template = template_render_service_template([
        [
            'type'    => 'paragraph',
            'content' => '{template_render_service_order.number}|{{#each query_items}}{this.name}:{this.amount};{{/each}}',
        ],
    ]);
    $template->context_type = 'generic';
    $template->setRelation('queries', new Collection([
        new TemplateRenderServiceQueryFake('query_items', new Collection([
            new TemplateRenderServiceLineItem(['name' => 'Query Freight', 'amount' => 75]),
            new TemplateRenderServiceLineItem(['name' => 'Query Handling', 'amount' => 25]),
        ])),
    ]));

    $html = (new TemplateRenderService())->renderToHtml($template, new TemplateRenderServiceOrder());

    expect($html)->toContain('T-001|Query Freight:75;Query Handling:25;');
});

test('template render service lazy loads query collections and resolves session company context', function () {
    template_render_service_container();
    $capsule = template_render_service_database();
    $capsule->getConnection('mysql')->table('companies')->insert([
        'uuid'       => 'company-render',
        'public_id'  => 'company_render',
        'name'       => 'Render Company',
        'created_at' => '2026-07-26 00:00:00',
        'updated_at' => '2026-07-26 00:00:00',
    ]);
    session(['company' => 'company-render']);

    $template = new TemplateRenderServiceLazyTemplate([
        'context_type' => 'generic',
        'width'        => 210,
        'height'       => 297,
        'unit'         => 'mm',
        'content'      => [
            [
                'type'    => 'paragraph',
                'content' => '{company.name}|{{#each lazy_items}}{this.name}:{this.amount};{{/each}}',
            ],
        ],
    ]);

    $html = (new TemplateRenderService())->renderToHtml($template);

    expect($template->loadMissingCalled)->toBeTrue()
        ->and($html)->toContain('Render Company|Lazy Freight:44;');
});

test('template render service includes authenticated user context when available', function () {
    template_render_service_container();

    TemplateRenderServiceAuthFake::$user = new TemplateRenderServiceUser();

    $template = template_render_service_template([
        [
            'type'    => 'paragraph',
            'content' => 'Rendered for {user.name} at {user.email}',
        ],
    ]);

    $html = (new TemplateRenderService())->renderToHtml($template);

    expect($html)->toContain('Rendered for Render User at render@example.test');
});

test('template render service returns formula errors when arithmetic fallback parsing fails', function () {
    template_render_service_container();

    expect((new TemplateRenderServiceArithmeticFailure())->evaluateForTest('1 + 2'))->toBe('3')
        ->and((new TemplateRenderServiceArithmeticFailure())->evaluateForTest('1 + unknown'))->toBe('#ERR');
});

test('template render service fallback arithmetic parser handles precedence unary values and defensive literals', function () {
    $parser = new TemplateRenderServiceParserProbe();

    expect($parser->parseForTest(' 1 + 2 * 3 '))->toBe(7.0)
        ->and($parser->parseForTest('(10 - 4) / 3'))->toBe(2.0)
        ->and($parser->parseForTest('-5 + 2'))->toBe(-3.0)
        ->and($parser->parseForTest('8 / 0'))->toBe(0.0)
        ->and($parser->parseForTest('missing'))->toBe(0.0);
});
