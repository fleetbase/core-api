<?php

use Fleetbase\Http\Controllers\Internal\v1\ReportController;
use Fleetbase\Support\Reporting\ReportQueryValidator;
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

class ReportControllerThrowingRegistry extends ReportSchemaRegistry
{
    public function getAvailableTables(string $extension = 'core', ?string $category = null): array
    {
        throw new RuntimeException('Reporting table not found');
    }

    public function getTableColumns(string $tableName): array
    {
        throw new RuntimeException("Reporting table {$tableName} not found");
    }

    public function getTableRelationships(string $tableName): array
    {
        throw new RuntimeException("Reporting table {$tableName} not found");
    }

    public function getTableSchema(string $tableName): array
    {
        throw new RuntimeException("Reporting table {$tableName} not found");
    }
}

class ReportControllerThrowingQueryValidator extends ReportQueryValidator
{
    public function validate(array $queryConfig): array
    {
        throw new RuntimeException('validator unavailable');
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
        $table->integer('execution_count')->nullable();
        $table->float('average_execution_time')->nullable();
        $table->integer('last_result_count')->nullable();
        $table->timestamp('last_executed_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
    $schema->create('report_executions', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('report_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->float('execution_time')->nullable();
        $table->integer('result_count')->nullable();
        $table->text('query_config')->nullable();
        $table->string('status')->nullable();
        $table->text('error_message')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
    });
    $schema->create('report_audit_logs', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('report_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('action')->nullable();
        $table->float('execution_time')->nullable();
        $table->integer('result_count')->nullable();
        $table->text('error_message')->nullable();
        $table->text('query_config')->nullable();
        $table->string('ip_address')->nullable();
        $table->string('user_agent')->nullable();
        $table->text('metadata')->nullable();
        $table->timestamps();
    });

    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('public_id')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('status')->nullable();
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

    $capsule->getConnection('testing')->table('orders')->insert([
        [
            'uuid'          => 'order-current-1',
            'company_uuid'  => 'company-1',
            'public_id'     => 'order_1',
            'customer_uuid' => 'customer-1',
            'status'        => 'created',
            'created_at'    => '2026-01-01 10:00:00',
            'updated_at'    => '2026-01-01 10:00:00',
        ],
        [
            'uuid'          => 'order-current-2',
            'company_uuid'  => 'company-1',
            'public_id'     => 'order_2',
            'customer_uuid' => 'customer-2',
            'status'        => 'dispatched',
            'created_at'    => '2026-01-02 10:00:00',
            'updated_at'    => '2026-01-02 10:00:00',
        ],
        [
            'uuid'          => 'order-other',
            'company_uuid'  => 'company-2',
            'public_id'     => 'order_3',
            'customer_uuid' => 'customer-3',
            'status'        => 'created',
            'created_at'    => '2026-01-03 10:00:00',
            'updated_at'    => '2026-01-03 10:00:00',
        ],
    ]);

    return $capsule;
}

function report_controller_payload(JsonResponse $response): array
{
    return $response->getData(true);
}

function report_controller_use_query_validator(ReportController $controller, ReportQueryValidator $validator): void
{
    $property = new ReflectionProperty(ReportController::class, 'queryValidator');
    $property->setAccessible(true);
    $property->setValue($controller, $validator);
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

test('report controller returns stable metadata errors for unavailable reporting tables', function () {
    report_controller_bind();
    app()->instance(ReportSchemaRegistry::class, new ReportControllerThrowingRegistry());
    $controller = new ReportController();

    $tables        = $controller->getTables(Request::create('/int/v1/reports/tables', 'GET'));
    $schema        = $controller->getTableSchema(Request::create('/int/v1/reports/tables/missing/schema'), 'missing');
    $columns       = $controller->getTableColumns(Request::create('/int/v1/reports/tables/missing/columns'), 'missing');
    $relationships = $controller->getTableRelationships(Request::create('/int/v1/reports/tables/missing/relationships'), 'missing');

    expect($tables->getStatusCode())->toBe(500)
        ->and(report_controller_payload($tables)['success'])->toBeFalse()
        ->and(report_controller_payload($tables)['error']['code'])->toBe('TABLE_NOT_FOUND')
        ->and($schema->getStatusCode())->toBe(404)
        ->and(report_controller_payload($schema)['success'])->toBeFalse()
        ->and(report_controller_payload($schema)['error']['code'])->toBe('TABLE_NOT_FOUND')
        ->and($columns->getStatusCode())->toBe(404)
        ->and(report_controller_payload($columns)['success'])->toBeFalse()
        ->and(report_controller_payload($columns)['error']['code'])->toBe('TABLE_NOT_FOUND')
        ->and($relationships->getStatusCode())->toBe(404)
        ->and(report_controller_payload($relationships)['success'])->toBeFalse()
        ->and(report_controller_payload($relationships)['error']['code'])->toBe('TABLE_NOT_FOUND');
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

test('report controller wraps validator exceptions for validation and direct query actions', function () {
    $registry = report_controller_bind();

    $controller = new ReportController();
    report_controller_use_query_validator($controller, new ReportControllerThrowingQueryValidator($registry));

    $validate = $controller->validateQuery(Request::create('/int/v1/reports/validate-query', 'POST', [
        'query_config' => report_controller_query_config(),
    ]));
    $execute = $controller->executeQuery(Request::create('/int/v1/reports/execute-query', 'POST', [
        'query_config' => report_controller_query_config(),
    ]));
    $export = $controller->exportQuery(Request::create('/int/v1/reports/export-query', 'POST', [
        'query_config' => report_controller_query_config(),
        'format'       => 'json',
    ]));
    $analysis = $controller->analyzeQuery(Request::create('/int/v1/reports/analyze-query', 'POST', [
        'query_config' => report_controller_query_config(),
    ]));

    expect($validate->getStatusCode())->toBe(500)
        ->and(report_controller_payload($validate)['success'])->toBeFalse()
        ->and(report_controller_payload($validate)['error']['code'])->toBe('QUERY_EXECUTION_FAILED')
        ->and(report_controller_payload($validate)['meta']['company_id'])->toBe('company-1')
        ->and($execute->getStatusCode())->toBe(500)
        ->and(report_controller_payload($execute)['success'])->toBeFalse()
        ->and(report_controller_payload($execute)['error']['code'])->toBe('QUERY_EXECUTION_FAILED')
        ->and($export->getStatusCode())->toBe(500)
        ->and(report_controller_payload($export)['success'])->toBeFalse()
        ->and(report_controller_payload($export)['error']['code'])->toBe('EXPORT_FAILED')
        ->and(report_controller_payload($export)['error']['format'])->toBe('json')
        ->and($analysis->getStatusCode())->toBe(500)
        ->and(report_controller_payload($analysis)['success'])->toBeFalse()
        ->and(report_controller_payload($analysis)['error']['code'])->toBe('QUERY_EXECUTION_FAILED')
        ->and(report_controller_payload($analysis)['meta']['request_id'])->toBe('request-1');
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

    $missing                = report_controller_payload($controller->analyzeQuery(Request::create('/int/v1/reports/analyze-query', 'POST')));
    $missingExecutionConfig = report_controller_payload($controller->executeQuery(Request::create('/int/v1/reports/execute-query', 'POST')));
    $missingExportConfig    = report_controller_payload($controller->exportQuery(Request::create('/int/v1/reports/export-query', 'POST')));
    $analysis               = report_controller_payload($controller->analyzeQuery(Request::create('/int/v1/reports/analyze-query', 'POST', [
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
        ->and($missingExecutionConfig['success'])->toBeFalse()
        ->and($missingExecutionConfig['error']['message'])->toBe('Query configuration is required')
        ->and($missingExportConfig['success'])->toBeFalse()
        ->and($missingExportConfig['error']['message'])->toBe('Query configuration is required')
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

test('report controller executes direct queries with active company scoping', function () {
    report_controller_bind();
    report_controller_database();
    $controller = new ReportController();

    $response = $controller->executeQuery(Request::create('/int/v1/reports/execute-query', 'POST', [
        'query_config' => report_controller_query_config([
            'columns' => [
                ['name' => 'status'],
                ['name' => 'created_at'],
            ],
            'sortBy' => [
                [
                    'column'    => ['name' => 'status'],
                    'direction' => ['value' => 'asc'],
                ],
            ],
            'limit' => 10,
        ]),
    ]));
    $payload = report_controller_payload($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['data'])->toHaveCount(2)
        ->and(array_column($payload['data'], 'order_status'))->toBe(['created', 'dispatched'])
        ->and(array_column($payload['data'], 'created_at'))->toBe(['2026-01-01 10:00:00', '2026-01-02 10:00:00'])
        ->and($payload['meta']['total_rows'])->toBe(2)
        ->and($payload['meta']['table_name'])->toBe('orders')
        ->and($payload['meta']['query_bindings'])->toBe(['company-1'])
        ->and($payload['meta']['query_sql'])->toContain('company_uuid');
});

test('report controller reports validation and timeout failures while executing saved or direct reports', function () {
    report_controller_bind();
    $capsule = report_controller_database();
    $capsule->getConnection('testing')->table('reports')->insert([
        'uuid'         => 'report-invalid-config',
        'public_id'    => 'report_invalid',
        'company_uuid' => 'company-1',
        'query_config' => json_encode(report_controller_query_config([
            'table' => ['name' => 'missing_table'],
        ])),
    ]);

    $controller = new ReportController();

    $savedValidation  = $controller->execute(Request::create('/int/v1/reports/report-invalid-config/execute'), 'report-invalid-config');
    $directValidation = $controller->executeQuery(Request::create('/int/v1/reports/execute-query', 'POST', [
        'query_config' => report_controller_query_config([
            'table' => ['name' => 'missing_table'],
        ]),
    ]));
    $exportValidation = $controller->exportQuery(Request::create('/int/v1/reports/export-query', 'POST', [
        'query_config' => report_controller_query_config([
            'table' => ['name' => 'missing_table'],
        ]),
        'format' => 'json',
    ]));

    config(['reports.query_timeout' => 0]);

    $timeout = $controller->executeQuery(Request::create('/int/v1/reports/execute-query', 'POST', [
        'query_config' => report_controller_query_config([
            'columns' => [
                ['name' => 'status'],
            ],
        ]),
    ]));

    expect($savedValidation->getStatusCode())->toBe(400)
        ->and(report_controller_payload($savedValidation)['success'])->toBeFalse()
        ->and(report_controller_payload($savedValidation)['error']['code'])->toBe('VALIDATION_FAILED')
        ->and(report_controller_payload($savedValidation)['error']['validation_errors'])->toContain("Table 'missing_table' is not available for reporting")
        ->and($directValidation->getStatusCode())->toBe(400)
        ->and(report_controller_payload($directValidation)['success'])->toBeFalse()
        ->and(report_controller_payload($directValidation)['error']['code'])->toBe('VALIDATION_FAILED')
        ->and(report_controller_payload($directValidation)['error'])->not->toHaveKey('context')
        ->and(report_controller_payload($directValidation)['error']['validation_errors'])->toContain("Table 'missing_table' is not available for reporting")
        ->and($exportValidation->getStatusCode())->toBe(400)
        ->and(report_controller_payload($exportValidation)['success'])->toBeFalse()
        ->and(report_controller_payload($exportValidation)['error']['code'])->toBe('VALIDATION_FAILED')
        ->and(report_controller_payload($exportValidation)['error'])->not->toHaveKey('context')
        ->and(report_controller_payload($exportValidation)['error']['validation_errors'])->toContain("Table 'missing_table' is not available for reporting")
        ->and($timeout->getStatusCode())->toBe(408)
        ->and(report_controller_payload($timeout)['success'])->toBeFalse()
        ->and(report_controller_payload($timeout)['error']['code'])->toBe('TIMEOUT')
        ->and(report_controller_payload($timeout)['error']['message'])->toBe('Query execution timed out');
});

test('report controller wraps saved report execution failures after validation passes', function () {
    report_controller_bind();
    $capsule = report_controller_database();
    $capsule->getConnection('testing')->getSchemaBuilder()->drop('orders');

    $controller = new ReportController();
    $response   = $controller->execute(Request::create('/int/v1/reports/report-current/execute'), 'report-current');
    $payload    = report_controller_payload($response);

    expect($response->getStatusCode())->toBe(500)
        ->and($payload['success'])->toBeFalse()
        ->and($payload['error']['code'])->toBe('CONNECTION_ERROR')
        ->and($payload['error']['message'])->toBe('Could not connect to the database.')
        ->and($payload['error'])->not->toHaveKey('context')
        ->and($payload['error']['details']['database'])->toBe('testing')
        ->and($payload['error']['details']['type'])->toBe(Exception::class)
        ->and($payload['meta']['company_id'])->toBe('company-1');
});

test('report controller executes saved reports with active company scoping', function () {
    report_controller_bind();
    report_controller_database();
    $controller = new ReportController();

    $response = $controller->execute(Request::create('/int/v1/reports/report-current/execute'), 'report-current');
    $payload  = report_controller_payload($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['results'])->toHaveCount(2)
        ->and(array_column($payload['results'], 'order_status'))->toBe(['created', 'dispatched'])
        ->and($payload['meta']['total_rows'])->toBe(2)
        ->and($payload['meta']['query_sql'])->toContain('company_uuid');
});

test('report controller exports direct query results and exposes download metadata', function () {
    report_controller_bind();
    report_controller_database();
    $controller = new ReportController();

    $response = $controller->exportQuery(Request::create('/int/v1/reports/export-query', 'POST', [
        'query_config' => report_controller_query_config([
            'columns' => [
                ['name' => 'status'],
                ['name' => 'created_at'],
            ],
        ]),
        'format'  => 'json',
        'options' => ['compact' => true],
    ]));
    $payload = report_controller_payload($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['format'])->toBe('json')
        ->and($payload['filename'])->toStartWith('report-orders-')
        ->and($payload['filename'])->toEndWith('.json')
        ->and($payload['rows'])->toBe(2)
        ->and($payload['size'])->toBeGreaterThan(0)
        ->and($payload['download_url'])->toBe('http://fleetbase.test/reports/download/' . rawurlencode($payload['filename']))
        ->and(file_exists($payload['filepath']))->toBeTrue();

    $export = json_decode(file_get_contents($payload['filepath']), true);

    expect($export['metadata']['total_rows'])->toBe(2)
        ->and($export['metadata']['format'])->toBe('json')
        ->and(array_column($export['data'], 'order_status'))->toBe(['created', 'dispatched']);
});

test('report controller validates and exports saved reports inside the active company', function () {
    report_controller_bind();
    report_controller_database();
    $controller = new ReportController();

    $invalidFormat = $controller->export(Request::create('/int/v1/reports/report-current/export', 'POST', [
        'format' => 'yaml',
    ]), 'report-current');

    $export = $controller->export(Request::create('/int/v1/reports/report-current/export', 'POST', [
        'format'  => 'json',
        'options' => ['compact' => true],
    ]), 'report-current');
    $payload = report_controller_payload($export);

    expect($invalidFormat->getStatusCode())->toBe(400)
        ->and(report_controller_payload($invalidFormat)['success'])->toBeFalse()
        ->and(report_controller_payload($invalidFormat)['error']['code'])->toBe('INVALID_CONFIGURATION')
        ->and(report_controller_payload($invalidFormat)['error']['allowed_formats'])->toBe(['csv', 'excel', 'json', 'pdf', 'xml'])
        ->and($export->getStatusCode())->toBe(200)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['format'])->toBe('json')
        ->and($payload['rows'])->toBe(2)
        ->and($payload['download_url'])->toBe('http://fleetbase.test/reports/download/' . rawurlencode($payload['filename']));
});

test('report controller reports saved report export execution failures after format validation', function () {
    report_controller_bind();
    $capsule = report_controller_database();
    $capsule->getConnection('testing')->table('reports')->insert([
        'uuid'         => 'report-export-invalid-config',
        'public_id'    => 'report_export_invalid',
        'company_uuid' => 'company-1',
        'query_config' => json_encode(report_controller_query_config([
            'table' => ['name' => 'missing_table'],
        ])),
    ]);

    $controller = new ReportController();
    $response   = $controller->export(Request::create('/int/v1/reports/report-export-invalid-config/export', 'POST', [
        'format' => 'json',
    ]), 'report-export-invalid-config');
    $payload = report_controller_payload($response);

    expect($response->getStatusCode())->toBe(500)
        ->and($payload['success'])->toBeFalse()
        ->and($payload['error']['code'])->toBe('EXPORT_FAILED')
        ->and($payload['error']['format'])->toBe('json')
        ->and($payload['error']['message'])->toBe('Export to json format failed');
});

test('report controller returns handled errors for report execution and export outside the active company', function () {
    report_controller_bind();
    report_controller_database();
    $controller = new ReportController();

    $executeMissing = $controller->execute(Request::create('/int/v1/reports/report-other/execute'), 'report-other');
    $exportMissing  = $controller->export(Request::create('/int/v1/reports/report-other/export', 'POST', [
        'format' => 'csv',
    ]), 'report-other');

    expect($executeMissing->getStatusCode())->toBe(500)
        ->and(report_controller_payload($executeMissing)['success'])->toBeFalse()
        ->and(report_controller_payload($executeMissing)['error']['code'])->toBe('QUERY_EXECUTION_FAILED')
        ->and(report_controller_payload($executeMissing)['error']['message'])->toBe('The query could not be executed. Please try simplifying your request.')
        ->and(report_controller_payload($executeMissing)['meta']['company_id'])->toBe('company-1')
        ->and($exportMissing->getStatusCode())->toBe(500)
        ->and(report_controller_payload($exportMissing)['success'])->toBeFalse()
        ->and(report_controller_payload($exportMissing)['error']['code'])->toBe('EXPORT_FAILED')
        ->and(report_controller_payload($exportMissing)['error']['message'])->toBe('Export to csv format failed')
        ->and(report_controller_payload($exportMissing)['error']['format'])->toBe('csv');
});

test('report controller recommends query changes for complex broad or warning-heavy analysis', function () {
    report_controller_bind();
    $controller = new ReportController();
    $method     = new ReflectionMethod(ReportController::class, 'getQueryRecommendations');
    $method->setAccessible(true);

    $recommendations = $method->invoke($controller, [
        'complexity'              => 'complex',
        'joins_count'             => 4,
        'selected_columns_count'  => 21,
    ], [
        'warnings' => ['No limit specified'],
    ]);

    expect($recommendations)->toHaveCount(4)
        ->and(array_column($recommendations, 'type'))->toBe([
            'performance',
            'performance',
            'performance',
            'validation',
        ])
        ->and($recommendations[0]['priority'])->toBe('high')
        ->and($recommendations[1]['message'])->toBe('Multiple joins may impact performance')
        ->and($recommendations[2]['message'])->toBe('Selecting many columns may slow down the query')
        ->and($recommendations[3]['suggestions'])->toBe(['No limit specified']);
});

test('report controller wraps export format and computed column registry failures', function () {
    report_controller_bind();
    $controller = new ReportController();

    app()->bind(ReportSchemaRegistry::class, function () {
        throw new RuntimeException('registry unavailable');
    });

    $formats  = $controller->getExportFormats(Request::create('/int/v1/reports/export-formats'));
    $computed = $controller->validateComputedColumn(Request::create('/int/v1/reports/validate-computed-column', 'POST', [
        'expression' => 'status',
        'table_name' => 'orders',
    ]));

    expect($formats->getStatusCode())->toBe(500)
        ->and(report_controller_payload($formats)['success'])->toBeFalse()
        ->and(report_controller_payload($formats)['error']['code'])->toBe('QUERY_EXECUTION_FAILED')
        ->and(report_controller_payload($formats)['error']['message'])->toBe('The query could not be executed. Please try simplifying your request.')
        ->and($computed->getStatusCode())->toBe(500)
        ->and(report_controller_payload($computed)['success'])->toBeFalse()
        ->and(report_controller_payload($computed)['error']['code'])->toBe('QUERY_EXECUTION_FAILED')
        ->and(report_controller_payload($computed)['error']['message'])->toBe('The query could not be executed. Please try simplifying your request.');
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

test('report controller downloads existing export files with stable cache and content headers', function () {
    report_controller_bind();
    $controller = new ReportController();

    $exportDir = storage_path('app/exports');
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }

    $filename = 'report-orders-download.csv';
    file_put_contents($exportDir . DIRECTORY_SEPARATOR . $filename, "Order,Status\norder_1,created\n");

    $response = $controller->download(Request::create('/int/v1/reports/download/' . $filename), $filename);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('content-type'))->toBe('text/csv')
        ->and($response->headers->getCacheControlDirective('no-cache'))->toBeTrue()
        ->and($response->headers->getCacheControlDirective('no-store'))->toBeTrue()
        ->and($response->headers->getCacheControlDirective('must-revalidate'))->toBeTrue()
        ->and($response->headers->get('pragma'))->toBe('no-cache')
        ->and($response->headers->get('expires'))->toBe('0')
        ->and($response->headers->get('content-disposition'))->toContain($filename);
});

test('report controller wraps download response failures for existing non-file export paths', function () {
    report_controller_bind();
    $controller = new ReportController();

    $exportDir = storage_path('app/exports');
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }

    $filename = 'report-orders-directory.csv';
    $filepath = $exportDir . DIRECTORY_SEPARATOR . $filename;
    if (is_file($filepath)) {
        unlink($filepath);
    }
    if (!is_dir($filepath)) {
        mkdir($filepath, 0755);
    }

    $response = $controller->download(Request::create('/int/v1/reports/download/' . $filename), $filename);
    $payload  = report_controller_payload($response);

    expect($response->getStatusCode())->toBe(500)
        ->and($payload['success'])->toBeFalse()
        ->and($payload['error']['code'])->toBe('QUERY_EXECUTION_FAILED')
        ->and($payload['error']['message'])->toBe('The query could not be executed. Please try simplifying your request.')
        ->and($payload['meta']['company_id'])->toBe('company-1');

    rmdir($filepath);
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
