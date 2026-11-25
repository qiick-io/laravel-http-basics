# Changelog

All notable changes to **laravel-http-basics** (`qiick/laravel-http-basics`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed

- CORS: the `http-basics.cors.validate_on_boot` switch (`HTTP_BASICS_CORS_VALIDATE_ON_BOOT`).
  Boot validation of `config('cors')` now always runs.

### Changed

- Idempotency: replayed response headers keep their values but are named in lower case
  (they come from `HeaderBag::all()`); HTTP header names are case-insensitive.

- Config: a boolean `http-basics.*` value set to `null` now takes the package's shipped
  default for that key instead of being read as `false`. Any other value of the wrong
  type also falls back to the shipped default, through the internal `PackageConfig`.
  The shipped defaults are constants in the package, never the environment read again,
  so a blank or `null` `HTTP_BASICS_X_FRAME_OPTIONS`, `HTTP_BASICS_REFERRER_POLICY` or
  `HTTP_BASICS_PERMISSIONS_POLICY` still sends the safe header.

- Config: a present but invalid `http-basics.security.csp.mode` or
  `http-basics.request_id.accept_from` now fails at boot, like the other enums, instead
  of on the first request that needs it. A missing key still takes its default.

- Config: with anonymous idempotency enabled, a missing
  `http-basics.idempotency.anonymous.components` now takes the shipped
  `ip, user_agent, route` instead of failing at boot; a value that is not a list still
  fails. A missing `http-basics.locale.machine_doors` now gives the security headers the
  same doors as the locale classifier (`api`, `api/*`, `mcp`, `mcp/*`), so `/api` itself
  counts as a machine door.

- Idempotency: cache-store records carry a payload version `v` (1), as the database row
  carries `schema_version`. A record without `v` reads as version 1; a record of any other
  version answers `idempotency_not_replayable`, so a rollback never replays or re-runs a
  format it cannot read (standards review S10).

- Idempotency (breaking for custom stores): `IdempotencyStore::claim()` takes an
  `IdempotencyContext` (key format, fingerprint version, request id, trace id,
  credential public id, metadata), filled by the middleware. The idempotency migration
  gains `public_id`, `bucket_version`, `credential_public_id`, `key_hash_algorithm`,
  `key_format`, `fingerprint_algorithm`, `fingerprint_version`, `body_encoding`,
  `request_id`, `trace_id`, `metadata`, `schema_version` and `completed_at` (standards
  review M4/M5). Pre-1.0 the create migration is edited in place: re-run it on a fresh
  database. A takeover of an expired or stale row gets a new `public_id` and the new
  operation's method, path and start time; `bucket` uses a binary NO PAD collation on MySQL and
  MariaDB, so it compares byte for byte (trailing spaces and letter case included),
  and a bucket over 128 characters is stored as its SHA-256.

- Idempotency: on machine doors the fingerprint includes the locale the response is
  served in, not the raw `Accept-Language` header, so `it-CH,it;q=0.9` and `it` replay the
  same response, and so do an unsupported `fr`, no header and `en` when `en` is the
  default, instead of failing with `idempotency_payload_mismatch`.

### Fixed

- Idempotency: the quoted RFC 9651 form the draft sends
  (`Idempotency-Key: "8e03…"`, draft-ietf-httpapi-idempotency-key-header-09) is accepted
  in both key formats, unquoted and unescaped; `"abc"` and `abc` are the same key, and a
  `uuid` key must still be a UUID once unquoted. `IdempotencyKeyValidator` gains
  `canonicalKey()`.

- Idempotency: the query fingerprint reads `+` as a space and `%2B` as a plus, as PHP
  and Symfony do, so `?q=a+b` and `?q=a%2Bb` no longer replay each other's response.
  `Fingerprinter::VERSION` is now 2 (the `fingerprint_version` default too). A key
  claimed before the deploy and retried after it with `+` or `%2B` in its query gets
  `idempotency_payload_mismatch` (422) until its record expires (`ttl_seconds`).

- Idempotency: `require_json` accepts `application/<name>+json` only with a non-empty RFC
  6838 name before `+json` (`application/+json` is now 415), and the English and Italian
  415 messages name both accepted media types.

- Idempotency: a lock backend failure (Redis down mid-request) is a 503 store failure in
  both stores, a lock that cannot be released no longer turns a finished claim into an
  error, a failing `abandon()` never replaces the handler's response, and the `:sealed`
  marker is checked again once the lock is held.

- Idempotency: every cache failure in `CacheIdempotencyStore` now surfaces as
  `idempotency_unavailable` (503 with `Retry-After`) instead of a raw exception, an
  in-progress marker the cache did not store refuses the claim instead of letting the
  handler run unprotected, and `abandon()` is best effort like the database store.

- Idempotency: middleware options follow a strict grammar. Each option is trimmed (Laravel
  keeps the space in `:required, replayable=false`), keys are exact and case-sensitive,
  `replayable=` takes only `true` or `false` (any letter case), and an unknown, misspelled
  or repeated option throws. Before, `replayable=0`, `Replayable=false` or `replayble=false`
  left the route replayable and replayed the first response of a secret-issuing route.

- Idempotency: when the response and then its seal both fail to persist, the key is
  sealed through a separate `:sealed` cache entry, so a retry after
  `stale_after_seconds` answers `idempotency_not_replayable` instead of running the
  handler again; the cache store no longer reports a seal it could not write.

- CORS: with `supports_credentials`, boot now rejects `allowed_headers: ['*']`, which
  Fruitcake answers by echoing every requested header (`Authorization` included).

- Idempotency: the database store, `IdempotencyRecord::prunable()` and
  `http-basics:prune-idempotency` now read the UTC `expires_at` / `updated_at` columns
  as UTC. Under a non-UTC `app.timezone` (Europe/Rome) completed keys expired, and were
  pruned, up to the UTC offset early, so a retry inside the TTL re-ran the handler.

- Idempotency: `IdempotencyRecord` (`model:prune`) and `http-basics:prune-idempotency`
  now use `http-basics.idempotency.database.connection`, the connection the store writes
  to, instead of always the default connection.

- Idempotency: no `@pest-mutate-ignore` is left in `src/Idempotency`; every class in
  `scripts/mutate-per-class.sh` reaches its real per-class score of at least 95%.

- Idempotency: an exception thrown by the route handler, `IdempotencyStoreException`
  included, now always abandons the claim, so a retry can run it; before, a
  handler-thrown `IdempotencyStoreException` left the key locked until `lock_ttl_seconds`.

- Idempotency: a completed record whose `status_code` cannot be read is answered
  `idempotency_not_replayable` instead of being replayed as a guessed `200`, in the
  database, cache and in-memory stores alike.

- Idempotency: when the handler throws and abandoning the claim fails too (cache down),
  the handler's exception is the one rethrown.

- Idempotency: `IdempotencyRecord::on()` / `setConnection()` win over the configured
  idempotency connection.

### Added

- Security headers: strict CSP with a per-request nonce (`HTTP_BASICS_CSP_NONCE`). The
  built `script-src` becomes `'self' 'nonce-…' 'strict-dynamic'` (no `'unsafe-eval'`),
  `SetSecurityHeadersMiddleware` hands the nonce to `Vite::useCspNonce()` before the
  handler runs, and `security.csp.script_hashes` adds hashes of allowed inline scripts,
  validated at boot (a list of hashes, only with the nonce on). New `CspNonceProvider` contract and `RequestCspNonceProvider`
  (128 random bits per request). Standards review S2.

- Uploads: `UploadValidator`, the `SafeUpload` rule, `HardFloor`, `FileNameSanitizer`,
  per-context `UploadPolicy` from `http-basics.uploads.contexts`, and the `MalwareScanner`
  seam. Archives are refused unless a context sets `allows_archives`; an allowed ZIP is
  inflated in streaming against its budget, and `ZipLayoutVerifier` requires every local
  header at its central-directory offset with no byte unaccounted for, data descriptors
  that match the directory, and a single end record; stored entries with a data
  descriptor and symbolic link entries are refused. The package checks structure only:
  products must never extract uploads with streaming readers (see the README).

- Errors: optional RFC 9457 problem details (`http-basics.errors.format=problem_details`).
  `ProblemDetailsErrorRenderer` and a container-bound `ExceptionErrorCodeAugmenter` render
  every machine-door error response, HTML error pages included, as
  `application/problem+json`, keeping the fleet members as extension members; golden
  tests pin the wire shapes.

- Errors: design §3.5 fleet helpers — single `ErrorCode` backed enum (framework,
  `idempotency_*` and `upload_*` codes) with
  `docs/error-codes.md` catalogue (Pest fails on drift), `ErrorCodeResolver` /
  `FrameworkErrorCodeResolver`, `ErrorCodeAugmentor` (JSON ≥400 only, never
  overwrites `error`), `ExceptionRespondBinder`, `ExceptionErrorCodeAugmenter`,
  and `MachineDoorJson::whenCallback` (Illuminate-only; no Foundation types).
  `FrameworkErrorCodeResolver` keeps an `IdempotencyRejection`'s own code, and a
  Pest test fails when an exception the package lets escape has no documented code.
- Idempotency: `HandleIdempotencyMiddleware`, `Fingerprinter`, `CacheIdempotencyStore`,
  `DatabaseIdempotencyStore`, `AnonymousBucketResolver`, `FleetErrorRenderer`,
  `IdempotencyRejection`, contract seams (`CredentialBucketResolver`, `IdempotencyStore`,
  `ErrorRenderer`), and testing helpers (`FixedClock`, `InMemoryIdempotencyStore`,
  `FakeCredentialBucketResolver`, `assertIdempotentReplay`, `assertNoSensitiveBucketData`).
  Per-route replay of 2xx/422 (not 429/5xx/Set-Cookie/streams); server-side buckets only;
  boot validation for TTLs, key format, anon secret length, and lock/handler bounds.
- Security headers: `SecurityHeaderBuilder`, `SetSecurityHeadersMiddleware`,
  `SecurityHeadersPolicy`, `CspMode`, `CspPolicyBuilder` /
  `ViteFriendlyCspPolicyBuilder`, and `assertSecurityHeaders`. Never clobbers
  existing HSTS/XFO/Referrer/Permissions/nosniff; if either CSP or CSP-RO is
  already present, neither CSP header is added; machine doors get baseline
  only; HSTS only when `max_age>0` and the request is secure; CSP `report-uri`
  is appended to the built/override string before set; install with global
  prepend so maintenance 503 carries the headers. Vite hot origin is
  feature-detected via `devServerUrl()` (Laravel 13+; skipped on 12).
- Locale negotiation: `LocaleNegotiator`, `SetLocaleMiddleware`,
  `MachineDoorClassifier`, `SupportedLocales` / `DefaultLocaleResolver` seams,
  and testing helpers (`ArraySupportedLocales`, `FixedDefaultLocaleResolver`,
  `assertContentLanguage`, `assertVaryAcceptLanguage`). Machine doors honour
  capped Accept-Language; web ignores it; `Content-Language` on responses;
  `Vary` when Accept-Language was read; exact locale beats prefix; install on
  `web` / `api` groups (optional global prepend for maintenance 503).
- Request id: `RequestId`, `RequestIdResolver`, `AssignRequestIdMiddleware`,
  `RequestIdPropagator`, `IdGenerator` / `TrustedProxyDetector` seams, and
  testing doubles (`FixedIdGenerator`, trusted-proxy detectors,
  `assertRequestIdEchoed`). Default `accept_from=trusted_proxy`; outbound
  allowlist empty (no propagation) until configured.
- Package scaffold: auto-discovered `HttpBasicsServiceProvider`, publishable
  `config/http-basics.php` (tag `http-basics-config`), Pest + Testbench, Pint,
  PHPStan level max, qiick standards pack pinned to app-ui `v0.12.0`, CI matrix
  (PHP 8.3/8.4/8.5 × Laravel 12/13 × lowest/stable) with `composer validate
  --strict`, `composer audit`, SHA-pinned Actions, and README header banners.

[Unreleased]: https://github.com/qiick-io/laravel-http-basics/compare/55a3690750baca3d6468a7465d606d8fe8a4e6c0...HEAD
