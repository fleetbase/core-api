<?php

test('example', function () {
    expect(true)->toBeTrue();
});

test('transaction settled currency supports taler sandbox currency codes', function () {
    $freshMigration = file_get_contents(__DIR__ . '/../migrations/2024_01_01_000001_improve_transactions_table.php');
    $widenMigration = file_get_contents(__DIR__ . '/../migrations/2026_07_17_000001_widen_transaction_settled_currency.php');

    expect($freshMigration)
        ->toContain("\$table->string('settled_currency', 10)");

    expect($widenMigration)
        ->toContain('changeSettledCurrencyLength(10)')
        ->toContain("\$table->string('settled_currency', \$length)->nullable()->change()");
});
