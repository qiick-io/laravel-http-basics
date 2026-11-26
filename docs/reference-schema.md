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
| `id` | bigint PK | Internal only |
| `public_id` | `uuid` null, unique | UUIDv7 stamped with the claim time and exposed as the operation ID. A takeover gets a new one. Erasure clears it when anonymising a row. |
| `bucket` | `varchar(128)` null, binary NO PAD collation on MySQL (`utf8mb4_0900_bin`, MySQL 8.0+) and MariaDB (`utf8mb4_nopad_bin`) | Server-side credential or anonymous bucket, compared byte for byte on every engine. Buckets over 128 characters become `sha256:<hex>`. Null detaches an anonymised row from replay. |
| `user_id` | `varchar(255)` null, indexed | Authenticated user's key, without a user-table dependency. Uses the same comparison as `bucket`. |
| `organisation_id` | `varchar(255)` null | Server-resolved scope for erasure, with the same comparison as `bucket`. Null marks an unscoped claim. |
| `bucket_version` | `smallint`, default `1` | Versions the bucket scheme (anonymous HMAC components) |
| `credential_public_id` | `uuid` null, indexed | Canonical lowercase principal or key UUID from `IdempotencyContext::$credentialPublicId`. |
| `key_hash` | `varchar(64)` | SHA-256 of the client key |
| `key_hash_algorithm` | `varchar(32)`, default `sha256` | |
| `key_format` | `varchar(16)` | `uuid` or `sf-string`, from the claim context |
| `method` | `varchar(16)` | HTTP method |
| `path` | `varchar(2048)` | Raw `getPathInfo()` |
| `fingerprint` | `varchar(64)` | Request fingerprint (method, path+query, bucket, body, served locale on machine doors) |
| `fingerprint_algorithm` | `varchar(32)`, default `sha256` | |
| `fingerprint_version` | `smallint`, default `2` | `IdempotencyContext::$fingerprintVersion` (`Fingerprinter::VERSION`); 2 = today's fingerprint material, with the raw query read as a form |
| `lease` | `varchar(64)` | Opaque owner token (NOT NULL) |
| `status` | `varchar(32)`, default `in_progress` | `in_progress` / `completed` |
| `status_code` | `smallint` null | Completed replayable responses only |
| `headers_json` | `text` null | Allowlisted replay headers (`Idempotent-Replayed` is never stored) |
| `body` | `longText` null | Capped by `max_response_bytes` (request bodies use `max_body_bytes`) |
| `body_encoding` | `varchar(16)`, default `utf-8` | Leaves room for `base64` |
| `request_id` | `varchar(128)` null | `X-Request-Id` of the claiming request; null when the id does not match the request-id pattern |
| `trace_id` | `char(32)` null, indexed | W3C trace-id, lowercase hex; empty until a trace-context module fills it (indexed now so 1.0 needs no later index on a hot table) |
| `metadata` | `jsonb` null (`json` on MySQL/MariaDB) | Namespaced keys, plain values, at most 4 KiB; never secrets, never `tracestate` |
| `schema_version` | `smallint`, default `1` | Row format, including how `headers_json` is serialised |
| `completed_at` | timestamp null | Set when the response is stored or the key sealed (UTC) |
| `created_at`, `updated_at` | timestamp null | Laravel defaults; store writes UTC |
| `expires_at` | `datetime` | Future-dated (C11 `dateTime`); enforced on read; index for prune |

`key_hash`, `fingerprint` and `lease` are `varchar(64)`, not the `char(64)` of the
standards review: PostgreSQL right-pads `CHAR` values shorter than the column, so
equality comparisons on them break. Every value the store writes is exactly 64
characters, so nothing else changes.

**Unique** `(bucket, key_hash)`. **Index** `expires_at`.

Completed rows with `body` and `status_code` both null are **unreplayable**: the
handler ran but the response could not be stored (or exceeded `max_response_bytes`).
Retries return `409 idempotency_not_replayable` and never re-execute the handler.

Schedule prune via `php artisan http-basics:prune-idempotency` or Laravel's
`model:prune` on `Qiick\HttpBasics\Idempotency\IdempotencyRecord`.
