# AGENTS.md — core-api

## Repo purpose
The foundational Laravel package (`fleetbase/core-api`) providing models, services, abstractions, helpers, and the extension contract used by every Fleetbase backend extension. Imported by `fleetbase/api` via composer (either from Packagist or as a local path).

## What this repo owns
- `src/Models/` — base models (Organization, User, etc.)
- `src/Http/Controllers/` — internal controllers used by the console
- `src/Support/` — `Utils`, `EnvironmentMapper`, `Str` expansions, etc.
- `src/Expansions/` — Laravel macro registrations
- `src/Notifications/`, `src/Mail/`, `src/Events/`, `src/Listeners/`
- The extension service provider contract

## What this repo must not modify
- Anything that breaks public method signatures of widely-used helpers (`Utils`, `Str` expansions). These are called by every extension.
- The extension contract — adding required methods is a breaking change for every downstream extension.

## Framework conventions
- Laravel 10+, PHP 8.0+
- PSR-4 autoload under `Fleetbase\\`
- Notifications via Laravel's notification system
- Eloquent + activity log via `spatie/laravel-activitylog`

## Test / build commands
- This package is consumed by `fleetbase/api`. To test changes: edit here, then in the application container run `composer update fleetbase/core-api` (requires path repository in `api/composer.json`).
- `vendor/bin/phpunit`

## Known sharp edges
- **`Str::domain($url)` at `src/Expansions/Str.php:53`** crashes on hosts with no `.` (e.g. `localhost`). Workaround in `fleetbase/api/.env`: set `MAIL_FROM_ADDRESS`. **If you fix this here, also remove the workaround.**
- `EnvironmentMapper.php` has dozens of nested AWS/SQS/SES key mappings. Don't refactor without a clear need.
- `Utils::getDefaultMailFromAddress()` is the caller of the buggy `Str::domain` — start here when fixing the upstream bug.

## Read first
- `~/fleetbase-project/docs/project-description.md`
- `~/fleetbase-project/docs/repo-map.md`
- `~/fleetbase-project/docs/ai-rules-laravel.md`
- `~/fleetbase-project/docs/ai-rules-workspace.md`

## Boost gate
This repo IS host-cloned (unlike `fleetbase/api`), so Boost outputs would land in a place future agents can read. Before first edit: `composer require laravel/boost --dev && php artisan boost:install` from a **real terminal** (the installer is interactive and crashes on `docker compose exec -T`). Then commit.
