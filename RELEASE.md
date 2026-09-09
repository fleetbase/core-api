# v1.6.61 — Faster IAM user authorization loading

## Improvements

- Reduce repeated database queries when listing IAM users by loading roles, policies, and permissions in batches and reading each user's primary role once.
- Match authorization to each user's company membership, including users belonging to multiple companies and system administrators viewing users across companies. User response fields remain unchanged.

## Reliability

- Add database-backed coverage for company isolation, missing and deleted memberships, recovery after a membership was initially absent, and matching responses between lazy and eager loading.
- Enable PHP CI and Postman checks for `release/v*` branches and support release tagging from both `release/v*` and `dev-v*` branches.

No database migration or configuration change is required.

Changes: [#251](https://github.com/fleetbase/core-api/pull/251), [#250](https://github.com/fleetbase/core-api/pull/250), and release-branch CI updates in [#252](https://github.com/fleetbase/core-api/pull/252).
