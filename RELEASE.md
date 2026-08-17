> v1.6.56 ~ "The public API answers callers, and answers them in JSON"

---
## Highlights
A release of platform-level fixes, most of them found by running the official Postman collections against a live stack. Several affected every Fleetbase install, not just CI: public API requests could not identify their own user, error responses arrived as HTML stack traces, and a verification email could not be sent at all to a recipient without a name.

---
## Bug Fixes
- **`$request->user()` was null on every public API request.** The `fleetbase.api` middleware authenticates with `Auth::setSession()`, which writes the session keys but never binds a user resolver. Anything downstream that asked the request who was calling got nothing.
- **API clients received HTML stack traces.** The exception handler rendered Laravel's debug page to callers that had asked for JSON, so a client parsing the response found markup where an error body belonged.
- **A user without a name broke verification email.** `Utils::delinkify()` required a string and the mail view passed it a null name, so the send threw instead of delivering. This blocked customer-creation verification for any recipient the code had no name for.
- **A multi-table `findModel()` miss queried a table named `Array`.** When no model matched, the table list itself was stringified into the query, producing `SQLSTATE[42S02]` rather than a clean null.
- **The public download endpoint would not take a `public_id`**, only the internal identifier.

---
## Improvements
- Added a safe user deletion console command.
- Fixed the Sentry configuration probe's validation, and restored its coverage gate.

---
## Continuous Integration
- PHP CI and the Postman contract now run on `dev-v*` release branches, so work merged into a release branch is tested before the release PR.
- The contract run tests this branch's API code rather than the published package.
- Removed a second-boundary race in the API credential expiry test.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
