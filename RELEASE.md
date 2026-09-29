# v1.6.66 — Per-consumer API rate limiting, admin rate-limit controls and API consumer metrics

## Fixes

- One API consumer can no longer rate-limit the whole platform. `ThrottleRequests` ran before API authentication, so Laravel keyed every bucket on the client IP. Behind a load balancer that is the balancer's IP, so every tenant, API key and console visitor shared a single 120/min bucket, and one busy integration returned 429 to everyone. The limiter now keys on the presented credential (API key, Sanctum token or basic auth), falling back to the user and then the IP only when none is sent. The first path segment keeps `/v1` and `/int` in separate buckets. (#280)
- 429 responses keep `Retry-After` and `X-RateLimit-*`. The exception handler used to drop them, so throttled clients could not back off correctly. (#280)

## Improvements

- System admins can manage API rate limits at runtime. The limits (`THROTTLE_*` environment defaults) can be overridden from the console and stored as the `system.rate-limits` setting: enable/disable, requests per window, and window length. **Per-organization overrides** give an organization a custom limit or none. New admin-only endpoints `GET/POST/DELETE int/v1/rate-limits/settings`. (#281)
- API consumer metrics. The throttle middleware counts every request and every 429 per consumer in Redis: minute buckets are kept for 2 hours, hour buckets for 8 days, at one pipelined round trip per request. `GET int/v1/rate-limits/consumers?window=&sort=&limit=` lists the busiest or most throttled consumers with their organization, masked key, scope, IP, avg and peak per minute, and share. `POST int/v1/rate-limits/consumers/{signature}/reset` clears one consumer's window. Tracking can be disabled with `THROTTLE_TRACK_CONSUMERS=false`; `THROTTLE_METRICS_REDIS_CONNECTION` picks the Redis connection (default `cache`). (#281)
