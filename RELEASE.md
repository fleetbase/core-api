> v2.0.0 ~ "Laravel 12"

# v2.0.0 — Laravel 12, Sanctum 4 and Carbon 3

core-api now runs on Laravel 12 (`illuminate/* ^12.69|^13.0`) and PHP 8.2 or later. This is a major release: extensions must be released against `fleetbase/core-api ^2.0` to install with it, and Laravel 10 installs stay on 1.6.x.

## Breaking

- **Laravel 12 and PHP 8.2 required.** The constraint already admits Laravel 13, so the next step needs no change here.
- **Sanctum 4.** Sanctum no longer loads its own migrations, so core-api ships a guarded `personal_access_tokens` create migration. It does nothing on databases that already have the table, so existing tokens keep working.
- **Carbon 3.** `diffIn*` now returns signed floats. core-api's own calls are cast and given an explicit direction, so their results match Carbon 2. Extensions must check their own calls.
- **`Request::array()` macro renamed to `inputArray()`** (#331). Laravel 11+ ships a native `Request::array()`, which wins over a macro of the same name and does not split comma-separated values. On Laravel 12, `?sort=a,b` and `?without=a,b` became one value and returned a 500. The old name is not available in 2.0.0, because the native method makes it unreachable. Extensions must call `inputArray()`.
- **Doctrine DBAL removed** (#332). Schema introspection uses Laravel's own `Schema` builder, so `getDoctrineSchemaManager()` is gone from core-api's code paths. Migrations that used Doctrine to check for an index now use `Schema::getIndexes()`.
- **New majors of Fleetbase's forks:** `fleetbase/laravel-mysql-spatial ^2.0`, `fleetbase/countries ^0.9.0` (drops `pragmarx/coollection`, which held Carbon at 2) and `fleetbase/twilio ^6.0` (Twilio SDK 8).

## Fixed

- **FCM push on kreait/laravel-firebase 6.** `FirebaseProjectManager` now requires the config repository. The previous one-argument construction would have failed every FCM push.
- **`QueryOptimizer`** no longer constructs a query grammar without a connection, which Laravel 12 rejects, and catches `Throwable`, so a failure there returns the original query instead of an error.
- **Report error categories** no longer depend on install-specific values. Laravel 11+ adds the host, port and database name to `QueryException` messages, so a database named with `memory` or `timeout`, say, could change the category. They are now stripped before classifying.

## Dependencies

- Notification channels: apn `^5.5`, fcm `^5.1`, twilio `^4.1`. Also `milon/barcode ^13`, `innoge/laravel-msgraph-mail ^2`.
- `hammerstone/fast-paginate` is abandoned, so it's replaced by `aaronfrancis/fast-paginate ^2.0`, the same package under its new name. The `fastPaginate()` macro is unchanged.
- `phpoffice/phpspreadsheet ^1.30.7`, which fixes the XXE bypass and denial of service advisories in 1.30.6.
- `composer audit` reports no advisories and no abandoned packages, with no ignores configured.
- Dev: Pest 3, collision 8, `cknow/laravel-money ^8`, mockery.

## Upgrade Steps

- Run `php artisan migrate`. The `personal_access_tokens` migration is a no-op if the table exists.
- Update every Fleetbase extension to its release that requires `fleetbase/core-api ^2.0`. Extensions built for 1.6.x call `$request->array()` and Doctrine-era APIs and will not work.
- Flush the cache at upgrade if `CACHE_PREFIX` is set without a trailing `:`. Laravel 11+ no longer adds one. The root app's default prefix now includes it.
