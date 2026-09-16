# v1.6.62 — Custom fields get a public id

## Improvements

- Give every custom field a public id, so an API that hands one out names it the way the rest of the platform names a resource rather than exposing an internal uuid. `CustomField` takes `HasPublicId` with the `custom_field` prefix, and `public_id` becomes fillable.
- Mint an id on the one path that would otherwise miss it: `HasCustomFields::setCustomField()` saves a field it creates on the fly with `saveQuietly()`, which skips the hook that assigns the id.

## Fixes

- Let an observer's refusal reach the caller on the update and bulk-delete paths. An observer that refused a write by throwing `FleetbaseRequestValidationException` had its explanation discarded: `HasApiModelBehavior::updateRecordFromRequest()` rewrapped every exception from the save as a plain `\Exception`, and `HasApiControllerBehavior::bulkDelete()` caught `\Exception` ahead of its dedicated handler, so callers saw `Invalid request` or a generic update error instead of the message the observer wrote. The exception now passes through untouched on both paths and is rendered with `getErrors()`, as it already was on create and single delete. Every other exception is wrapped exactly as before. Reported in [#256](https://github.com/fleetbase/core-api/issues/256).

## Reliability

- Backfill existing rows in the migration, and add the column as nullable and indexed rather than unique-and-required, so it is safe on an already-populated `custom_fields` table.
- Cover id generation for `CustomField`, and add the column to the in-memory schemas whose saves now probe it for uniqueness.

This is platform-wide: every custom field gains a public id, not only those used by inspections. Nothing reads the new column yet — `withCustomFields()`'s public projection emits field names and is unchanged — so the change is additive for existing consumers.

A database migration is required. No configuration change is needed.

Changes: [#254](https://github.com/fleetbase/core-api/pull/254), [#259](https://github.com/fleetbase/core-api/pull/259).
