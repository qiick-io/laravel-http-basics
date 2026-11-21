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

### Request id (landed)

```php
use Qiick\HttpBasics\RequestId\AssignRequestIdMiddleware;
use Qiick\HttpBasics\RequestId\RequestIdPropagator;

// bootstrap/app.php — OUTERMOST global middleware (prepend), so 401/404/405/419/429/503,
// maintenance and CORS preflight responses carry X-Request-Id.
$middleware->prepend(AssignRequestIdMiddleware::class);

// Outbound (only when http-basics.request_id.outbound.allowed_hosts is set)
app(RequestIdPropagator::class)->applyTo($pending, $url);
```

| Config / env | Default | Meaning |
| --- | --- | --- |
| `HTTP_BASICS_REQUEST_ID_ACCEPT_FROM` | `trusted_proxy` | Keep inbound id only from a trusted proxy hop (`any` / `never` also valid). |
| `HTTP_BASICS_TRUST_WILDCARD_PROXIES` | `false` | When TrustProxies is `*` / `**` (Cloud/Forge/Vapor), still reject client ids unless true. |
| `HTTP_BASICS_OUTBOUND_HOSTS` | empty | Comma-separated `host` or `host:port`; empty = never propagate; `*` = all hosts. |
| `http-basics.request_id.accept_pattern` | `RequestId::PATTERN` | Must require ≥1 alphanumeric; punctuation-only tokens are rejected. |

The id is stored in **visible** Context as `request_id` (Laravel logs it and queued jobs inherit it). Provenance (`generated` / `trusted_proxy` / `client`) stays in hidden Context.

Trust is computed by `ConfigTrustedProxyDetector` from the same sources as TrustProxies (`TrustProxies::at()` and `trustedproxy.proxies`) via `IpUtils::checkIp` on `REMOTE_ADDR`. It does **not** call `$request->isFromTrustedProxy()`, so middleware order and Octane static request state cannot flip the decision.

## Threat model (short)

| Topic | Contract |
| --- | --- |
| Log injection / header smuggling | Charset + single-value `X-Request-Id` (≥1 alphanumeric); provenance in hidden Context only (`generated` / `trusted_proxy` / `client`). |
| Correlation spoofing | Default inbound `accept_from=trusted_proxy`; outbound host allowlist empty by default (no propagation). |
| Wildcard TrustProxies (`*` / `**`) | Common on Cloud/Forge/Vapor. Client ids are **not** trusted under `*` unless `trust_wildcard_proxies=true` (default false). |
| Outbound leak | Allowlist decides at send time from the real URI; cross-host/port redirects strip `X-Request-Id`; caller-supplied header wins (no duplicate). |
| Cross-credential replay | Server-side credential bucket + owner lease; unique `(bucket, key_hash)`; never a request-controlled bucket. |
| Anonymous bucket | Best-effort de-duplication, **not** an identity boundary; HMAC secret rotatable (rotation invalidates anon records); depends on TrustProxies. |
| Secret replay | Route `replayable=false` + header deny-list + text/JSON only; API-key issuance must be non-replayable. |
| Retry storms / double execution | 409 while lock held; lease-conditional complete; fail closed on marker eviction. |
| Cache poisoning | `Vary: Accept-Language` when negotiated from the header; authenticated responses documented `Cache-Control: private`. |
| Proxy trust | Same configured proxies as TrustProxies, evaluated independently of middleware order / request static state. |

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
