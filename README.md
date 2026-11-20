<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/header-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/header-light.svg">
    <img alt="laravel-http-basics: Shared HTTP runtime for qiick Laravel products" src=".github/assets/header-light.svg" width="100%">
  </picture>
</p>

# qiick/laravel-http-basics

> **In development. Not released and not on Packagist yet: do not depend on it.**

Shared HTTP runtime for qiick Laravel products: trusted `X-Request-Id`,
Accept-Language / `Content-Language` negotiation, configurable security headers,
and `Idempotency-Key` (middleware + store). Thin helpers for fleet `error`
augmentation and machine-door JSON classification — without owning product error
enums.

Part of the qiick fleet. Binding design:
`projects/qiick/packages/laravel-http-basics-design.md` in the knowledge base
(overlays: `decisions/2026-11-20-fleet-contracts.md`,
`decisions/2026-11-20-core-hardening.md`, `conventions/standards-register.md`).

## Status

Pre-`0.1.0`. Public APIs may change in any 0.x release; see [UPGRADING.md](./UPGRADING.md).
Do not require this package from production apps until a tagged release lands on
Packagist (Packagist publish only after Luca’s go).

## Requirements

- PHP `^8.3`
- Illuminate cache / contracts / http / support `^12.0 \|\| ^13.0` (never
  `laravel/framework`)

## Install (when published)

```bash
composer require qiick/laravel-http-basics
php artisan vendor:publish --tag=http-basics-config
```

Register request-id, locale and security middleware **globally**. Idempotency is
per-route after throttle / auth / scope / cost. Products wire
`ExceptionErrorCodeAugmenter` through their own bootstrap (no Foundation types in
this package).

## Threat model (short)

| Topic | Contract |
| --- | --- |
| Log injection / header smuggling | Charset + single-value `X-Request-Id`; provenance in hidden Context only (`generated` / `trusted_proxy` / `client`). |
| Correlation spoofing | Default inbound `accept_from=trusted_proxy`; outbound host allowlist empty by default (no propagation). |
| Cross-credential replay | Server-side credential bucket + owner lease; unique `(bucket, key_hash)`; never a request-controlled bucket. |
| Anonymous bucket | Best-effort de-duplication, **not** an identity boundary; HMAC secret rotatable (rotation invalidates anon records); depends on TrustProxies. |
| Secret replay | Route `replayable=false` + header deny-list + text/JSON only; API-key issuance must be non-replayable. |
| Retry storms / double execution | 409 while lock held; lease-conditional complete; fail closed on marker eviction. |
| Cache poisoning | `Vary: Accept-Language` when negotiated from the header; authenticated responses documented `Cache-Control: private`. |
| Proxy trust | Same rule as api-keys IP source — `Request::ip()` behind configured TrustProxies only. |

## CORS (fleet)

Products that expose these headers to browsers must list them in CORS
`exposed_headers`: `X-Request-Id`, `X-RateLimit-*`, `Retry-After`, `Deprecation`,
`Sunset`, `Content-Language`, and `Vary` (see fleet contracts).

## Development

```bash
composer update
composer test      # Pest
composer lint      # Pint
composer analyse   # PHPStan level max
```

No `composer.lock` is committed (library). CI resolves with `--prefer-lowest` and
stable across PHP 8.3/8.4/8.5 × Laravel 12/13. Mutation targets from the design
run with `pest --mutate` (per-class MSI ≥ 95%).

## Security

Private vulnerability reporting: see [SECURITY.md](./SECURITY.md).

## License

MIT. See [LICENSE](./LICENSE).
