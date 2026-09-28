# v1.6.65 — Authenticator-app 2FA, sign-in hardening and IAM permission fixes

## Improvements

- Sign in with an authenticator app (TOTP, RFC 6238), next to email and SMS 2FA (#163). It works with Authy, Google Authenticator, Microsoft Authenticator and 1Password.
  - New `users/two-fa/authenticator` endpoints to set up, confirm, disable and inspect the app. Setup, disable and regenerating recovery codes need the current password.
  - Confirming the app returns 8 single-use recovery codes. `two-fa/verify` accepts an app code (±1 step for clock drift, each code once) or a recovery code.
  - `two-fa/resend` falls back to an emailed (or SMS) code and returns the new `method`.
  - The secret is encrypted with the app key, and recovery codes are stored as keyed hashes.
  - New `Fleetbase\Support\Barcode` helper with a compact `qrCodeSvg()`.
- Record the app, APNs environment and last-seen time on user devices. New nullable `user_devices` columns: `app_identifier` (indexed), `environment` and `last_seen_at`.
- New organization setting to let users change their own password (default on), at `GET/POST companies/auth-settings`. `GET users/password-policy` returns `{can_change_password}`.

## Fixes

- Settings → Notifications applies when notifications are sent from the queue or a console command (#262). `NotificationRegistry` takes the company from the notification's subject instead of the session. `notifyUsingDefinitionName()` reads the company-scoped settings instead of a global key nothing writes. New `Setting::lookupForCompany()`.
- Invited users can set their first password, and non-admins can change their own (#263). The password endpoints no longer fall through to `iam create user`.
- FCM order notifications are sent with Android `priority: high`, so drivers' phones in Doze get them immediately (#268).

## Security

- A 2FA session starts only after the password is checked. `GET two-fa/check` used to start one from the identity alone, so an email plus the emailed code was enough to sign in. It now always returns `{twoFaSession: null, isTwoFaEnabled: false}` and no longer reveals whether an account uses 2FA.
- 2FA sessions expire after 10 minutes; they used to live about 56 years. The 5th wrong code deletes the session, and resending doesn't reset the count. Codes are compared in constant time and generated with `random_int`.
- `users/change-password` requires `current_password` in the same request, and `iam change-password` is enforced. `users/set-password` works only once, within 24 hours of accepting an invite. `validate-password` and `change-password` are limited to 10 requests per minute.
- Close authorization gaps where `AuthorizationGuard` resolved to permission names that don't exist:
  - Updating the organization and its 2FA policy is limited to the owner, the Administrator role and system admins. Non-admin updates ignore `owner_uuid`, Stripe ids, `plan`, `status`, `trial_ends_at` and `type`.
  - `POST two-fa/config` (system 2FA policy) and admin platform metrics are limited to system admins.
  - IAM and developer metrics need `iam list user` / `developers list api-key`.
  - Reports use the `iam` service: `iam execute report` for direct queries, `iam export report` for exports.
  - API credentials, webhooks, API events and request logs check the `api-key`, `webhook`, `event` and `log` permissions.
- Password, auth-setting and authenticator changes are written to the `auth` activity log.

## Behaviour changes

- Consoles need the companion fleetbase/fleetbase changes: the 2FA sign-in flow (`fix/2fa-login-hardening`), `current_password` on change-password (fleetbase/fleetbase#685) and the authenticator-app UI (fleetbase/fleetbase#686). With an older console, users with 2FA can't sign in and self-service password changes fail.
- Users need `iam … report` permissions to use reports, and `developers …` permissions for API keys, webhooks, events and logs. Fleet-Ops' report screens check the same names in fleetbase/fleetops#345.
- A user who accepted an invite before this release but never set a password should use **Forgot password**.

Run the migrations for the new `user_devices` columns. Run `composer update` to install `pragmarx/google2fa`.

Changes: [#272](https://github.com/fleetbase/core-api/pull/272), [#273](https://github.com/fleetbase/core-api/pull/273), [#274](https://github.com/fleetbase/core-api/pull/274), [#275](https://github.com/fleetbase/core-api/pull/275), [#276](https://github.com/fleetbase/core-api/pull/276), [#277](https://github.com/fleetbase/core-api/pull/277), [#278](https://github.com/fleetbase/core-api/pull/278).
