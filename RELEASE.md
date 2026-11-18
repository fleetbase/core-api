> v2.0.2 ~ "Migrations run on MySQL 8.4"

# v2.0.2 — Migrations run on MySQL 8.4

## Fixed

- **Installs and upgrades run on MySQL 8.4 and later.** MySQL 8.4 turns on `restrict_fk_on_non_standard_key` by default. With it on, MySQL refuses to create a foreign key that points at a non-unique key (error 6125), and refuses any change to a table that such a key points at (error 1553, `Cannot drop index '<unknown key name>': needed in a foreign key constraint`). Many Fleetbase foreign keys point at `uuid` columns with an ordinary index. So on 8.4 a fresh install failed at the first of them, and upgrading to Fleetbase v0.8.0 failed at Fleet-Ops' adhoc dispatch migration. While `migrate` or `sandbox:migrate` runs, each MySQL connection it uses, including ones a migration opens such as storefront's, now has that setting off for its session and back on when the run ends. Nothing changes outside migrations, or on MySQL 8.0, MariaDB, SQLite or Postgres.

## Upgrade Steps

- If an upgrade stopped with either error, upgrade to core-api v2.0.2, then run `php artisan migrate` and `php artisan sandbox:migrate` again. Fleet-Ops v0.7.1 also lets its adhoc dispatch migration resume after a failed run.
