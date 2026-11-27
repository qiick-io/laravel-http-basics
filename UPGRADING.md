# Upgrading

The public API and schema remain provisional through the `1.0.0` prereleases.
Check these notes before upgrading between betas.

## First beta

After the first tag appears on Packagist, run
`composer require qiick/laravel-http-basics:1.0.0-beta.1`.
Commit the resulting Composer lock file and keep the product's minimum stability
at stable. Publish `http-basics-config` to manage the package values in the product.
Run `php artisan http-basics:check` as a preflight after config caching and any
product-owned migrations.

### Product wiring

1. In `bootstrap/app.php`, prepend `AssignRequestIdMiddleware` outermost
   and prepend `SetSecurityHeadersMiddleware`. Keep the product's trusted proxy
   configuration and select which hops can supply request IDs. Set any hidden
   Context aliases and opt in to outbound propagation only for approved hosts.
2. Put `SetLocaleMiddleware` after cookies and session in the web group and
   apply it to machine doors. Bind `SupportedLocales` and
   `DefaultLocaleResolver` when the product derives them from its own settings.
   Choose global registration for machine-door errors that occur before routing
   and verify web cookie and session locale behavior in the resulting stack.
3. Carry the existing CSP, framing, permissions, referrer and HSTS policy into
   `config/http-basics.php`. Keep product CSP directives through `CspPolicyBuilder`
   where needed. If enforcing a nonce policy, share the same request nonce with
   Vite and inline scripts. For public API CORS, merge the header catalogs into
   `config/cors.php`, list credentialed origins and headers explicitly, and
   put `ConfigureCorsMiddleware` before Laravel's `HandleCors` for dynamic
   origins. Preserve any product API key origin enforcement.
4. Keep the product's error status and `error` codes. Extend machine-door JSON
   rendering and register `ExceptionErrorCodeAugmenter` where the fleet envelope is the
   contract. Preserve any product code resolver that maps additional exceptions.
5. Add `HandleIdempotencyMiddleware` to selected write routes after auth,
   permission, throttle and cost checks. Bind `CredentialBucketResolver` to
   product-owned identity and tenant scope. Set trusted credential and
   organisation UUID attributes for database claims. Keep secret-issuing routes
   `replayable=false`. Use a shared lock-capable cache and choose a record TTL
   greater than the lock TTL. Bound handler runtime below the lock TTL.
6. Use the database adapter for subject-specific or organisation-scoped erasure.
   Publish the initial migration to the product, apply it, and run the scheduler
   with a shared cache. Register `HttpBasicsEraser` in the product's resumable
   erasure orchestrator after access revocation and in-flight request drainage.
   Pass the product's user ID, public ID and credential UUIDs, and the organisation
   UUID for scoped erasure. Add the C17 conformance case and document retained
   fields and TTL in the product privacy inventory. Cache-only claims expire
   through TTL and support global retention reporting.
7. Run product fixtures for CSP and assets, CORS preflight and sequential origin
   isolation, error status and locale, and idempotency concurrency, replay and
   secret responses. Where adopting uploads, exercise the product's accepted
   types, archive policy and WebP MIME and signature checks. Record green CI
   and the checklist observation window in Excubia and the second product.

### Product entry points

- **Excubia.** Replace the product's `SecurityHeadersMiddleware` and
  `SetLocaleMiddleware` registrations in `bootstrap/app.php`. Transfer
  `EXCUBIA_HSTS_MAX_AGE` to `HTTP_BASICS_HSTS_MAX_AGE` and use
  `HTTP_BASICS_CSP_MODE=off` to match the existing web baseline. Verify the
  branding route's own CSP, the Vite assets and the inline appearance script
  before enabling a stronger policy.
- **Instituta.** Replace the product's `SetSecurityHeadersMiddleware` and
  `SetLocaleMiddleware` registrations in `bootstrap/app.php`. Preserve the
  `AddContractErrorCodeMiddleware` contract for the public API and verify
  JSON rendering for the MCP doors the product enables.

