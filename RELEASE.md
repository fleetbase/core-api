# v1.6.71 — Keep live and test-mode API caches apart; driver-assigned events on driver channels

## Fixed

- **Live and test-mode data no longer share API cache entries.** List, model and relationship cache keys now carry the database connection (`{api_query}:mysql:…` / `{api_query}:sandbox:…`). Before, a console request in test mode could be served the cached live response for the same query (and the reverse), because console sessions carry no API credential to tell the two apart. (#312)
- **Driver-assigned lifecycle events reach `driver.{public_id}` and `driver.{uuid}`.** Order events for records with an assigned driver were only published on `driverAssigned.*`, which clients following the `{type}.{id}` convention never subscribe to. `driverAssigned.*` is still published and is now deprecated. (#311)

## Upgrade Steps

- None. Existing cache entries keep their old keys and expire within their TTL (5 minutes for lists, 1 hour for models); `deploy.sh` already runs `php artisan cache:clear`.
