> v2.0.5 ~ "App Review types that share a model"

# v2.0.5 — App Review types that share a model

## Fixed

- **A second App Review type on the same model is read and changed as itself.** `AppReview::typeOf()` returns the first registered type whose model matches. Fleet-Ops registers an organization's Navigator app on `Company`, so any other type on `Company` (such as Fleet-Ops' customer apps) was always read, changed and checked as Navigator's.
  - `accessFor()`, `isEnabledFor()`, `acceptsCode()`, `update()` and `status()` take an optional `?string $type` as their last argument. Left out, it is `typeOf($subject)`, so existing callers behave as before.
  - A type registered for a different model never applies: `acceptsCode()` and `isEnabledFor()` answer false, `status()` reports no type, and `update()` throws.
  - New `AppReview::resolveType($subject, $type)` gives the type a subject is checked as.
  - The Console endpoint (`/int/v1/review-access/{type}/{id}`) and the OAuth endpoint (`/oauth/v1/review-access/{type}/{id}`) pass the route's `{type}` through, so each type keeps its own number, code and access row.

## Upgrade Steps

- No migration. Extensions that register more than one type on the same model must pass the type to the calls above.