## Upgrading from an untagged build

These changes apply to products that tested an untagged build before the first beta.

### Configuration

Publish the configuration if the product does not have a copy:

```bash
php artisan vendor:publish --tag=http-basics-config
```

For an existing published copy, merge the changes below while preserving the
product's configuration values.

Notable configuration changes and requirements:

- **CORS (`http-basics.cors` / `config/cors.php`):**
  - The `http-basics.cors.validate_on_boot` switch (`HTTP_BASICS_CORS_VALIDATE_ON_BOOT`)
    has been removed. Boot-time validation of `config('cors')` now always runs.
  - When `cors.supports_credentials` is `true`, boot-time validation strictly
    forbids wildcard origins (`*`), wildcard origin patterns (`allowed_origins_patterns`),
    and wildcards in `allowed_headers` or `exposed_headers` (e.g. `allowed_headers: ['*']`).
    Every allowed origin and header must be listed explicitly, or resolved dynamically
    via a bound `AllowedOriginsResolver`.
  - Expose fleet headers in `config/cors.php` by merging `CorsHeaderCatalog::exposedHeaders()`
    and `CorsHeaderCatalog::allowedRequestHeaders()`.

- **Errors (`http-basics.errors`):**
  - `errors.format` controls the error envelope: `'fleet'` (default, top-level
    `message` and `error` codes) or `'problem_details'` (RFC 9457 `application/problem+json`).
  - `errors.problem_type_base_uri` optionally prefixes `type` URIs for problem details.
    When null, `about:blank` is used.

- **Idempotency (`http-basics.idempotency`):**
  - `idempotency.store` selects the backing engine (`cache` or `database`).
  - When using the database store, `idempotency.database.connection` specifies the
    connection. If unset, it falls back to the application default connection.
  - The package schedules database pruning hourly. Set `idempotency.schedule`
    to `false` if you keep an existing application-level schedule.
  - `max_handler_seconds` limits replay eligibility after the handler returns and
    must be strictly less than `lock_ttl_seconds`. Set the product's runtime timeout
    below the lock TTL and keep the write itself idempotent.
  - On machine doors, request fingerprinting now includes the negotiated response
    locale (rather than the raw `Accept-Language` header), ensuring equivalent language
    negotiations match the same idempotency claim.
  - Middleware parameter syntax enforces a strict grammar: options must be exact and
    case-sensitive (`replayable=false` or `replayable=true`); invalid or misspelled
    options throw an exception at runtime.

- **Uploads (`http-basics.uploads`):**
  - `uploads.contexts` defines validation contexts. Archives (`.zip`, `.docx`, etc.)
    are refused by default unless `allows_archives: true` is explicitly enabled for
    that context.
  - When `allows_archives` is enabled, strict ZIP layout verification (`ZipLayoutVerifier`)
    and streaming uncompressed byte/ratio limits are enforced.

- **Missing, null and invalid values:**
  - A key missing from a block you published before it existed takes the package
    default (the publish only merges top-level blocks, so nested keys can be absent).
  - A boolean set to `null` now takes the package default instead of `false`: for
    example, a `null` `security.enabled`, `security.baseline_on_machine_doors` or
    `idempotency.require_json` is now on. An explicit `false` stays off. Any other value
    of the wrong type (a string for a number, a blank string for a header value) also
    takes the default, so a blank `HTTP_BASICS_X_FRAME_OPTIONS` still sends `DENY`.
  - A present but invalid `security.csp.mode` or `request_id.accept_from` now fails at
    boot (so a deploy step such as `php artisan config:cache` or `migrate` reports it),
    instead of failing every request that needs it.
  - With `idempotency.anonymous.enabled`, a missing `idempotency.anonymous.components`
    takes `ip, user_agent, route` instead of failing at boot; a value that is not a list
    (such as `'ip'`) still fails.
  - A missing `locale.machine_doors` gives the security headers the same doors as the
    locale classifier: `api`, `api/*`, `mcp`, `mcp/*`, so `/api` itself is a machine door.

