<?php

/*
 * Every attribute Report persists must have a column to land in.
 *
 * `updateExecutionStats()` sets execution_count, average_execution_time and
 * last_result_count and then calls save(). None of the three had a column, so the save
 * threw and **every saved report failed to execute** — after its query had already
 * succeeded, which made it look like the query was at fault.
 *
 * ReportModelTest covers the arithmetic thoroughly and could not catch this: it drives
 * the model through a spy whose save() is a counter, so nothing it does reaches a
 * schema. This test closes that specific gap by reading the migrations rather than the
 * database, so it needs no connection and runs in the same pure-unit suite.
 */
function reportsTableColumns(): array
{
    $columns = [];

    foreach (glob(__DIR__ . '/../../../migrations/*.php') as $migration) {
        $source = file_get_contents($migration);

        // Read only up(). A down() names the same columns in its dropColumn, and
        // counting those alongside the additions cancelled them straight back out.
        if (!preg_match('/function up\(\).*?(?=\n    \/\*\*|\n    public function down)/s', $source, $upBody)) {
            continue;
        }

        // Only the blueprints that build or alter `reports` — the reporting feature also
        // has report_executions, report_cache and report_audit_logs tables, several of
        // which legitimately do have their own execution_time.
        if (!preg_match_all("/Schema::(?:create|table)\(\s*'reports'\s*,.*?\n        \}\);/s", $upBody[0], $blocks)) {
            continue;
        }

        foreach ($blocks[0] as $block) {
            preg_match_all("/\\\$table->[A-Za-z]+\(\s*'([a-z0-9_]+)'/", $block, $found);
            $columns = array_merge($columns, $found[1]);
        }
    }

    return array_unique($columns);
}

it('has a column for every execution statistic the report model writes', function () {
    $columns = reportsTableColumns();

    expect($columns)->not->toBeEmpty('no reports blueprints were found to read');

    foreach (['execution_count', 'average_execution_time', 'last_result_count', 'last_executed_at'] as $column) {
        expect($columns)->toContain($column);
    }
});

it('still has the per-run columns the api resource exposes', function () {
    // execution_time and row_count describe the last run and are returned by the Report
    // resource; the statistics above accumulate across runs. Both sets must exist —
    // adding one must not be mistaken for replacing the other.
    $columns = reportsTableColumns();

    expect($columns)->toContain('execution_time')
        ->and($columns)->toContain('row_count');
});
