<?php

use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class SearchableRuntimeRecord extends EloquentModel
{
    use Searchable;

    protected $table             = 'searchable_runtime_records';
    protected $searchableColumns = ['name', 'owner.name'];
}

class SearchableRuntimeBareRecord extends EloquentModel
{
    use Searchable;

    protected $table = 'searchable_runtime_bare_records';
}

it('merges columns registered at runtime into the model searchable columns, per class', function () {
    SearchableRuntimeRecord::addSearchableColumns(['loan.plate_number', 'name', 42, null, 'loan.plate_number']);
    SearchableRuntimeRecord::addSearchableColumns(['loan.vin']);

    expect(SearchableRuntimeRecord::runtimeSearchableColumns())->toBe(['loan.plate_number', 'name', 'loan.vin'])
        ->and((new SearchableRuntimeRecord())->getSearchableColumns())->toBe(['name', 'owner.name', 'loan.plate_number', 'loan.vin'])
        ->and(SearchableRuntimeBareRecord::runtimeSearchableColumns())->toBe([], 'registration is per model class')
        ->and((new SearchableRuntimeBareRecord())->getSearchableColumns())->toBe([]);

    SearchableRuntimeBareRecord::addSearchableColumns(['code']);

    expect((new SearchableRuntimeBareRecord())->getSearchableColumns())->toBe(['code'])
        ->and((new SearchableRuntimeRecord())->getSearchableColumns())->not->toContain('code');
});
