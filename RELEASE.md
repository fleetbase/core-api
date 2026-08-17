> v1.6.58 ~ "Release images install without requiring a live database"

---
## Highlights
Core API no longer opens a MySQL connection while Composer discovers Laravel packages. Fresh Fleetbase images can install their dependencies before the database service exists.

---
## Bug Fixes
- Resolved the database-backed user-deletion service only when `fleetbase:user-delete` is actually executed.
- Prevented Laravel's console command discovery from constructing a spatial MySQL connection during `composer install` and `composer dumpautoload`.
- Added regression coverage proving the command can be registered and resolved while its database service is unavailable.

---
## Upgrade Steps
No migration or configuration change is required.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
