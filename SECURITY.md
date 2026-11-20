# Security Policy

## Supported versions

| Version | Security fixes |
| --- | --- |
| Untagged `develop` / pre-`0.1.0` | Best effort while the package is in development. |
| Tagged `0.x` / `1.x` (once published) | Documented here when the first tag lands. |

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
("Report a vulnerability" on the Security tab of
`qiick-io/laravel-http-basics`). We aim to acknowledge reports within 72 hours.

This package is an HTTP boundary shared by every qiick product. Reports about
request-id spoofing / log injection, locale or `Vary` cache poisoning, security
header bypass, idempotency cross-credential replay, lease/takeover races,
anonymous-bucket secret leakage, or storage of secret-bearing / streamed
responses are treated as critical.

Coordinate disclosure with `qiick/laravel-api-keys` and `qiick/laravel-outbound`
when a finding spans packages.

## Trusted release path

Tagged releases are cut from `main` with GitHub attestations / build provenance
where available. Packagist publish happens only after Luca’s explicit go. Never
trust an untagged `develop` commit as a production dependency.

## Anonymous idempotency secret

When `http-basics.idempotency.anonymous.enabled` is on, rotate
`HTTP_BASICS_IDEMPOTENCY_ANON_SECRET` (≥ 32 bytes) deliberately: rotation
invalidates existing anonymous records.
