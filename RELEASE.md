# v1.6.64 — Order reporting and test SMS with the entered credentials

## Improvements for reporting

- Declare row-level expression columns with `Column::expression($name, $sql, $type)`. Bare names resolve against the table or relationship that declares it, so `JSON_EXTRACT(meta, '$.quantity')` on `payload.entities` reads the joined entity's `meta`. An expression column can be selected, filtered, sorted, grouped by and aggregated.
- Summary columns (`Column::count/sum/avg`) are flagged `aggregate` and resolve to their computation. Without grouping they return a single summary row; with grouping they sit beside the group keys.
- `Table::softDeletes()` and `Relationship::softDeletes()` leave out soft-deleted rows. On joins the filter goes in the `ON` clause, so LEFT joins keep the parent row.
- Computed columns can be group keys, conditions and sort columns, and a grouped report can be sorted by an aggregate's alias. A new `count_distinct` aggregate is available.
- Custom expressions accept the JSON functions, `DATE()`, `CAST(… AS DECIMAL(15,2))` and the other cast types, `DISTINCT`, `IN`, `GROUP_CONCAT(… ORDER BY … SEPARATOR …)`, `->`/`->>` and `INTERVAL n UNIT`.
- `public_id` and `internal_id` are no longer hidden as foreign keys, so ID columns appear in the column picker.
- Relationship columns are labelled with the whole relationship name ("Order Config Namespace", not "Order Namespace"), and aggregate labels use the column label ("Sum (Quantity)").
- `_key` and `_import_id` are never listed or selectable, whatever a schema declares.

## Fixes

- Test SMS Provider and Test Twilio in Admin › System Config › Services use the credentials entered in the form (fleetbase/fleetbase#680). Under Octane the Twilio client was built once per worker, so a test failed with "Credentials are required to create a Client" or reported success for the saved account. The endpoints now rebuild the client from the request's config and release it after the send.

## Security

- Every computed column is validated up front, including in grouped reports. Names must be safe identifiers, and `SELECT` is forbidden.
- Schema-declared columns always take their SQL from the registry, never from the request. Group keys, aggregate columns, sort columns and condition fields must be allowed or computed columns.
- Sort direction is normalised to `asc`/`desc`, and grouped reports validate `aggregateBy.computation`.

## Behaviour changes

- In a grouped report, a selected column that is neither a group key nor aggregated is now an error instead of being dropped.
- Invalid report shapes fail with a clear message instead of an SQL error.

A database migration is not required. No configuration change is needed. The FleetOps order report schema ships in fleetbase/fleetops v0.6.70, and the report builder changes in fleetbase/ember-ui v0.4.4.

Changes: [#269](https://github.com/fleetbase/core-api/pull/269), [#270](https://github.com/fleetbase/core-api/pull/270).
