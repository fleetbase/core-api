<?php

namespace Fleetbase\Tests\FilterFixtures {
    use Fleetbase\Http\Filter\Filter;
    use Illuminate\Database\Eloquent\Model;

    class FilterWidget extends Model
    {
        protected $connection = 'mysql';
        protected $table      = 'filter_widgets';
        protected $guarded    = [];
        public $timestamps    = false;
    }

    class FilterWidgetFilter extends Filter
    {
        public function query(?string $query): void
        {
            $this->builder->where('name', 'like', '%' . $query . '%');
        }

        public function status(string $status): void
        {
            $this->builder->where('status', $status);
        }

        public function snakeCaseFilter(string $value): void
        {
            $this->builder->where('snake_case_value', $value);
        }

        public function createdBetween(?string $after, ?string $before): void
        {
            $this->builder->whereBetween('created_at', [$after, $before]);
        }

        public function queryForInternal(): void
        {
            $this->builder->where('company_uuid', $this->session->get('company'));
        }

        public function queryForPublic(): void
        {
            $this->builder->where('is_public', true);
        }
    }
}

namespace {
    use Fleetbase\Tests\FilterFixtures\FilterWidget;
    use Fleetbase\Tests\FilterFixtures\FilterWidgetFilter;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Eloquent\Model as EloquentModel;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Facade;

    class FilterPipelineRoute
    {
        public array $action;

        public function __construct(private string $uri, string $namespace = '')
        {
            $this->action = ['namespace' => $namespace];
        }

        public function uri(): string
        {
            return $this->uri;
        }
    }

    function filter_pipeline_database(): Capsule
    {
        EloquentModel::clearBootedModels();

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
        Facade::clearResolvedInstance('db');

        $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
        $schema->dropIfExists('filter_widgets');
        $schema->create('filter_widgets', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->string('snake_case_value')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        $capsule->getConnection('mysql')->table('filter_widgets')->insert([
            [
                'uuid'             => 'widget-1',
                'company_uuid'     => 'company-1',
                'name'             => 'Alpha Dispatch',
                'status'           => 'active',
                'snake_case_value' => 'special',
                'is_public'        => true,
                'created_at'       => '2026-01-10 00:00:00',
            ],
            [
                'uuid'             => 'widget-2',
                'company_uuid'     => 'company-1',
                'name'             => 'Beta Dispatch',
                'status'           => 'inactive',
                'snake_case_value' => 'ordinary',
                'is_public'        => false,
                'created_at'       => '2026-02-10 00:00:00',
            ],
            [
                'uuid'             => 'widget-3',
                'company_uuid'     => 'company-2',
                'name'             => 'Alpha External',
                'status'           => 'active',
                'snake_case_value' => 'special',
                'is_public'        => true,
                'created_at'       => '2026-03-10 00:00:00',
            ],
        ]);

        session()->flush();
        session(['company' => 'company-1']);

        return $capsule;
    }

    function filter_pipeline_request(array $query, string $routeUri = 'int/v1/filter-widgets'): Request
    {
        $request = Request::create('/' . $routeUri, 'GET', $query);
        $request->setRouteResolver(fn () => new FilterPipelineRoute($routeUri));

        return $request;
    }

    function filter_pipeline_uuids(array $query, string $routeUri = 'int/v1/filter-widgets'): array
    {
        filter_pipeline_database();

        return (new FilterWidgetFilter(filter_pipeline_request($query, $routeUri)))
            ->apply(FilterWidget::query())
            ->orderBy('uuid')
            ->pluck('uuid')
            ->all();
    }

    afterEach(function () {
        FilterWidgetFilter::expand('specialExpansion', null);
        session()->flush();
        EloquentModel::clearBootedModels();
        Facade::clearResolvedInstances();
    });

    test('filter pipeline applies named filters while skipping pagination sorting and empty values', function () {
        $uuids = filter_pipeline_uuids([
            'query'  => 'Dispatch',
            'status' => 'active',
            'limit'  => 1,
            'page'   => 3,
            'sort'   => '-name',
            'with'   => 'company',
            'name'   => '',
        ]);

        expect($uuids)->toBe(['widget-1']);
    });

    test('filter pipeline resolves camel case filters from snake case request parameters', function () {
        $uuids = filter_pipeline_uuids([
            'snake_case_filter' => 'special',
        ]);

        expect($uuids)->toBe(['widget-1']);
    });

    test('filter pipeline builds range callbacks from paired request suffixes', function () {
        $uuids = filter_pipeline_uuids([
            'created_after'  => '2026-01-01 00:00:00',
            'created_before' => '2026-01-31 23:59:59',
            '_after'         => '2026-01-01 00:00:00',
        ]);

        expect($uuids)->toBe(['widget-1']);
    });

    test('filter pipeline applies internal and public route scopes after request filters', function () {
        expect(filter_pipeline_uuids(['status' => 'active'], 'int/v1/filter-widgets'))->toBe(['widget-1'])
            ->and(filter_pipeline_uuids(['status' => 'active'], 'v1/filter-widgets'))->toBe(['widget-1', 'widget-3']);
    });

    test('filter pipeline invokes dynamic expansions using snake or camel case request parameters', function () {
        filter_pipeline_database();

        FilterWidgetFilter::expand('specialExpansion', function (string $value): void {
            $this->builder->where('snake_case_value', $value);
        });

        $camel = (new FilterWidgetFilter(filter_pipeline_request([
            'special_expansion' => 'special',
        ])))->apply(FilterWidget::query())->orderBy('uuid')->pluck('uuid')->all();

        $raw = (new FilterWidgetFilter(filter_pipeline_request([
            'specialExpansion' => 'special',
        ])))->apply(FilterWidget::query())->orderBy('uuid')->pluck('uuid')->all();

        expect($camel)->toBe(['widget-1'])
            ->and($raw)->toBe(['widget-1']);
    });
}
