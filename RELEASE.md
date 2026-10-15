# v1.6.71 — Keep live and test-mode API caches apart; driver-assigned events on driver channels

## Fixed

- **A controller's filter is used for every query it serves.** `Find::httpFilterForModel()` cached its answer per model class for the life of the process, ignoring the filter a controller sets on its model (`$filter`) and whether the request was internal. Under Octane, the first controller to list a model decided the filter for every other: Fleet-Ops' order filter was applied to Storefront's order lists, so a network's orders page listed every order in the company. The cache is now keyed by the model's own filter, and a filter found by convention is picked per request audience. `Find::httpRequestForModel()` is keyed by the model's own request class too. (#315)
- **Live and test-mode data no longer share API cache entries.** List, model and relationship cache keys now carry the database connection (`{api_query}:mysql:…` / `{api_query}:sandbox:…`). Before, a console request in test mode could be served the cached live response for the same query (and the reverse), because console sessions carry no API credential to tell the two apart. (#312)
- **Driver-assigned lifecycle events reach `driver.{public_id}` and `driver.{uuid}`.** Order events for records with an assigned driver were only published on `driverAssigned.*`, which clients following the `{type}.{id}` convention never subscribe to. `driverAssigned.*` is still published and is now deprecated. (#311)

## Upgrade Steps

- None. Existing cache entries keep their old keys and expire within their TTL (5 minutes for lists, 1 hour for models); `deploy.sh` already runs `php artisan cache:clear`.
