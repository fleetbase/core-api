> v2.0.4 ~ "Sandbox migrations for extensions outside core's vendor"

# v2.0.4 — Sandbox migrations for extensions outside core's vendor

## Fixed

- **`sandbox:migrate` runs the migrations of extensions installed outside core's `vendor` directory.** It made each extension's migration path relative by cutting it at the first `vendor` segment. Two layouts broke:
  - **Extensions project** (`api/extensions/vendor/...`, fleetbase/fleetbase#754): the path pointed at core's `vendor` instead, so those sandbox migrations never ran.
  - **Path-linked package in development** (`packages/<name>`): the absolute path was passed as `--path`, which Laravel resolves against the base path.

  Paths inside the app are now made relative by stripping the base path, and paths outside it are migrated with `--realpath`.

## Upgrade Steps

- Run `php artisan sandbox:migrate` to apply any extension sandbox migrations that were skipped before.
