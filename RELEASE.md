# v1.6.73 — `mysql:createdb` works on a fresh install again

## Fixed

- **`mysql:createdb` failed on a fresh install with "Unknown database".** At boot, `Setting::doesntHaveConnection()` opens the `mysql` connection with the configured schema selected and Laravel caches it. Clearing the schema name in config afterwards had no effect on that cached connection, so `CREATE DATABASE` ran on a connection pointing at the database it was meant to create. The command now purges the `mysql` connection before each `CREATE DATABASE`, and again after restoring the schema name. `Setting::hasConnection()` also purges the connection when its check fails, so a broken connection isn't left cached for other code.

## Upgrade Steps

- None. Deployments that worked around this with an override of `CreateDatabase` in `api/app/Console/Commands` can delete the override and its binding in `AppServiceProvider`.
