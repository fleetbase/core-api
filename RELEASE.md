> v1.6.57 ~ "Settings changes take effect without clearing the cache by hand"

---
## Highlights
Writing a system setting did not invalidate the cache entry the reader actually uses, so the old value kept being served until the cache was cleared manually. On a default install — `api/.env.example` ships `CACHE_DRIVER=file` — nothing invalidated it at all.

This surfaced as a rotated platform API token being rejected as invalid: the new hash was written to the database, and every request kept validating against the previously cached one.

---
## Bug Fixes
- **A saved setting invalidated the wrong cache key.** `Setting::system('platform_api.token_hash')` caches under `system_settings.platform_api.token_hash`, using the key exactly as the caller passed it, while the row is stored as `system.platform_api.token_hash`. The model's `saved`/`deleted` events built the cache key from the row, producing `system_settings.system.platform_api.token_hash` — a key nothing ever writes. Both spellings are now forgotten.
- **Saving a setting threw when Redis was not configured.** `Utils::clearCacheByPattern()` resolved a Redis connection unguarded, and the `saved` event calls through it — so on an install without Redis, an ordinary `Setting::configureSystem()` write raised a binding exception. Pattern clearing is inherently Redis-only; without Redis there is nothing to enumerate, so it now skips instead of failing.

---
## Known limitations
`Utils::clearCacheByPattern()` still only clears entries on Redis. That no longer matters for settings, which now invalidate their own keys directly, but a caller relying on pattern clearing for something else will not see it work on another driver.

---
## Upgrade Steps
No migration and no configuration change. If a platform API token was rotated and rejected on an earlier version, rotate it once more after upgrading — or clear the cache — so the stale hash is dropped.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
