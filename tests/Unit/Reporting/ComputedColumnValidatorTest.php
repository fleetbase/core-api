<?php

namespace Tests\Unit\Reporting;

use Fleetbase\Support\Reporting\ComputedColumnValidator;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;
use Tests\TestCase;

class ComputedColumnValidatorTest extends TestCase
{
    protected ComputedColumnValidator $validator;
    protected ReportSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock registry with a test table
        $this->registry = new ReportSchemaRegistry();

        $testTable = Table::make('test_table')
            ->label('Test Table')
            ->columns([
                Column::make('id', 'integer')->label('ID'),
                Column::make('name', 'string')->label('Name'),
                Column::make('start_date', 'date')->label('Start Date'),
                Column::make('end_date', 'date')->label('End Date'),
                Column::make('amount', 'decimal')->label('Amount'),
                Column::make('quantity', 'integer')->label('Quantity'),
                Column::make('details.price', 'decimal')->label('Price'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('related', 'related_table')
                    ->label('Related')
                    ->localKey('related_id')
                    ->columns([
                        Column::make('value', 'string')->label('Value'),
                    ]),
            ]);

        $this->registry->registerTable($testTable);
        $this->validator = new ComputedColumnValidator($this->registry);
    }

    /** @test */
    public function itValidatesSimpleDatediffExpression()
    {
        $expression = 'DATEDIFF(end_date, start_date)';
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function itValidatesComplexDatediffWithFunctions()
    {
        $expression = "DATEDIFF(LEAST(end_date, '2025-10-31'), GREATEST(start_date, '2025-10-01')) + 1";
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function itValidatesConcatExpression()
    {
        $expression = "CONCAT(name, ' - ', id)";
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function itValidatesCaseStatement()
    {
        $expression = "CASE WHEN amount > 100 THEN 'High' WHEN amount > 50 THEN 'Medium' ELSE 'Low' END";
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function itValidatesSafeDivision()
    {
        $expression = 'ROUND(COALESCE(amount / NULLIF(quantity, 0), 0), 2)';
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function itValidatesJsonColumnAccess()
    {
        $expression = 'details.price * quantity';
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function itValidatesRelationshipColumnAccess()
    {
        $expression = "CONCAT(name, ' - ', related.value)";
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function itRejectsForbiddenKeywords()
    {
        $expressions = [
            'DROP TABLE test_table',
            'DELETE FROM test_table',
            'UPDATE test_table SET name = "test"',
            'INSERT INTO test_table VALUES (1)',
            'UNION SELECT * FROM users',
        ];

        foreach ($expressions as $expression) {
            $result = $this->validator->validate($expression, 'test_table');

            $this->assertFalse($result['valid'], "Expression '{$expression}' should be invalid");
            $this->assertNotEmpty($result['errors']);
        }
    }

    /** @test */
    public function itRejectsInvalidFunctions()
    {
        $expression = 'INVALID_FUNC(name)';
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('INVALID_FUNC', $result['errors'][0]);
    }

    /** @test */
    public function itRejectsDangerousOperators()
    {
        $expressions = [
            'name || "test"',  // String concatenation operator (MySQL specific)
            'name; DROP TABLE test_table',
            'name -- comment',
            'name /* comment */',
        ];

        foreach ($expressions as $expression) {
            $result = $this->validator->validate($expression, 'test_table');

            $this->assertFalse($result['valid'], "Expression '{$expression}' should be invalid");
            $this->assertNotEmpty($result['errors']);
        }
    }

    /** @test */
    public function itRejectsInvalidColumnReferences()
    {
        $expression = 'DATEDIFF(invalid_column, start_date)';
        $result     = $this->validator->validate($expression, 'test_table');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('invalid_column', $result['errors'][0]);
    }

    /** @test */
    public function itRejectsInvalidTableName()
    {
        $expression = 'DATEDIFF(end_date, start_date)';
        $result     = $this->validator->validate($expression, 'invalid_table');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('invalid_table', $result['errors'][0]);
    }

    /** @test */
    public function itProvidesAllowedFunctionsList()
    {
        $functions = $this->validator->getAllowedFunctions();

        $this->assertIsArray($functions);
        $this->assertContains('DATEDIFF', $functions);
        $this->assertContains('CONCAT', $functions);
        $this->assertContains('CASE', $functions);
    }

    /** @test */
    public function itProvidesAllowedOperatorsList()
    {
        $operators = $this->validator->getAllowedOperators();

        $this->assertIsArray($operators);
        $this->assertContains('+', $operators);
        $this->assertContains('-', $operators);
        $this->assertContains('*', $operators);
        $this->assertContains('/', $operators);
    }
}
