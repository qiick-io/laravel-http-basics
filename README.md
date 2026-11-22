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

### Locale (landed)

Locale cookie and user preference only work **after** `EncryptCookies` +
`StartSession`. Register on the `web` and `api` groups (Laravel 12 / 13
`bootstrap/app.php`). Prepend globally as well when maintenance / early errors
must carry `Content-Language`.

```php
use Qiick\HttpBasics\Locale\SetLocaleMiddleware;
use Qiick\HttpBasics\Locale\LocaleNegotiator;

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    // Web: after EncryptCookies + StartSession (group defaults) so cookie / user work.
    $middleware->web(append: [
        SetLocaleMiddleware::class,
    ]);

    // API / machine doors: Accept-Language negotiation only (no cookie).
    $middleware->api(append: [
        SetLocaleMiddleware::class,
    ]);

    // Optional but recommended: Content-Language on maintenance 503 and errors
    // before group middleware (same reason AssignRequestId is prepended).
    $middleware->prepend(SetLocaleMiddleware::class);
})

// After API-key / principal auth (inside the stack, before Content-Language):
app(LocaleNegotiator::class)->applyPrincipalLocale($request, $principal->locale);
```

Default machine doors include `api` and `api/*` (so `/api` itself negotiates).
`Vary: Accept-Language` is set whenever that header was **read** on a machine
door, not only when it won.

| Config / env | Default | Meaning |
| --- | --- | --- |
| `HTTP_BASICS_SUPPORTED_LOCALES` | empty → app locale + fallback | Comma-separated supported tags (case-sensitive). |
| `HTTP_BASICS_LOCALE_MAX_HEADER_BYTES` | `1024` | Oversize `Accept-Language` is ignored (fall through). |
| `HTTP_BASICS_LOCALE_MAX_LANGUAGES` | `20` | Too many ranges (counted before dedupe) → treat header as absent. |

### Security headers (landed)

Register **globally with prepend** (same reason as request-id / locale) so
maintenance 503 and early errors still get the defense headers.

```php
use Qiick\HttpBasics\Security\SetSecurityHeadersMiddleware;

// bootstrap/app.php — prepend globally so 401/404/405/419/429/503 and
// maintenance responses carry nosniff / Referrer / Permissions (and web XFO +
// CSP). Machine doors stay baseline-only.
$middleware->prepend(SetSecurityHeadersMiddleware::class);
```

| Config / env | Default | Meaning |
| --- | --- | --- |
| `HTTP_BASICS_SECURITY_ENABLED` | `true` | Master switch. |
| `HTTP_BASICS_CSP_MODE` | `enforce` | `enforce` / `report-only` / `off` / `both`. |
| `HTTP_BASICS_CSP` | empty → Vite-friendly default | Full CSP override (verbatim). |
| `HTTP_BASICS_CSP_REPORT_URI` | empty | Appended to the built/override CSP string before set. |
| `HTTP_BASICS_HSTS_MAX_AGE` | `0` | HSTS only when greater than 0 and the request is HTTPS; proxy usually owns this. |
| `HTTP_BASICS_BASELINE_ON_API` | `true` | Machine doors still get nosniff / Referrer / Permissions. |

Existing HSTS / XFO / Referrer / Permissions / nosniff on the response are never
replaced. If the response already has **either** `Content-Security-Policy` or
`Content-Security-Policy-Report-Only`, this package adds **neither** CSP header.

### Idempotency (landed)

```php
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;

// Per route, after throttle / auth / scope / cost:
Route::post('/api/items', ...)->middleware(HandleIdempotencyMiddleware::class);

// Non-replayable routes that issue secrets (API keys, invites, password reset):
Route::post('/api/keys', ...)->middleware(HandleIdempotencyMiddleware::class.':replayable=false');

// Product bucket from server state (never request input):
app()->bind(CredentialBucketResolver::class, MyCredentialBucketResolver::class);
```

| Config / env | Default | Meaning |
| --- | --- | --- |
| `HTTP_BASICS_IDEMPOTENCY_STORE` | `cache` | `cache` or `database` (see `docs/reference-schema.md`). |
| `HTTP_BASICS_IDEMPOTENCY_TTL` | `86400` | Completed record TTL from completion time. |
| `HTTP_BASICS_IDEMPOTENCY_LOCK_TTL` | `60` | Must be ≥ `max_handler_seconds`. |
| `HTTP_BASICS_IDEMPOTENCY_KEY_FORMAT` | `uuid` | `uuid` or `sf-string`. |
| `HTTP_BASICS_IDEMPOTENCY_ANON_ENABLED` | `false` | Public writes via HMAC bucket (`ip`, `user_agent`, `route`). |
| `HTTP_BASICS_IDEMPOTENCY_ANON_SECRET` | — | Required ≥32 bytes when anon is enabled. |

Refusals use fleet JSON (`message` + `error`): `idempotency_key_required`,
`idempotency_key_invalid`, `idempotency_unsupported_media` (415),
`idempotency_payload_mismatch` (422), `idempotency_in_progress` (409 + `Retry-After`),
`idempotency_unavailable` (503). Replays force the **current** `X-Request-Id` and strip
stale `X-RateLimit-*` headers.

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
| Cache poisoning | `Vary: Accept-Language` whenever Accept-Language was read on a machine door; authenticated responses documented `Cache-Control: private`. |
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
