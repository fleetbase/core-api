<?php

use Fleetbase\Support\Reporting\ReportQueryValidator;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;

class ReportValidatorTestFactory
{
    public function make(array $data, array $rules): ReportValidatorTestResult
    {
        return new ReportValidatorTestResult($data, $rules);
    }
}

class ReportValidatorTestResult
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
            if ($rule === 'required' && (!$exists || $value === '')) {
                $this->errors[] = "The {$field} field is required.";
            } elseif (str_starts_with($rule, 'required_with:')) {
                $other = substr($rule, strlen('required_with:'));
                if (data_get($this->data, $other) !== null && (!$exists || $value === '')) {
                    $this->errors[] = "The {$field} field is required when {$other} is present.";
                }
            } elseif ($exists && $rule === 'array' && !is_array($value)) {
                $this->errors[] = "The {$field} field must be an array.";
            } elseif ($exists && $rule === 'string' && !is_string($value)) {
                $this->errors[] = "The {$field} field must be a string.";
            } elseif ($exists && $rule === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                $this->errors[] = "The {$field} field must be an integer.";
            } elseif ($exists && str_starts_with($rule, 'in:')) {
                $allowed = explode(',', substr($rule, 3));
                if (!in_array($value, $allowed, true)) {
                    $this->errors[] = "The selected {$field} is invalid.";
                }
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
            }
        }
    }
}

function report_validator_registry_fixture(): ReportSchemaRegistry
{
    $registry = new ReportSchemaRegistry();
    $registry->setCacheEnabled(false);

    $pickup = Relationship::hasAutoJoin('pickup', 'locations')
        ->label('Pickup Location')
        ->localKey('pickup_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('city')->label('City'),
            Column::make('street1')->label('Street 1'),
        ]);

    $payload = Relationship::hasAutoJoin('payload', 'payloads')
        ->label('Order Payload')
        ->localKey('payload_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('description')->label('Description'),
        ])
        ->with([$pickup]);

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
            ->maxRows(2500)
            ->columns([
                Column::make('uuid')->label('UUID')->hidden(),
                Column::make('public_id')->label('Public ID')->sortable(),
                Column::make('tracking_number')->label('Tracking Number')->sortable(),
                Column::make('status')->label('Status')->filterable(),
                Column::make('total', 'decimal')->label('Total')->aggregatable(),
                Column::make('customer_token')->label('Customer Token'),
            ])
            ->relationships([$payload, $customer])
            ->excludeColumns(['uuid'])
    );

    $registry->registerTable(
        Table::make('customers')
            ->label('Customers')
            ->columns([
                Column::make('uuid'),
                Column::make('name'),
            ])
    );

    return $registry;
}

function report_validator_fixture(): ReportQueryValidator
{
    bind_test_container();
    app()->instance('validator', new ReportValidatorTestFactory());

    return new ReportQueryValidator(report_validator_registry_fixture());
}

function valid_report_query_config(array $overrides = []): array
{
    $config = [
        'table' => [
            'name' => 'orders',
        ],
        'columns' => [
            ['name' => 'status', 'alias' => 'order_status'],
            ['name' => 'tracking_number'],
        ],
        'joins'      => [],
        'conditions' => [
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'eq'],
                'value'    => 'dispatched',
            ],
            [
                'logicalOperator' => 'or',
                'conditions'      => [
                    [
                        'field'    => ['name' => 'tracking_number'],
                        'operator' => ['value' => 'starts_with'],
                        'value'    => 'T',
                    ],
                ],
            ],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'status'],
                'aggregateFn' => ['value' => 'count'],
                'aggregateBy' => ['name' => '*'],
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'tracking_number'],
                'direction' => ['value' => 'asc'],
            ],
        ],
        'limit' => 500,
    ];

    foreach ($overrides as $key => $value) {
        $config[$key] = $value;
    }

    return $config;
}

test('report query validator accepts nested report query contracts and summarizes shape', function () {
    $result = report_validator_fixture()->validate(valid_report_query_config());

    expect($result['errors'])->toBe([])
        ->and($result['valid'])->toBeTrue()
        ->and($result['summary']['total_columns'])->toBe(2)
        ->and($result['summary']['total_joins'])->toBe(0)
        ->and($result['summary']['total_conditions'])->toBe(2)
        ->and($result['summary']['has_grouping'])->toBeTrue()
        ->and($result['summary']['has_sorting'])->toBeTrue()
        ->and($result['summary']['estimated_performance'])->toBe('moderate');
});

