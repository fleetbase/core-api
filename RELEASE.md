> v1.6.60 ~ "Revoked keys stay revoked, transactions stop colliding, webhooks stop signing with the wrong secret"

---
## Highlights
Three independent defects, each of which let the platform keep doing something an operator had already told it to stop.

**Deleting an API credential did not revoke it.** The console hid the row and the key kept authenticating — indefinitely, on every endpoint. Combined with an "expire immediately" option that also did not take effect, a stock Fleetbase console had **no working way to revoke an API credential**. Anyone who has ever deleted a key in the console should read the upgrade steps.

**Persistent PDO handles shared one MySQL transaction.** Writes landed and the API still answered `422 There is no active transaction`, so anyone who retried after the error applied the write twice.

**A queue worker signed every lifecycle webhook with the first event's secret.** Every outbound webhook after the first carried the wrong HMAC key, along with the wrong credential, environment and company.

---
## Security Fixes
- **Soft-deleted API credentials still authenticated.** `AuthenticateOnceWithBasicAuth` looks the credential up with `withoutGlobalScopes()`, which strips `SoftDeletingScope` along with `ExpiryScope`. Expiry was re-applied in PHP; soft-deletion never was. A credential the console reports as **Deleted** kept authenticating indefinitely — and Delete is the only revocation most operators ever perform. Now rejected with a 401, before the `OPTIONS` shortcut so a revoked key cannot seed api key session context on a preflight either.

- **Authentication was fail-open when the credential's creator was gone.** An API credential carries no identity of its own; it acts as the user that created it. When that user no longer resolved — deleted, or soft-deleted on off-boarding — the `is_admin` guard was skipped but `Auth::setSession()` still returned `true`. Authorization degraded safely, since a null user fails every group and admin check, but authentication did not: the key kept working on every read endpoint and every ungated write. Off-boarding a person did not revoke the keys they had created. `setSession()` now returns `false` in that case and the middleware answers a clean 401.

---
## Bug Fixes
- **"Expire immediately" did not expire the credential.** `ApiCredential::setExpiresAtAttribute()` maps the console's `immediately` option to `Carbon::now()`, but `Expirable::hasExpired()` used a strict `<`, so `now() < now()` was false. `ExpiryScope` already disagreed with it — it keeps a row only while `expires_at > now()`, i.e. it treats an exactly-now expiry as expired. `hasExpired()` is now inclusive, so the trait and the scope agree.

- **Persistent PDO handles shared one MySQL transaction.** `Connection::commit()` decides whether to issue a COMMIT from its own counter; PDO decides whether one is legal from the server's `SERVER_STATUS_IN_TRANS` flag, and nothing reconciled the two. With `PDO::ATTR_PERSISTENT => true`, PHP hands the same MySQL session to a second handle built from the same DSN while the first is still using it, so one handle could commit — and thereby invalidate — the other's transaction. Observed on onboarding account creation, ledger invoice creation and inventory stock adjustments, none of which share a code path. Persistence is now `env('DB_PERSISTENT', false)` on the `mysql` and `sandbox` connections.

- **A queue worker signed lifecycle webhooks with a stale secret.** `SendResourceLifecycleWebhook::setSessionFromEvent()` only wrote a session key when it was absent, and `handle()` preferred the session value over the event's. A `queue:work` process is long-running and its session store is a container singleton, so once the worker handled one lifecycle event, every later event reused that first event's `api_secret` — plus its `api_credential`, `api_key`, `api_environment`, `is_sandbox`, `company` and `user`. The context serialized onto the event is now authoritative for the job that carries it, and a restorer running in a `finally` hands the session back as it was found. Reported in #244.

---
## Testing
- New coverage for revoked credentials on both normal and `OPTIONS` requests, for a credential whose creator has been soft-deleted, for `Auth::setSession()` returning `false`, and for the exactly-now expiry boundary.
- Four tests for the webhook secret bleed, each verified to fail against the unpatched listener, covering per-event signing, sandbox/live isolation, session restoration, and event-over-session credential attribution.
- The middleware fixture previously seeded the sandbox user only on the sandbox connection. `User` is pinned to the `mysql` connection and `sandbox:sync` mirrors `mysql → sandbox`, so the fixture now matches the real system.

---
## Upgrade Steps
**Audit your API credentials.** Any credential deleted from the console before this release was never actually revoked and has been live the whole time. After upgrading, those keys stop working — which is the point, but it means an integration quietly running on a key someone believed they had deleted will break at upgrade rather than at deletion. List your credentials including soft-deleted rows before deploying if you want to know what will change.

**Keys whose creator has been off-boarded will start returning 401.** That is the intent of the fix, but it is a behaviour change for anyone whose integrations run on a departed employee's credential. Reassign those to a dedicated service user before upgrading.

**Deployments not running Octane may see per-request connect cost.** `PDO::ATTR_PERSISTENT` now defaults to off. Under Octane connection counts are unchanged (measured at 17 on a 24-thread FrankenPHP container). Short-lived PHP-FPM workers that relied on the pool will pay roughly 1–3 ms per request; `DB_PERSISTENT=true` restores the old behaviour, at the cost of re-arming the transaction bug for any request that opens a transaction. Note that persistent connections also silently defeated Octane's `DisconnectFromDatabases` listener, which now works as intended.

No migration and no configuration change is required.

---
## Still Open
API credentials still carry no scope of their own — `Auth::setSession()` derives `is_admin` from whoever created the key, so every key an admin creates is a full-admin key regardless of what it is named. Least privilege is currently only reachable indirectly, by scoping the *creator* into a dedicated service user. Per-key roles, or an explicit key assignee decoupled from the creator, is a feature rather than a fix and is tracked separately.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
