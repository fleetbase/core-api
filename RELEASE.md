> v2.0.1 ~ "Sort keys registered at runtime"

# v2.0.1 — Sort keys registered at runtime

## Added

- **Sort keys registered at runtime.** `Model::addSortHandler('key', fn ($builder, string $direction, $request) => …)` lets an extension make its own data sortable through a core or Fleet-Ops model's list endpoint without editing the model: `?sort=key` and `?sort=-key` are ordered by the handler instead of being treated as a column of the model's table. A typical handler orders by a column of a companion table with a correlated subquery. Handlers are kept per model class; a handler registered for a key that is also a real column takes precedence. This completes the set an extension needs to add a column to a list view: values through resource transformers, filters through `Filter` expansions, search through `addSearchableColumns()`, and now sorting. (#335)

## Upgrade Steps

- None. Nothing changes until an extension registers a handler.
