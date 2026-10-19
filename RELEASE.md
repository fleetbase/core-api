# v1.6.72 — Searchable columns registered at runtime

## Added

- **Searchable columns registered at runtime.** `Searchable::addSearchableColumns([...])` lets an extension make its own data findable through a core or Fleet-Ops model's search without editing the model: `Order::addSearchableColumns(['companion.reference_number', 'customerContact.name'])`. Columns may use the existing `relation.column` dot notation, and the relation may be one the extension adds with Eloquent's `Model::resolveRelationUsing()`. Registered columns are merged into `getSearchableColumns()` (search) and `searcheableFields()` (filters), per model class. (#317)

## Upgrade Steps

- None. Nothing changes until an extension registers columns.
