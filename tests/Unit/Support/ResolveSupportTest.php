<?php

namespace Fleetbase\Tests\ResolveFixtures\Models {
    use Illuminate\Database\Eloquent\Model;

    class ResolveWidget extends Model
    {
        protected $connection = 'mysql';
        protected $table      = 'resolve_widgets';
        protected $primaryKey = 'uuid';
        protected $keyType    = 'string';
        public $incrementing  = false;
        public $timestamps    = false;
        protected $guarded    = [];
    }

    class ResolveMissingContract extends Model
    {
    }
}

namespace Fleetbase\Tests\ResolveFixtures\Http\Resources\v1 {
    use Illuminate\Http\Resources\Json\JsonResource;

    class ResolveWidget extends JsonResource
    {
    }
}

namespace Fleetbase\Tests\ResolveFixtures\Http\Requests {
    use Illuminate\Foundation\Http\FormRequest;

    class CreateResolveWidgetRequest extends FormRequest
    {
    }
}

namespace Fleetbase\Tests\ResolveFixtures\Http\Filter {
    use Fleetbase\Http\Filter\Filter;

    class ResolveWidgetFilter extends Filter
    {
    }
}

namespace {
    use Fleetbase\Http\Requests\FleetbaseRequest;
    use Fleetbase\Http\Resources\FleetbaseResource;
    use Fleetbase\Support\Resolve;
    use Fleetbase\Tests\ResolveFixtures\Http\Filter\ResolveWidgetFilter;
    use Fleetbase\Tests\ResolveFixtures\Http\Requests\CreateResolveWidgetRequest;
    use Fleetbase\Tests\ResolveFixtures\Http\Resources\v1\ResolveWidget as ResolveWidgetResource;
    use Fleetbase\Tests\ResolveFixtures\Models\ResolveMissingContract;
    use Fleetbase\Tests\ResolveFixtures\Models\ResolveWidget;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Eloquent\Model as EloquentModel;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Facade;

    class ResolveSupportRoute
    {
        public array $action = [];

        public function __construct(private string $uri)
        {
        }

        public function uri(): string
        {
            return $this->uri;
        }
    }

    function resolve_support_fixtures(): void
    {
        $connectionConfig = [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ];

        $container = bind_test_container([
            'database.default'           => 'mysql',
            'database.connections.mysql' => $connectionConfig,
            'fleetbase.connection.db'    => 'mysql',
        ]);

        $capsule = new Capsule($container);
        $capsule->addConnection($connectionConfig, 'mysql');
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $databaseManager = $capsule->getDatabaseManager();
        $databaseManager->setDefaultConnection('mysql');
        $container->instance('db', $databaseManager);
        Facade::clearResolvedInstance('db');

        $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
        $schema->dropIfExists('resolve_widgets');
        $schema->create('resolve_widgets', function ($table) {
            $table->string('uuid')->primary();
            $table->string('name')->nullable();
        });
        $capsule->getConnection('mysql')->table('resolve_widgets')->insert([
            'uuid' => 'widget-1',
            'name' => 'Resolved Widget',
        ]);

        app()->instance('request', resolve_support_request('/v1/resolve-widgets', 'v1/resolve-widgets'));
    }

    function resolve_support_request(string $path, string $routeUri, string $method = 'GET'): Request
    {
        $request = Request::create($path, $method);
        $request->setRouteResolver(fn () => new ResolveSupportRoute($routeUri));

        return $request;
    }

    afterEach(function () {
        unset($_SERVER['REQUEST_METHOD']);
        EloquentModel::clearBootedModels();
        Facade::clearResolvedInstances();
    });

    test('resolve instantiates http resources requests and filters for model contracts', function () {
        resolve_support_fixtures();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $model = new ResolveWidget(['uuid' => 'widget-1', 'name' => 'Resolved Widget']);

        $resource            = Resolve::httpResourceForModel($model, '\Fleetbase\Tests\ResolveFixtures');
        $classStringResource = Resolve::httpResourceForModel(ResolveWidget::class, '\Fleetbase\Tests\ResolveFixtures');
        $request             = Resolve::httpRequestForModel(ResolveWidget::class, '\Fleetbase\Tests\ResolveFixtures');
        $filter              = Resolve::httpFilterForModel($model, Request::create('/v1/resolve-widgets', 'GET', ['name' => 'Resolved Widget']));

        expect($resource)->toBeInstanceOf(ResolveWidgetResource::class)
            ->and($resource->resource)->toBe($model)
            ->and($classStringResource)->toBeInstanceOf(ResolveWidgetResource::class)
            ->and($classStringResource->resource)->toBeInstanceOf(ResolveWidget::class)
            ->and($request)->toBeInstanceOf(CreateResolveWidgetRequest::class)
            ->and($filter)->toBeInstanceOf(ResolveWidgetFilter::class);
    });

    test('resolve falls back for missing request and resource contracts and rejects invalid models', function () {
        resolve_support_fixtures();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $missing = new ResolveMissingContract();

        expect(Resolve::httpResourceForModel($missing, '\Fleetbase\Tests\ResolveFixtures'))->toBeInstanceOf(FleetbaseResource::class)
            ->and(Resolve::httpRequestForModel($missing, '\Fleetbase\Tests\ResolveFixtures'))->toBeInstanceOf(FleetbaseRequest::class)
            ->and(Resolve::httpFilterForModel($missing, Request::create('/v1/missing')))->toBeNull();

        expect(fn () => Resolve::httpResourceForModel(new stdClass()))->toThrow(Exception::class, 'Invalid model to resolve resource for!');
        expect(fn () => Resolve::httpRequestForModel('not-a-model'))->toThrow(Exception::class, 'Invalid model to resolve request for!');
    });

    test('resolve creates resources for morph references and returns null for empty or missing targets', function () {
        resolve_support_fixtures();
        app()->bind('resolve.container-widget', fn () => new ResolveWidget(['uuid' => 'container-widget']));

        $resource     = Resolve::resourceForMorph(ResolveWidget::class, 'widget-1', ResolveWidgetResource::class);
        $autoResource = Resolve::resourceForMorph(ResolveWidget::class, 'widget-1');

        expect($resource)->toBeInstanceOf(ResolveWidgetResource::class)
            ->and($resource->resource)->toBeInstanceOf(ResolveWidget::class)
            ->and($resource->resource->uuid)->toBe('widget-1')
            ->and($autoResource)->toBeInstanceOf(FleetbaseResource::class)
            ->and($autoResource->resource)->toBeInstanceOf(ResolveWidget::class)
            ->and(Resolve::resourceForMorph('', 'widget-1'))->toBeNull()
            ->and(Resolve::resourceForMorph(ResolveWidget::class, 'missing'))->toBeNull()
            ->and(Resolve::instance([]))->toBeNull()
            ->and(Resolve::instance(new ResolveWidget(['uuid' => 'widget-2'])))->toBeInstanceOf(ResolveWidget::class)
            ->and(Resolve::instance('resolve.container-widget'))->toBeInstanceOf(ResolveWidget::class)
            ->and(Resolve::instance('resolve.container-widget')->uuid)->toBe('container-widget');
    });
}
