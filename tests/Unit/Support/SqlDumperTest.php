<?php

use Fleetbase\Support\SqlDumper;

function invokeSqlDumperHelper(string $method, array $arguments = [])
{
    $reflection = new ReflectionClass(SqlDumper::class);
    $methodRef  = $reflection->getMethod($method);
    $methodRef->setAccessible(true);

    if ($methodRef->isStatic()) {
        return $methodRef->invokeArgs(null, $arguments);
    }

    $instance = $reflection->newInstanceWithoutConstructor();

    return $methodRef->invokeArgs($instance, $arguments);
}

test('sql dumper formats record values for insert statements', function () {
    $values = invokeSqlDumperHelper('formatRecordValues', [[
        'null_value'       => null,
        'true_value'       => true,
        'false_value'      => false,
        'integer_value'    => 42,
        'float_value'      => 12.5,
        'numeric_string'   => '12345',
        'leading_zero'     => '0123',
        'quoted_string'    => "Fleetbase's dump",
        'plain_string'     => 'dispatch',
    ]]);

    expect(array_values($values))->toBe([
        'NULL',
        '1',
        '0',
        '42',
        '12.5',
        '12345',
        "'0123'",
        "'Fleetbase''s dump'",
        "'dispatch'",
    ]);
});

test('sql dumper quotes identifiers and escapes embedded backticks', function () {
    expect(invokeSqlDumperHelper('quoteIdentifiers', [['id', 'company_uuid', 'bad`column']]))->toBe([
        '`id`',
        '`company_uuid`',
        '`bad``column`',
    ]);
});

test('sql dumper primary key detection prefers id then uuid and can fall back to null', function () {
    expect(invokeSqlDumperHelper('getPrimaryKey', [['uuid', 'id', 'company_uuid']]))->toBe('id')
        ->and(invokeSqlDumperHelper('getPrimaryKey', [['uuid', 'company_uuid']]))->toBe('uuid')
        ->and(invokeSqlDumperHelper('getPrimaryKey', [['public_id', 'company_uuid']]))->toBeNull();
});

test('sql dumper detects likely foreign key column variants', function () {
    expect(invokeSqlDumperHelper('isForeignKey', ['order_uuid', 'orders']))->toBeTrue()
        ->and(invokeSqlDumperHelper('isForeignKey', ['orders_id', 'orders']))->toBeTrue()
        ->and(invokeSqlDumperHelper('isForeignKey', ['order_item_uuid', 'order_items']))->toBeTrue()
        ->and(invokeSqlDumperHelper('isForeignKey', ['orderitems_id', 'order_items']))->toBeTrue()
        ->and(invokeSqlDumperHelper('isForeignKey', ['company_uuid', 'orders']))->toBeFalse();
});

test('sql dumper guesses and merges foreign key parent sets', function () {
    $columns = ['id', 'order_uuid', 'customer_id', 'unrelated_uuid'];

    expect(invokeSqlDumperHelper('guessForeignKeyMatches', ['events', $columns, ['orders', 'customers']]))
        ->toBe(['order_uuid', 'customer_id']);

    $merged = invokeSqlDumperHelper('collectPrimaryKeysForFk', ['order_uuid', [
        'orders'    => ['order_1' => true, 'order_2' => true],
        'customers' => ['customer_1' => true],
    ]]);

    expect($merged)->toBe([
        'order_1' => true,
        'order_2' => true,
    ]);
});
