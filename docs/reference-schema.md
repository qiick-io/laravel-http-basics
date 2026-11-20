# Reference schema: idempotency records

Optional database store for `Idempotency-Key`. The package does **not** publish
migrations; products that choose the DB adapter apply an equivalent schema (or
use the cache + lock store).

| Column | Type | Notes |
| --- | --- | --- |
| `bucket` | `varchar(128)` | Server-side credential / anon bucket |
| `key_hash` | `char(64)` | SHA-256 of the client key |
| `method` | `varchar(8)` | HTTP method |
| `path` | `varchar(512)` | Raw `getPathInfo()` |
| `fingerprint` | `char(64)` | Request fingerprint |
| `lease` | `char(64)` | Opaque owner token |
| `status` | enum `in_progress` / `completed` | |
| `status_code` | `smallint` null | Completed responses only |
| `headers_json` | `text` null | Allowlisted replay headers |
| `body` | `text` null | Capped by `max_body_bytes` |
| `created_at` | datetime | |
| `updated_at` | datetime | |
| `expires_at` | datetime | Index `(expires_at)` |

**Unique** `(bucket, key_hash)`.
