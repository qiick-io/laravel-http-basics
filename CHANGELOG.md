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

- Idempotency: on machine doors the fingerprint includes the locale the response is
  served in, not the raw `Accept-Language` header, so `it-CH,it;q=0.9` and `it` replay the
  same response, and so do an unsupported `fr`, no header and `en` when `en` is the
  default, instead of failing with `idempotency_payload_mismatch`.

### Fixed

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

- Uploads: `UploadValidator`, the `SafeUpload` rule, `HardFloor`, `FileNameSanitizer`,
  per-context `UploadPolicy` from `http-basics.uploads.contexts`, and the `MalwareScanner`
  seam. Archives are refused unless a context sets `allows_archives`; an allowed ZIP is
  inflated in streaming against its budget, and `ZipLayoutVerifier` requires every local
  header at its central-directory offset with no byte unaccounted for, data descriptors
  that match the directory, and a single end record; stored entries with a data
  descriptor and symbolic link entries are refused. The package checks structure only:
  products must never extract uploads with streaming readers (see the README).

- Errors: design §3.5 fleet helpers — `ErrorCodeResolver` /
  `FrameworkErrorCodeResolver`, `ErrorCodeAugmentor` (JSON ≥400 only, never
  overwrites `error`), `ExceptionRespondBinder`, `ExceptionErrorCodeAugmenter`,
  and `MachineDoorJson::whenCallback` (Illuminate-only; no Foundation types).
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
