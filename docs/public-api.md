# Public API

Everything listed here is the public API frozen at 1.0: it changes only as the
deprecation policy allows. Everything else in `src/` is marked `@internal` and may
change in any release. `tests/Arch/PublicApiTest.php` fails when a class is neither
`@api` nor `@internal`, or when an `@api` class is missing from this page.

## Classes, interfaces and enums

Public means the class name, its public methods and constructor, and for enums the
case names and backing values (backing values are wire values).

### `Qiick\HttpBasics\Contracts`

- `CredentialBucketResolver` (interface)
- `DefaultLocaleResolver` (interface)
- `ErrorRenderer` (interface)
- `IdGenerator` (interface)
- `IdempotencyStore` (interface)
- `SupportedLocales` (interface)
- `TrustedProxyDetector` (interface)

### `Qiick\HttpBasics\Cors`

- `AllowedOriginsResolver` (interface)
- `ConfigureCorsMiddleware` (class)
- `CorsConfigValidator` (class)
- `CorsHeaderCatalog` (class)
- `NullAllowedOriginsResolver` (class)
- `OriginNormalizer` (class)

### `Qiick\HttpBasics\Errors`

- `ErrorCode` (enum)
- `ErrorCodeAugmentor` (class)
- `ErrorCodeResolver` (interface)
- `ExceptionErrorCodeAugmenter` (class)
- `ExceptionRespondBinder` (interface)
- `FrameworkErrorCodeResolver` (class)
- `MachineDoorJson` (class)
- `ProblemDetailsErrorRenderer` (class)
- `ProblemInstanceResolver` (interface)

### `Qiick\HttpBasics\Idempotency`

- `AnonymousBucketResolver` (class)
- `CacheIdempotencyStore` (class)
- `ClaimOutcome` (enum)
- `DatabaseIdempotencyStore` (class)
- `Fingerprinter` (class)
- `FleetErrorRenderer` (class)
- `HandleIdempotencyMiddleware` (class)
- `IdempotencyClaim` (class)
- `IdempotencyContext` (class)
- `IdempotencyKeyFormat` (enum)
- `IdempotencyKeyValidator` (class)
- `IdempotencyRecord` (class)
- `IdempotencyRejection` (class)
- `IdempotencyScope` (class)
- `IdempotencyStoreException` (class)
- `NullCredentialBucketResolver` (class)
- `PayloadTooLargeException` (class)
- `ReplayHeaderSanitizer` (class)
- `ReplayableResponse` (class)

### `Qiick\HttpBasics\Laravel`

- `HttpBasicsServiceProvider` (class)

### `Qiick\HttpBasics\Locale`

- `LocaleNegotiator` (class)
- `LocaleResolution` (class)
- `LocaleSource` (enum)
- `MachineDoorClassifier` (class)
- `SetLocaleMiddleware` (class)

### `Qiick\HttpBasics\RequestId`

- `AssignRequestIdMiddleware` (class)
- `RequestId` (class)
- `RequestIdAcceptFrom` (enum)
- `RequestIdPropagator` (class)
- `RequestIdResolver` (class)
- `RequestIdSource` (enum)

### `Qiick\HttpBasics\Security`

- `CspMode` (enum)
- `CspNonceProvider` (interface)
- `CspPolicyBuilder` (interface)
- `RequestCspNonceProvider` (class)
- `SecurityHeaderBuilder` (class)
- `SecurityHeadersPolicy` (class)
- `SetSecurityHeadersMiddleware` (class)
- `ViteFriendlyCspPolicyBuilder` (class)

### `Qiick\HttpBasics\Testing`

- `AlwaysTrustedProxyDetector` (class)
- `ArraySupportedLocales` (class)
- `FakeCredentialBucketResolver` (class)
- `FixedClock` (class)
- `FixedDefaultLocaleResolver` (class)
- `FixedIdGenerator` (class)
- `InMemoryIdempotencyStore` (class)
- `NeverTrustedProxyDetector` (class)

### `Qiick\HttpBasics\Uploads`

- `ArrayUploadPolicy` (class)
- `ConfigUploadPolicyResolver` (class)
- `DetectedMimeExtension` (class)
- `FileNameSanitizer` (class)
- `HardFloor` (class)
- `MalwareScanner` (interface)
- `MismatchMode` (enum)
- `NullMalwareScanner` (class)
- `SafeUpload` (class)
- `ScanResult` (enum)
- `UploadPolicy` (interface)
- `UploadPolicyResolver` (interface)
- `UploadRejectedException` (class)
- `UploadValidator` (class)
- `ValidatedUpload` (class)

### Testing functions (`Qiick\HttpBasics\Testing`)

- `assertRequestIdEchoed()`
- `assertContentLanguage()`
- `assertVaryAcceptLanguage()`
- `assertSecurityHeaders()`
- `assertIdempotentReplay()`
- `assertNoSensitiveBucketData()`

## Config keys

Every key below sits under `http-basics.` in `config/http-basics.php`; the published
file documents each one.

