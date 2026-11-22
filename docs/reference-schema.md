# Reference schema: idempotency records

Optional database store for `Idempotency-Key`. Publish the package migration:

```bash
php artisan vendor:publish --tag=http-basics-migrations
php artisan migrate
```

The migration is **not** auto-loaded (cache store remains the default). Until `1.0.0`
the create migration is edited in place. Tests load the same migration.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `bucket` | `varchar(128)` | Server-side credential / anon bucket |
| `key_hash` | `char(64)` | SHA-256 of the client key |
| `method` | `varchar(16)` | HTTP method |
| `path` | `varchar(2048)` | Raw `getPathInfo()` |
| `fingerprint` | `char(64)` | Request fingerprint (method, path+query, bucket, body, optional Accept-Language) |
| `lease` | `char(64)` | Opaque owner token (NOT NULL) |
| `status` | `varchar(32)` | `in_progress` / `completed` |
| `status_code` | `smallint` null | Completed replayable responses only |
| `headers_json` | `text` null | Allowlisted replay headers (`Idempotent-Replayed` is never stored) |
| `body` | `longText` null | Capped by `max_response_bytes` (request bodies use `max_body_bytes`) |
| `created_at`, `updated_at` | timestamp null | Laravel defaults; store writes UTC |
| `expires_at` | `datetime` | Future-dated (C11 `dateTime`); enforced on read; index for prune |

**Unique** `(bucket, key_hash)`. **Index** `expires_at`.

Completed rows with `body` and `status_code` both null are **unreplayable**: the
handler ran but the response could not be stored (or exceeded `max_response_bytes`).
Retries return `409 idempotency_not_replayable` and never re-execute the handler.

Schedule prune via `php artisan http-basics:prune-idempotency` or Laravel's
`model:prune` on `Qiick\HttpBasics\Idempotency\IdempotencyRecord`.
