<?php

use Fleetbase\Http\Controllers\Internal\v1\ReportController;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class ReportControllerValidatorFactory
{
    public function make(array $data, array $rules): ReportControllerValidatorResult
    {
        return new ReportControllerValidatorResult($data, $rules);
    }
}

class ReportControllerValidatorResult
{
    private array $errors = [];

    public function __construct(private array $data, private array $rules)
    {
        foreach ($this->rules as $field => $ruleSet) {
            $this->validateField($field, explode('|', $ruleSet));
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): object
    {
        return new class($this->errors) {
            public function __construct(private array $errors)
            {
            }

            public function all(): array
            {
                return $this->errors;
            }
        };
    }

    private function validateField(string $field, array $rules): void
    {
        $exists = data_get($this->data, $field) !== null;
        $value  = data_get($this->data, $field);

        if (!$exists && in_array('sometimes', $rules, true)) {
            return;
        }

        foreach ($rules as $rule) {
            if ($rule === 'nullable' && ($value === null || $value === '')) {
                return;
            }
            if ($rule === 'required' && (!$exists || $value === '')) {
                $this->errors[] = "The {$field} field is required.";
            } elseif ($exists && $rule === 'array' && !is_array($value)) {
                $this->errors[] = "The {$field} field must be an array.";
            } elseif ($exists && $rule === 'string' && !is_string($value)) {
                $this->errors[] = "The {$field} field must be a string.";
            } elseif ($exists && $rule === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                $this->errors[] = "The {$field} field must be an integer.";
            } elseif ($exists && str_starts_with($rule, 'min:')) {
                $minimum = (int) substr($rule, 4);
                if ((is_array($value) && count($value) < $minimum) || (is_numeric($value) && (int) $value < $minimum)) {
                    $this->errors[] = "The {$field} field must be at least {$minimum}.";
                }
            } elseif ($exists && str_starts_with($rule, 'max:')) {
                $maximum = (int) substr($rule, 4);
                if ((is_string($value) && strlen($value) > $maximum) || (is_numeric($value) && (int) $value > $maximum)) {
                    $this->errors[] = "The {$field} field must not be greater than {$maximum}.";
                }
            } elseif ($exists && str_starts_with($rule, 'in:')) {
                $allowed = explode(',', substr($rule, 3));
                if (!in_array($value, $allowed, true)) {
                    $this->errors[] = "The selected {$field} is invalid.";
                }
            }
        }
    }
}

function report_controller_registry(): ReportSchemaRegistry
{
    $registry = new ReportSchemaRegistry();
    $registry->setCacheEnabled(false);

    $customer = Relationship::belongsTo('customer', 'customers')
        ->label('Customer')
        ->localKey('customer_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('name')->label('Name'),
        ]);

    $registry->registerTable(
        Table::make('orders')
            ->label('Orders')
            ->description('Order report table')
            ->extension('fleetops')
            ->category('operations')
            ->maxRows(2500)
            ->columns([
                Column::make('uuid')->label('UUID')->hidden(),
                Column::make('public_id')->label('Public ID')->sortable(),
                Column::make('status')->label('Status')->filterable(),
                Column::make('created_at', 'datetime')->label('Created At'),
                Column::computed('age_days', 'DATEDIFF(NOW(), created_at)', 'integer'),
            ])
            ->relationships([$customer])
            ->excludeColumns(['uuid'])
    );

    $registry->registerTable(
        Table::make('customers')
            ->label('Customers')
            ->extension('fleetops')
            ->category('crm')
            ->columns([
                Column::make('uuid'),
                Column::make('name'),
            ])
    );

    return $registry;
}

function report_controller_bind(): ReportSchemaRegistry
{
    EloquentModel::clearBootedModels();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $registry  = report_controller_registry();
    $container = bind_test_container([
        'app.env'               => 'testing',
        'reports.query_timeout' => 30,
    ]);

    $container->instance(ReportSchemaRegistry::class, $registry);
    $container->instance('validator', new ReportControllerValidatorFactory());
    $container->instance('request', Request::create('/int/v1/reports', 'GET', [], [], [], [
        'HTTP_X_REQUEST_ID' => 'request-1',
    ]));
    Facade::clearResolvedInstances();

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-1',
    ]);

    return $registry;
}

function report_controller_query_config(array $overrides = []): array
{
    return array_replace_recursive([
        'table' => [
            'name' => 'orders',
        ],
        'columns' => [
            ['name' => 'status', 'alias' => 'order_status'],
            ['name' => 'created_at'],
        ],
        'conditions' => [],
        'joins'      => [],
        'groupBy'    => [],
        'sortBy'     => [],
        'limit'      => 100,
    ], $overrides);
}