test('report query validator rejects unavailable tables columns joins and malformed aliases', function () {
    $result = report_validator_fixture()->validate(valid_report_query_config([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'missing_column', 'alias' => '1bad'],
        ],
        'joins' => [
            [
                'key'         => 'missing_relationship',
                'table'       => 'missing_table',
                'type'        => 'outer',
                'local_key'   => 'customer_uuid',
                'foreign_key' => 'uuid',
            ],
        ],
    ]));

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Column 'missing_column' does not exist in table 'orders'")
        ->and($result['errors'])->toContain("Invalid alias format '1bad' for column 'missing_column'")
        ->and($result['errors'])->toContain('Join 0: The selected type is invalid.');

    $missingRelationship = report_validator_fixture()->validate(valid_report_query_config([
        'joins' => [
            [
                'key'         => 'missing_relationship',
                'table'       => 'customers',
                'type'        => 'left',
                'local_key'   => 'customer_uuid',
                'foreign_key' => 'uuid',
            ],
        ],
    ]));

    expect($missingRelationship['valid'])->toBeFalse()
        ->and($missingRelationship['errors'])->toContain("Join relationship 'missing_relationship' is not available for table 'orders'");

    $missingTable = report_validator_fixture()->validate(valid_report_query_config([
        'table' => ['name' => 'missing_table'],
    ]));

    expect($missingTable['valid'])->toBeFalse()
        ->and($missingTable['errors'])->toContain("Table 'missing_table' is not available for reporting");
});

test('report query validator catches condition operator value grouping sorting and limit failures', function () {
    $result = report_validator_fixture()->validate(valid_report_query_config([
        'conditions' => [
            [
                'field'    => ['name' => 'missing_field'],
                'operator' => ['value' => 'between'],
                'value'    => ['only-one'],
            ],
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'sideways'],
                'value'    => '',
            ],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'missing_group'],
                'aggregateFn' => ['value' => 'sum'],
                'aggregateBy' => ['name' => '*'],
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'missing_sort'],
                'direction' => ['value' => 'sideways'],
            ],
        ],
        'limit' => 500,
    ]));

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("conditions[0]: Field 'missing_field' is not available in the query")
        ->and($result['errors'])->toContain("conditions[0]: Value for 'between' operator must be an array with exactly 2 elements")
        ->and($result['errors'])->toContain("conditions[1]: Invalid operator 'sideways'")
        ->and($result['errors'])->toContain("Group By 0: Field 'missing_group' is not available")
        ->and($result['errors'])->toContain("Group By 0: Wildcard '*' can only be used with COUNT function")
        ->and($result['errors'])->toContain('Sort By 0: The selected direction.value is invalid.')
        ->and($result['errors'])->toContain("Sort By 0: Field 'missing_sort' is not available");

    $limitResult = report_validator_fixture()->validate(valid_report_query_config([
        'limit' => 50001,
    ]));

    expect($limitResult['valid'])->toBeFalse()
        ->and($limitResult['errors'])->toContain('The limit field must not be greater than 50000.');
});

test('report query validator emits security and performance warnings for risky but valid queries', function () {
    $manyColumns = array_fill(0, 51, ['name' => 'status']);
    $conditions  = array_map(fn (int $index) => [
        'field'    => ['name' => 'status'],
        'operator' => ['value' => 'eq'],
        'value'    => 'status-' . $index,
    ], range(1, 21));

    $result = report_validator_fixture()->validate(valid_report_query_config([
        'columns' => [
            ...$manyColumns,
            ['name' => 'customer_token'],
        ],
        'conditions' => $conditions,
        'groupBy'    => [],
        'sortBy'     => [],
        'joins'      => [],
        'limit'      => 11000,
    ]));

    expect($result['valid'])->toBeTrue()
        ->and($result['warnings'])->toContain('Selecting many columns (52) may impact performance')
        ->and($result['warnings'])->toContain('Many conditions (21) may impact query performance')
        ->and($result['warnings'])->toContain('Accessing potentially sensitive column: customer_token')
        ->and($result['warnings'])->toContain('Query complexity is high and may result in slow execution')
        ->and($result['warnings'])->toContain('Consider adding conditions on indexed columns for better performance')
        ->and($result['warnings'])->toContain('Large limit (11000) may impact performance')
        ->and($result['summary']['estimated_performance'])->toBe('slow');
});

