> v2.0.3 ~ "Foreign keys point at unique keys, and push apps"

# v2.0.3 — Foreign keys point at unique keys, and push apps

## Added

- **Push apps: an organization's own apps send pushes with their own credentials.** A push app (`push_apps`) holds one app's APNs key, team and bundle ID and its Firebase service account, stored encrypted and never returned. It has a key (`push_app_…`) that the app sends when it registers a device, which links the device to the app (`user_devices.push_app_uuid`, plus `company_uuid`).
  - Notifications that add `Fleetbase\Push\PushAppChannel` to `via()` and use the `PushesToApps` trait send to those devices with their app's credentials. iOS devices go to the APNs environment they registered with, or production then sandbox, and tokens rejected in every environment are removed.
  - Devices registered without a push app are unchanged: the install-wide APNs and FCM settings under Admin › Config still send to them, and `routeNotificationForApn/Fcm` now leave out push-app devices, so no device gets a push twice.
  - The sender is Storefront's per-channel sender, moved into core and made app-agnostic.

## Fixed

- **Core tables that foreign keys point at can be altered on MySQL 8.4 with `restrict_fk_on_non_standard_key` on.** v2.0.2 turned that setting off while migrations run. This release removes the cause: the columns those foreign keys point at are now unique. `chat_channels`, `chat_messages`, `chat_participants`, `comments`, `custom_fields`, `dashboards` and `reports` get a unique `uuid`, and `permissions` and `roles` a unique `id`. Core's copy of the spatie/permission migration had declared `id` with an ordinary index instead of a primary key. Each column's old index is replaced, so it is not indexed twice. A column that already has a unique or primary key is left alone. A table with duplicate values is skipped and reported, and the migration can be run again once they are removed. Runs on the main and sandbox databases.
- **Sandbox no longer has foreign keys to a table it doesn't have.** `api_events`, `api_request_logs` and `webhook_request_logs` had `access_token_id` foreign keys to `personal_access_tokens`, which exists only in the main database. They are dropped in sandbox. The columns stay, and nothing changes in the main database.

## Upgrade Steps

- `2026_11_19_000001_create_push_apps_table` creates `push_apps` and adds `push_app_uuid` and `company_uuid` to `user_devices`.
- Run `php artisan migrate` and `php artisan sandbox:migrate`. If a table is reported as having duplicate values, it is skipped. Remove the duplicates, then run the `php artisan migrate:refresh --force --path=…` command the message gives. It rolls back and re-runs only that migration, and lists every other migration as "Migration not found" without touching them.
- Create migrations for new tables should declare `uuid` as `->unique()`, so foreign keys to it work on MySQL 8.4 without the migration hook.
