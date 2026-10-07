# v1.6.69 — Authenticated realtime channels, private media, database backups and hashed codes

## Added

- **Authenticated realtime channels.** Socket tokens, a channel authorizer with per-resource resolvers, and signed HTTP publish. `POST int/v1/socket/token` mints a user token, and `POST int/v1/socket/authorize` is called only by the socket server, with signed requests. Extensions register channel resolvers for their own resources. It is **off by default** and stays off until `SOCKETCLUSTER_AUTH_ENABLED=true`, even with a key set, so existing socket clients (mobile apps, the console, integrations) keep working. (#290)
- **Database backups.** Settings-driven backups (`db:backup`) on a configurable schedule, with environment defaults in `config/database-backups.php` (`DB_BACKUP_*`) and an admin override. Failures email the configured addresses and are logged. (#288)
- **Hashed one-time codes.** `VerificationCode::issue()` stores an HMAC of the code and returns the plain code once. `check()` counts attempts and reports `valid`, `invalid`, `expired` or `locked`. The existing generators are unchanged. (#289)

## Changed

- **Private media buckets.** Stored file URLs that point into the configured `s3` bucket are signed again on read, so the bucket can be fully private. (#287)
- **Faster lookups.** The country lookup is cached, and `files.subject_uuid` is indexed. (#291)
- The admin SocketCluster test always publishes to `test.{current user uuid}` and returns the channel it used. (#290)

## Removed

- `MysqlS3Backup`, `S3BackupTrimmer` and `config/laravel-mysql-s3-backup.php`, replaced by the new database backups. (#288)

## Upgrade Steps

- Run migrations: `database_backups` table (#288) and the `files.subject_uuid` index (#291).
- Socket auth (#290) adds `SOCKETCLUSTER_AUTH_ENABLED` (default `false`), `SOCKETCLUSTER_AUTH_KEY`, `SOCKETCLUSTER_PUBLISH_URL` (default `http://{SOCKETCLUSTER_HOST}:8001`) and `SOCKETCLUSTER_TOKEN_TTL` (default 900) to `broadcasting.connections.socketcluster`. Nothing changes for socket clients until `SOCKETCLUSTER_AUTH_ENABLED=true`. Roll out in this order:
  1. Ship clients that fall back to connecting without a token when the token route answers 404.
  2. Set `SOCKETCLUSTER_AUTH_ENABLED=true` on the API and the socket server, with the socket server in `log` mode.
  3. Switch the socket server to `enforce`.
- To make the media bucket private, remove any public `s3:GetObject` statement from the bucket policy and turn on Block Public Access (#287).
- Database backups replace the old S3 backup settings; configure them with `DB_BACKUP_*` or in the admin settings (#288).
- fleetbase/storefront v0.4.25 and fleetbase/fleetops#358 require this release (`fleetbase/core-api ^1.6.69`).
