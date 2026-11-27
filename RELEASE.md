> v2.0.6 ~ "Platform token sign-in for instance apps"

# v2.0.6 — Platform token sign-in for instance apps

## Added

- **A middleware group for the routes an app calls before its user signs in: `fleetbase.api-or-platform`.** It is `fleetbase.api` that also accepts the instance's platform token (`flb_platform_…`). An app built for the whole instance, such as Navigator, has no organization until its user signs in, so it can present the platform token on these routes instead of one organization's API key. API keys and access tokens work on them as before.
  - `AuthenticateApiOrPlatformToken` checks a bearer token that starts with `flb_platform_` against the platform token and answers 401 if it is wrong; it is never tried as an API key. Any other credential goes to `AuthenticateOnceWithBasicAuth` unchanged.
  - A request that authenticated with the platform token has no organization and no user. `PlatformApi::authenticated($request)` tells a controller which kind it is handling.
  - New helpers: `PlatformApi::isPlatformToken()`, `PlatformApi::markAuthenticated()`, `PlatformApi::TOKEN_PREFIX`.
  - **The platform token ships inside apps, so treat it as public.** Only put a route in this group if it is safe for anyone to call.

## Fixed

- **Requests carrying the platform token are rate limited per client address.** The limiter keys on the presented credential, and every installed app presents the same platform token, so they all shared one bucket: one busy or hostile client could lock every user out. An API key is still one bucket wherever it is sent from. The API consumers view lists these requests as **Platform token**.
- **A use of the platform token is written down at most once every five minutes.** `PlatformApi::markUsed()` saved `last_used_at` on every request, and each save clears the system settings cache.

## Upgrade Steps

- No migration. `fleetbase.platform-api` and `fleetbase.api` are unchanged.