- `errors.format`
- `errors.problem_type_base_uri`
- `uploads.magic_signatures`
- `uploads.contexts`
- `request_id.hidden_context_keys`
- `request_id.accept_pattern`
- `request_id.accept_from`
- `request_id.trust_wildcard_proxies`
- `request_id.outbound.allowed_hosts`
- `locale.supported`
- `locale.machine_doors`
- `locale.cookie`
- `locale.fallback`
- `locale.max_header_bytes`
- `locale.max_languages`
- `security.enabled`
- `security.x_frame_options`
- `security.referrer_policy`
- `security.permissions_policy`
- `security.csp.mode`
- `security.csp.nonce`
- `security.csp.policy`
- `security.csp.report_only_policy`
- `security.csp.report_uri`
- `security.csp.script_hashes`
- `security.hsts.max_age`
- `security.hsts.include_subdomains`
- `security.hsts.preload`
- `security.baseline_on_machine_doors`
- `idempotency.store`
- `idempotency.schedule`
- `idempotency.cache_store`
- `idempotency.database.connection`
- `idempotency.database.table`
- `idempotency.ttl_seconds`
- `idempotency.lock_ttl_seconds`
- `idempotency.max_handler_seconds`
- `idempotency.stale_after_seconds`
- `idempotency.key_format`
- `idempotency.max_key_length`
- `idempotency.max_body_bytes`
- `idempotency.max_response_bytes`
- `idempotency.replay_header`
- `idempotency.methods`
- `idempotency.require_json`
- `idempotency.anonymous.enabled`
- `idempotency.anonymous.secret`
- `idempotency.anonymous.components`
- `idempotency.documentation_url`
- `idempotency.replay_headers`
- `idempotency.denied_replay_headers`

The package also reads `app.locale` and `app.fallback_locale`, and validates Laravel's
own `cors.*` config at boot.

## Environment variables

- `APP_FALLBACK_LOCALE`
- `HTTP_BASICS_BASELINE_ON_API`
- `HTTP_BASICS_CSP`
- `HTTP_BASICS_CSP_MODE`
- `HTTP_BASICS_CSP_NONCE`
- `HTTP_BASICS_CSP_REPORT_ONLY`
- `HTTP_BASICS_CSP_REPORT_URI`
- `HTTP_BASICS_ERROR_FORMAT`
- `HTTP_BASICS_HSTS_MAX_AGE`
- `HTTP_BASICS_HSTS_PRELOAD`
- `HTTP_BASICS_HSTS_SUBDOMAINS`
- `HTTP_BASICS_IDEMPOTENCY_ANON_COMPONENTS`
- `HTTP_BASICS_IDEMPOTENCY_ANON_ENABLED`
- `HTTP_BASICS_IDEMPOTENCY_ANON_SECRET`
- `HTTP_BASICS_IDEMPOTENCY_CACHE_STORE`
- `HTTP_BASICS_IDEMPOTENCY_CONNECTION`
- `HTTP_BASICS_IDEMPOTENCY_DOCS_URL`
- `HTTP_BASICS_IDEMPOTENCY_KEY_FORMAT`
- `HTTP_BASICS_IDEMPOTENCY_LOCK_TTL`
- `HTTP_BASICS_IDEMPOTENCY_MAX_BODY_BYTES`
- `HTTP_BASICS_IDEMPOTENCY_MAX_HANDLER`
- `HTTP_BASICS_IDEMPOTENCY_MAX_RESPONSE_BYTES`
- `HTTP_BASICS_IDEMPOTENCY_REPLAY_HEADER`
- `HTTP_BASICS_IDEMPOTENCY_STALE_AFTER`
- `HTTP_BASICS_IDEMPOTENCY_STORE`
- `HTTP_BASICS_IDEMPOTENCY_TABLE`
- `HTTP_BASICS_IDEMPOTENCY_TTL`
- `HTTP_BASICS_LOCALE_MAX_HEADER_BYTES`
- `HTTP_BASICS_LOCALE_MAX_LANGUAGES`
- `HTTP_BASICS_OUTBOUND_HOSTS`
- `HTTP_BASICS_PROBLEM_TYPE_BASE_URI`
- `HTTP_BASICS_REFERRER_POLICY`
- `HTTP_BASICS_REQUEST_ID_ACCEPT_FROM`
- `HTTP_BASICS_SECURITY_ENABLED`
- `HTTP_BASICS_SUPPORTED_LOCALES`
- `HTTP_BASICS_TRUST_WILDCARD_PROXIES`
- `HTTP_BASICS_X_FRAME_OPTIONS`

## Artisan commands

- `http-basics:prune-idempotency` (`--hours`, `--dry-run`)

## Publish tags

- `http-basics-config`
- `http-basics-migrations`
- `http-basics-lang`

## Migrations

- `2026_11_22_000001_create_idempotency_records_table`: optional, for the database
  idempotency store. Schema in `docs/reference-schema.md`. Edited in place until 1.0,
  forward-only after.

## Translations

- `http-basics::idempotency.*`: one line per refusal code, shipped in `en` and `it`;
  products override them through the `http-basics-lang` publish tag.

## Wire formats

- `error` codes: `docs/error-codes.md` (`Qiick\HttpBasics\Errors\ErrorCode`).
- Headers: `X-Request-Id`, `Idempotency-Key`, `Idempotent-Replayed` (configurable),
  `Content-Language`, and the security headers listed in the README.

## Events

The package dispatches no events.

## Deprecation policy

This package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

- **Deprecation in minor releases.** Mark deprecated PHP symbols with
  `@deprecated`, issue a runtime notice where appropriate, and document the
  deprecated API in `CHANGELOG.md` under **Deprecated** and its replacement
  in `UPGRADING.md`. The deprecated API keeps working for at least one minor
  release.
- **Removal in major releases.** Starting with 1.0.0, remove deprecated
  public APIs only in a major release.
- **Pre-1.0 changes.** A minor release may include breaking changes. Record
  every break in `UPGRADING.md` before tagging the release. Migrations become
  forward-only at 1.0.0.