function report_controller_database(): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = app();
    $capsule   = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();

    config([
        'database.default'             => 'testing',
        'database.connections.testing' => $connection,
        'fleetbase.connection.db'      => 'testing',
    ]);

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('reports', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable()->index();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->text('query_config')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    $capsule->getConnection('testing')->table('reports')->insert([
        [
            'uuid'         => 'report-current',
            'public_id'    => 'report_1',
            'company_uuid' => 'company-1',
            'query_config' => json_encode(report_controller_query_config()),
        ],
        [
            'uuid'         => 'report-other',
            'public_id'    => 'report_2',
            'company_uuid' => 'company-2',
            'query_config' => json_encode(report_controller_query_config()),
        ],
    ]);

    return $capsule;
}

function report_controller_payload(JsonResponse $response): array
{
    return $response->getData(true);
}

afterEach(function () {
    session()->flush();
    config([
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
        'reports.query_timeout'   => null,
    ]);
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('report controller exposes table schema column and relationship metadata contracts', function () {
    report_controller_bind();
    $controller = new ReportController();

    $tables        = report_controller_payload($controller->getTables(Request::create('/int/v1/reports/tables', 'GET', ['extension' => 'fleetops', 'category' => 'operations'])));
    $schema        = report_controller_payload($controller->getTableSchema(Request::create('/int/v1/reports/tables/orders/schema'), 'orders'));
    $columns       = report_controller_payload($controller->getTableColumns(Request::create('/int/v1/reports/tables/orders/columns'), 'orders'));
    $relationships = report_controller_payload($controller->getTableRelationships(Request::create('/int/v1/reports/tables/orders/relationships'), 'orders'));

    expect($tables['success'])->toBeTrue()
        ->and($tables['tables'])->toHaveCount(1)
        ->and($tables['tables'][0]['name'])->toBe('orders')
        ->and($tables['meta']['total_tables'])->toBe(1)
        ->and($schema['success'])->toBeTrue()
        ->and($schema['schema']['table']['name'])->toBe('orders')
        ->and($schema['meta']['columns_count'])->toBeGreaterThan(0)
        ->and($schema['meta']['relationships_count'])->toBe(1)
        ->and(array_column($columns['columns'], 'name'))->toContain('status', 'created_at', 'age_days')
        ->and(array_column($columns['columns'], 'name'))->not->toContain('uuid', 'public_id')
        ->and($columns['meta']['total_columns'])->toBe(count($columns['columns']))
        ->and($relationships['relationships'][0]['name'])->toBe('customer')
        ->and($relationships['meta']['total_relationships'])->toBe(1);
});

test('report controller validates query configuration and reports validation errors', function () {
    report_controller_bind();
    $controller = new ReportController();

    $missing = report_controller_payload($controller->validateQuery(Request::create('/int/v1/reports/validate-query', 'POST')));
    $invalid = report_controller_payload($controller->validateQuery(Request::create('/int/v1/reports/validate-query', 'POST', [
        'query_config' => report_controller_query_config([
            'table' => ['name' => 'missing_table'],
        ]),
    ])));
    $valid = report_controller_payload($controller->validateQuery(Request::create('/int/v1/reports/validate-query', 'POST', [
        'query_config' => report_controller_query_config(),
    ])));

    expect($missing['valid'])->toBeFalse()
        ->and($missing['errors'])->toBe(['Query configuration is required'])
        ->and($invalid['success'])->toBeFalse()
        ->and($invalid['error']['code'])->toBe('VALIDATION_FAILED')
        ->and($invalid['error']['validation_errors'])->toContain("Table 'missing_table' is not available for reporting")
        ->and($valid['valid'])->toBeTrue()
        ->and($valid['message'])->toBe('Query configuration is valid')
        ->and($valid['summary']['total_columns'])->toBe(2)
        ->and($valid['summary']['has_limit'])->toBeTrue();
});

test('report controller validates computed column expression boundaries', function () {
    report_controller_bind();
    $controller = new ReportController();

    $missingExpression = report_controller_payload($controller->validateComputedColumn(Request::create('/int/v1/reports/validate-computed-column', 'POST', [
        'table_name' => 'orders',
    ])));
    $missingTable = report_controller_payload($controller->validateComputedColumn(Request::create('/int/v1/reports/validate-computed-column', 'POST', [
        'expression' => 'DATEDIFF(NOW(), created_at)',
    ])));
    $valid = report_controller_payload($controller->validateComputedColumn(Request::create('/int/v1/reports/validate-computed-column', 'POST', [
        'expression' => 'DATEDIFF(NOW(), created_at)',
        'table_name' => 'orders',
    ])));
    $forbidden = report_controller_payload($controller->validateComputedColumn(Request::create('/int/v1/reports/validate-computed-column', 'POST', [
        'expression' => 'DROP TABLE orders',
        'table_name' => 'orders',
    ])));

    expect($missingExpression['valid'])->toBeFalse()
        ->and($missingExpression['errors'])->toBe(['Expression is required'])
        ->and($missingTable['valid'])->toBeFalse()
        ->and($missingTable['errors'])->toBe(['Table name is required'])
        ->and($valid['valid'])->toBeTrue()
        ->and($valid['message'])->toBe('Expression is valid')
        ->and($forbidden['valid'])->toBeFalse()
        ->and($forbidden['errors'][0])->toContain('forbidden SQL keyword: DROP');
});

test('report controller exposes query analysis and export format contracts without executing unsafe formats', function () {
    report_controller_bind();
    $controller = new ReportController();

    $missing  = report_controller_payload($controller->analyzeQuery(Request::create('/int/v1/reports/analyze-query', 'POST')));
    $analysis = report_controller_payload($controller->analyzeQuery(Request::create('/int/v1/reports/analyze-query', 'POST', [
        'query_config' => report_controller_query_config([
            'computed_columns' => [
                [
                    'name'       => 'age_days',
                    'expression' => 'DATEDIFF(NOW(), created_at)',
                    'type'       => 'integer',
                ],
            ],
        ]),
    ])));
    $formats       = report_controller_payload($controller->getExportFormats(Request::create('/int/v1/reports/export-formats')));
    $invalidFormat = report_controller_payload($controller->exportQuery(Request::create('/int/v1/reports/export-query', 'POST', [
        'query_config' => report_controller_query_config(),
        'format'       => 'yaml',
    ])));

    expect($missing['success'])->toBeFalse()
        ->and($missing['error']['code'])->toBe('INVALID_CONFIGURATION')
        ->and($analysis['success'])->toBeTrue()
        ->and($analysis['analysis']['table_name'])->toBe('orders')
        ->and($analysis['analysis']['selected_columns_count'])->toBe(3)
        ->and($analysis['validation']['valid'])->toBeTrue()
        ->and($formats['success'])->toBeTrue()
        ->and(array_keys($formats['formats']))->toBe(['csv', 'excel', 'json', 'pdf', 'xml'])
        ->and($formats['meta']['total_formats'])->toBe(5)
        ->and($invalidFormat['success'])->toBeFalse()
        ->and($invalidFormat['error']['code'])->toBe('INVALID_CONFIGURATION')
        ->and($invalidFormat['error']['allowed_formats'])->toBe(['csv', 'excel', 'json', 'pdf', 'xml']);
});

test('report controller rejects missing and unsafe export download filenames before file access', function () {
    report_controller_bind();
    $controller = new ReportController();

    $missing   = $controller->download(Request::create('/int/v1/reports/download/missing.csv'), 'missing.csv');
    $traversal = $controller->download(Request::create('/int/v1/reports/download/../secret.csv'), '../secret.csv');
    $nested    = $controller->download(Request::create('/int/v1/reports/download/nested/report.csv'), 'nested/report.csv');

    expect($missing->getStatusCode())->toBe(404)
        ->and(report_controller_payload($missing)['error']['code'])->toBe('FILE_NOT_FOUND')
        ->and($traversal->getStatusCode())->toBe(400)
        ->and(report_controller_payload($traversal)['error']['code'])->toBe('INVALID_FILENAME')
        ->and($nested->getStatusCode())->toBe(400)
        ->and(report_controller_payload($nested)['error']['code'])->toBe('INVALID_FILENAME');
});

test('report controller report query scopes custom actions to the active company', function () {
    report_controller_bind();
    report_controller_database();

    $controller = new ReportController();
    $method     = new ReflectionMethod(ReportController::class, 'reportQuery');
    $method->setAccessible(true);

    $current = $method->invoke($controller, 'report-current')->first();
    $other   = $method->invoke($controller, 'report-other')->first();

    expect($current)->not->toBeNull()
        ->and($current->uuid)->toBe('report-current')
        ->and($current->company_uuid)->toBe('company-1')
        ->and($other)->toBeNull();
});
