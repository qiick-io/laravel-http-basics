# Changelog

All notable changes to **laravel-http-basics** (`qiick/laravel-http-basics`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed

- CORS: the `http-basics.cors.validate_on_boot` switch (`HTTP_BASICS_CORS_VALIDATE_ON_BOOT`).
  Boot validation of `config('cors')` now always runs.

### Fixed

- CORS: with `supports_credentials`, boot now rejects `allowed_headers: ['*']`, which
  Fruitcake answers by echoing every requested header (`Authorization` included).

- Idempotency: rebase onto security-headers re-review tip; strip blanket
  `@pest-mutate-ignore` and keep only proven equivalents with one-line reasons;
  raise per-class mutation score to 100% for the six design §8 targets.

### Added

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
