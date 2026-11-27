# Upgrading

This package is **pre-release** (`0.x`). Breaking changes are allowed between
minor versions while the major is `0`, and each break is recorded here before a
tagged release.

## From 0.x toward 1.0.0

This section documents what changes for applications adopting the package or
upgrading from pre-release `0.x` milestones to the stable `1.0.0` release.

### Configuration

Publish or re-publish the package configuration file to pick up new sections:

```bash
php artisan vendor:publish --tag=http-basics-config --force
```

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
  - The package now schedules database pruning daily. Set `idempotency.schedule`
    to `false` if you keep an existing application-level schedule.
  - `max_handler_seconds` must be strictly less than `lock_ttl_seconds` to guarantee
    locks do not expire while handlers execute normally.
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

- **Idempotency fingerprint version 2:** the raw query uses form semantics (`+` is a
  space, `%2B` a plus), and the fingerprint includes the media type in lower case,
  without parameters. Version 2 first ships with both changes. For deployments that
  ran an untagged build, retained replayable records keep their original fingerprints.
  Retrying one with the updated build returns `idempotency_payload_mismatch` (422)
  while the record is live. Drain old writers before rollout, retain existing records
  through their TTL, and verify the original operation's outcome before issuing a new key.

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
> The initial migration freezes at the first stable release. Existing installations
> from untagged builds must reconcile their published migration and table with the
> [reference schema](docs/reference-schema.md). On a disposable database, republish
> the migration and use `migrate:fresh --seed`. To retain live records, drain writers,
> back up the table, and have the product migrate it explicitly. Map any legacy
> organisation keys to their product UUIDs and leave unscoped rows null. Normalize
> existing organisation UUIDs to lowercase before converting the column; scoped
> erasure compares the canonical UUID. PostgreSQL converts `metadata` from `jsonb`
> to `json`. Preserve the claim keys, leases, response data and expiry times.
> Compare the upgraded columns against the reference schema, verify lowercase
> organisation values, and run `http-basics:check` to confirm the table is reachable
> before resuming traffic.

New claims and scoped erasure both require organisation UUIDs. Supply these from
trusted product state through `ORGANISATION_ID_ATTRIBUTE` or `IdempotencyContext`,
and pass the same UUID in the erasure call.

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