- **Idempotency fingerprint version 2:** the raw query is read as a form (`+` is a
  space, `%2B` a plus) and `Fingerprinter::VERSION` is 2. Within `ttl_seconds` of the
  deploy, a client retrying a key claimed before it, with `+` or `%2B` in the query, gets
  `idempotency_payload_mismatch` (422) instead of the replay; other keys are unaffected.
  Deploy outside peak traffic, or let clients retry with a new key.

- **Quoted idempotency keys:** the draft's quoted form (`Idempotency-Key: "…"`) is
  accepted in both key formats and stored without its quotes, so `"abc"` and `abc` are
  the same key.

- **Idempotency record lifetime:** `idempotency.ttl_seconds` must be greater than
  `idempotency.lock_ttl_seconds`, or boot fails. The defaults (86400 and 60) pass.

- **Replayed idempotent responses:** their headers keep their values but are now named
  in lower case (`content-type`, `location`). HTTP header names are case-insensitive;
  check any client or test that compares them as exact strings.

### Migrations

Idempotency database storage is optional. The migration is **not** auto-loaded
by default. If your application uses `HTTP_BASICS_IDEMPOTENCY_STORE=database`:

1. Publish the migration:
   ```bash
   php artisan vendor:publish --tag=http-basics-migrations
   ```
2. Run migrations:
   ```bash
   php artisan migrate
   ```

> [!IMPORTANT]
> In accordance with qiick standards, migrations are frozen at the first stable release
> (`1.0.0`). Before `1.0.0`, schema modifications are made directly in the initial
> `2026_11_22_000001_create_idempotency_records_table.php` migration. If you were
> using an earlier pre-release schema, run `migrate:fresh` or recreate the table to match
> the reference schema in `docs/reference-schema.md`.

Pruning completed records can be performed via:
```bash
php artisan http-basics:prune-idempotency
```
or by registering Laravel's `model:prune` against `Qiick\HttpBasics\Idempotency\IdempotencyRecord`.

### Middleware Registration

The package requires specific registration order in `bootstrap/app.php`:

1. **Outer global middleware (prepend):**
   - `AssignRequestIdMiddleware` must be prepended outermost so error responses (401, 404, 503)
     and maintenance responses carry `X-Request-Id`.
   - `SetSecurityHeadersMiddleware` must be prepended so baseline security headers
     (`nosniff`, `Referrer-Policy`, `Permissions-Policy`) are present on all responses.
   - `SetLocaleMiddleware` should be prepended if early maintenance responses require
     `Content-Language`.

2. **Route groups:**
   - Append `SetLocaleMiddleware` to the `web` and `api` groups to enable session/cookie
     or `Accept-Language` negotiation.
   - `ConfigureCorsMiddleware` should be placed ahead of `HandleCors` if using dynamic
     origin resolution via `AllowedOriginsResolver`.

3. **Per-route middleware:**
   - Register `HandleIdempotencyMiddleware` on mutating routes (`POST`, `PATCH`) after
     authentication, throttling, and authorization.
   - For endpoints that issue one-time secrets or credentials (e.g. API keys, password resets),
     always disable replay:
     ```php
     Route::post('/api/keys', ...)->middleware(HandleIdempotencyMiddleware::class.':replayable=false');
     ```

### Error Handling

Wire error augmentation into your application's bootstrap:

```php
use Qiick\HttpBasics\Errors\ExceptionErrorCodeAugmenter;
use Qiick\HttpBasics\Errors\MachineDoorJson;

// Target machine-door routes
$exceptions->shouldRenderJsonWhen(MachineDoorJson::whenCallback(['api/*', 'mcp', 'mcp/*']));

// Augment JSON error responses with top-level error codes
$exceptions->respond(fn ($response, $exception, $request) => app(ExceptionErrorCodeAugmenter::class)
    ->respond($response, $exception, $request));
```