test('report query validator blocks sql injection patterns anywhere in the query payload', function () {
    $result = report_validator_fixture()->validate(valid_report_query_config([
        'conditions' => [
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'eq'],
                'value'    => "active' UNION SELECT password FROM users --",
            ],
        ],
    ]));

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain('Potential SQL injection detected: value');
});

test('report query validator stops detailed validation when basic structure is invalid', function () {
    $result = report_validator_fixture()->validate([
        'table'   => 'orders',
        'columns' => [],
        'limit'   => 0,
    ]);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain('The table field must be an array.')
        ->and($result['errors'])->toContain('The columns field must be at least 1.')
        ->and($result['errors'])->toContain('The limit field must be at least 1.')
        ->and($result['errors'])->not->toContain("Table 'orders' is not available for reporting")
        ->and($result['summary'])->toMatchArray([
            'complexity'            => 'low',
            'total_columns'         => 0,
            'total_joins'           => 0,
            'total_conditions'      => 0,
            'has_grouping'          => false,
            'has_sorting'           => false,
            'has_limit'             => true,
            'estimated_performance' => 'fast',
        ]);
});

test('report query validator validates joined table selected columns and joined field availability', function () {
    $result = report_validator_fixture()->validate(valid_report_query_config([
        'columns' => [
            ['name' => 'status'],
        ],
        'joins' => [
            [
                'key'             => 'customers',
                'table'           => 'customers',
                'type'            => 'inner',
                'local_key'       => 'customer_uuid',
                'foreign_key'     => 'uuid',
                'selectedColumns' => [
                    ['name' => 'name', 'alias' => 'customer_name'],
                    ['name' => 'missing_customer_column'],
                ],
            ],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'name', 'table' => 'customers'],
                'operator' => ['value' => 'contains'],
                'value'    => 'Acme',
            ],
        ],
        'groupBy' => [],
        'sortBy'  => [],
    ]));

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("Column 'missing_customer_column' does not exist in table 'customers'")
        ->and($result['errors'])->not->toContain("conditions[0]: Field 'name' is not available in the query");
});

test('report query validator reports condition value errors and empty value warnings by operator', function () {
    $result = report_validator_fixture()->validate(valid_report_query_config([
        'conditions' => [
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'in'],
                'value'    => 123,
            ],
            [
                'field'    => ['name' => 'total'],
                'operator' => ['value' => 'not_between'],
                'value'    => [10],
            ],
            [
                'field'    => ['name' => 'tracking_number'],
                'operator' => ['value' => 'eq'],
                'value'    => '',
            ],
            [
                'field'    => ['name' => 'customer_token'],
                'operator' => ['value' => 'is_null'],
                'value'    => null,
            ],
        ],
        'groupBy' => [],
        'sortBy'  => [],
    ]));

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain("conditions[0]: Value for 'in' operator must be an array or comma-separated string")
        ->and($result['errors'])->toContain("conditions[1]: Value for 'not_between' operator must be an array with exactly 2 elements")
        ->and($result['warnings'])->toContain("conditions[2]: Empty value for operator 'eq' may not produce expected results")
        ->and($result['warnings'])->not->toContain("conditions[3]: Empty value for operator 'is_null' may not produce expected results");
});

test('report query validator emits resource and cartesian warnings for large joined queries', function () {
    $joins = array_map(fn () => [
        'key'         => 'customers',
        'table'       => 'customers',
        'type'        => 'left',
        'local_key'   => 'customer_uuid',
        'foreign_key' => 'uuid',
    ], range(1, 6));

    $result = report_validator_fixture()->validate(valid_report_query_config([
        'columns' => [
            ['name' => 'status'],
            ['name' => 'tracking_number'],
        ],
        'joins'      => $joins,
        'conditions' => [],
        'groupBy'    => [],
        'sortBy'     => [],
        'limit'      => 50000,
    ]));

    expect($result['valid'])->toBeTrue()
        ->and($result['warnings'])->toContain('Multiple joins (6) may significantly impact performance')
        ->and($result['warnings'])->toContain('Query may consume significant system resources')
        ->and($result['warnings'])->toContain('Multiple joins may result in cartesian products - ensure proper join conditions')
        ->and($result['summary']['complexity'])->toBe('medium')
        ->and($result['summary']['estimated_performance'])->toBe('slow');
});
