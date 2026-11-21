# Changelog

All notable changes to **laravel-http-basics** (`qiick/laravel-http-basics`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Locale negotiation: `LocaleNegotiator`, `SetLocaleMiddleware`,
  `MachineDoorClassifier`, `SupportedLocales` / `DefaultLocaleResolver` seams,
  and testing helpers (`ArraySupportedLocales`, `FixedDefaultLocaleResolver`,
  `assertContentLanguage`, `assertVaryAcceptLanguage`). Machine doors honour
  capped Accept-Language; web ignores it; `Content-Language` + `Vary` on
  header-sourced responses.
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
