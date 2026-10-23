# v1.6.74 — Webhook endpoints for API keys, signed per endpoint, and safer upload names

## Added

- **Webhook endpoints can be managed with an API key** (#319). `/v1/webhook-endpoints` supports create, list (optionally filtered by `url`), show, update, `POST {id}/rotate-secret` and delete. Endpoints are scoped to the key's company and environment (`live`/`test`). The URL must pass `PublicWebhookUrl`, and events are checked against `config('api.events')` when it is set. Integrations such as WooCommerce and Shopify connectors can now register their own endpoints instead of merchants copying URLs into the console.
- **Per-endpoint signing secrets** (#319). Each endpoint has its own secret (`whsec_…`), stored encrypted, hidden from serialisation and kept out of the activity log. Endpoints that already exist get one the first time it is needed. Deliveries now also carry `X-Fleetbase-Signature: t=<unix>,v1=<hmac_sha256("<t>.<body>", secret)>`; `EndpointSignature::verify()` documents the receiver side. The legacy `Signature` header is unchanged. The console can reveal (`GET int/v1/webhook-endpoints/{id}/secret`, view permission) and rotate (`POST int/v1/webhook-endpoints/{id}/rotate-secret`, create permission) the secret.

## Security

- **Uploaded files are stored under an extension that matches their contents** (#320). The stored name used to keep the client's extension, so a file that passed content-type validation could be stored as `.php`. The client's extension is now kept only when it matches the detected type (or is a loosely detected data format such as `csv`, `json` or `xlsx`); otherwise the extension comes from the detected type. Script extensions (`php`, `phtml`, `phar`, `shtml`, `htaccess`, `cgi`, …) are never used and become `bin`. `original_filename` is unchanged.

## Upgrade Steps

- Run migrations: `php artisan migrate` adds the nullable `webhook_endpoints.secret` column.
