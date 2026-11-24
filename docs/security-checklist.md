# Security checklist: properties and the tests that cover them

Design §8 lists the security properties the package must keep. Each row names the
test that fails when the property breaks, so the 1.0 checklist ("every numbered
security item has a red-first test that names it") can be checked line by line.
**Gap** marks a property with no test, **Partial** one whose test covers only part
of it.

| # | Property | Covering test | Status |
| --- | --- | --- | --- |
| H1 | Keep a PATTERN-matching id from a trusted proxy | `RequestIdResolverTest` › keeps a valid inbound id under trusted_proxy when the hop is trusted; `RealHttpRequestIdTest` › keeps a trusted proxy id over real HTTP | Covered |
| H2 | Untrusted hop or `never` → generate | `RequestIdResolverTest` › generates when trusted_proxy and the hop is untrusted; › always generates when accept_from is never | Covered |
| H3 | Invalid or duplicate header → generate | `RequestIdResolverTest` › generates on invalid or duplicate inbound values; `RequestIdTest` › rejects duplicate header values even when each matches | Covered |
| H4 | Provenance only in hidden Context | `RequestIdResolverTest` › stores the id in visible Context for logging and jobs (asserts `request_id_source` hidden) | Covered |
| H5 | Error responses 401–503 carry the id | `AssignRequestIdMiddlewareTest` › carries the id on 404 / 405 / 500 / maintenance 503 / error responses | Partial: no 401, 403, 419, 422 or 429 case |
| H6 | Queued jobs inherit the id | `RequestIdJobInheritanceTest` › inherits the visible request_id into a database-queued job after serialisation | Covered |
| H7 | Empty outbound allowlist → no header | `RequestIdPropagatorTest` › does not propagate when the allowlist is empty | Covered |
| H8 | Allowlist, `*`, caller wins, no leak on redirect | `RequestIdPropagatorTest` › propagates to an allowlisted host; › propagates to every host when allowlist is *; › lets a caller-supplied X-Request-Id win and never duplicates; › strips the header when a redirect lands on a host outside the allowlist | Covered |
| L1 | q-values honoured, `q=0` ignored | `LocaleNegotiatorTest` › honours q-values and ignores q=0 on machine doors | Covered |
| L2 | Oversize Accept-Language falls through | `LocaleNegotiatorTest` › treats an oversize Accept-Language as absent but still marks it read; › treats too many language ranges as absent | Covered |
| L3 | `de-DE` matches `de`; `EN` does not match `en` | `LocaleNegotiatorTest` › matches de-DE to available de and rejects case-folded EN | Covered |
| L4 | Header beats principal on machine doors; web ignores Accept-Language | `LocaleNegotiatorTest` › prefers Accept-Language over a later principal on machine doors; › ignores Accept-Language on web requests | Covered |
| L5 | Content-Language and `Vary` when the header was read | `SetLocaleMiddlewareTest` › sets Content-Language and Vary from Accept-Language on machine doors; › adds Vary when Accept-Language was read even if it did not win | Covered |
| L6 | Exact codes; `*` never outranks an explicit language | `LocaleNegotiatorTest` › prefers an exact available locale over an earlier prefix match; › does not let * outrank an explicit language | Covered |
| L7 | Global middleware runs on 404/419/429 | `SetLocaleMiddlewareTest` › carries Content-Language on error responses (429); › carries Content-Language on a maintenance 503 when prepended globally | Partial: no 404 or 419 case |
| S1 | CSP mode picks the header names; `both` explicit | `SecurityHeaderBuilderTest` › emits report-only CSP and not enforce when mode is report-only; › emits both CSP headers when mode is both | Covered |
| S2 | `frame-ancestors` present | `SecurityHeaderBuilderTest` › sets the full web header set including CSP in enforce mode | Covered |
| S3 | Never clobber an existing CSP or HSTS | `SecurityHeaderBuilderTest` › never clobbers an existing HSTS header; › adds no CSP of either kind when Content-Security-Policy is already present | Covered |
| S4 | HSTS flags only when configured and secure | `SecurityHeaderBuilderTest` › sets HSTS only on secure requests when max_age is positive; › omits HSTS flag tokens when they are not configured | Covered |
| S5 | Machine doors get the baseline only | `SecurityHeaderBuilderTest` › applies baseline only on machine doors when enabled; `SetSecurityHeadersMiddlewareTest` › sets baseline only on machine doors | Covered |
| S6 | Streamed, binary and redirect responses get headers without buffering | `SetSecurityHeadersMiddlewareTest` › applies headers to streamed responses without reading the body; › applies headers to error responses without buffering a body | Partial: no BinaryFileResponse or redirect case |
| I1 | Key format validation | `HandleIdempotencyMiddlewareTest` › requires a valid idempotency key; `IdempotencyKeyValidatorTest` | Covered |
| I2 | Canonical JSON (flags, key order) | `FingerprinterTest` › canonicalizes json whitespace key order and zero fractions | Covered |
| I3 | Duplicate query order; path slash; 415 on multipart | `FingerprinterTest` › preserves duplicate query values in encounter order; › treats paths with and without trailing segments as distinct; `HandleIdempotencyMiddlewareTest` › rejects non json content type with 415 | Covered |
| I4 | Served locale in the fingerprint | `HandleIdempotencyMiddlewareCoverageTest` › folds the served locale into machine-door fingerprints and nothing into web ones | Covered |
| I5 | Execute once; replay 2xx/422 exact bytes | `HandleIdempotencyMiddlewareTest` › executes once and replays exact bytes including 422 | Covered |
| I6 | Concurrent → 409; a held lock blocks takeover | `IdempotencyLockRaceTest` › keeps a concurrent claim in Conflict while the lock is held; `RealHttpIdempotencyTest` › returns in-progress 409 for a concurrent duplicate Idempotency-Key over real HTTP | Covered |
| I7 | Bucket isolation; no cross-replay after revoke or anon → auth | `SecurityChecklistIdempotencyTest` › never replays one bucket's response to another (I7); › never replays an anonymous response once the client is authenticated (I7); `CacheIdempotencyStoreTest` › never replays a completed record to another bucket (I7); `DatabaseIdempotencyStoreTest` › keeps buckets that differ only in letter case apart on every engine; `InMemoryIdempotencyStoreTest` (two buckets); `AnonymousBucketResolverTest` › does not trust request bucket inputs | Covered |
| I8 | Anonymous HMAC components; boot fails without a secret; the secret never leaks | `AnonymousBucketResolverTest` › derives stable anon buckets without embedding the secret; `IdempotencyBootValidationTest` › fails boot when anonymous idempotency is enabled without a long enough secret; `SecurityChecklistIdempotencyTest` › keeps the anonymous secret out of logs, responses and boot errors (I8) | Covered |
| I9 | Never store 5xx, 429, Set-Cookie, denied headers, streams | `HandleIdempotencyMiddlewareTest` › never stores 5xx responses; `HandleIdempotencyMiddlewareCoverageTest` › abandons binary file responses, non-utf8 bodies, 429, redirects and denied headers; › abandons streamed responses without completing the claim | Covered |
| I9b | Store and replay 422 | `HandleIdempotencyMiddlewareTest` › executes once and replays exact bytes including 422 | Covered |
| I10 | Lease miss on complete → 503, no completed record | `SecurityChecklistIdempotencyTest` › answers 503 and keeps no completed record when the lease is lost mid-flight (I10); `CacheIdempotencyStoreTest` and `DatabaseIdempotencyStoreTest` lease-mismatch cases | Covered |
| I11 | `replayable=false` never stores; the secret never reaches the store | `HandleIdempotencyMiddlewareTest` › does not store when replayable is false; `HandleIdempotencyMiddlewareCoverageTest` › refuses unknown, misspelled or duplicated options instead of replaying secrets | Covered |
| I12 | An exception abandons the claim; the product error still renders | `HandleIdempotencyMiddlewareCoverageTest` › rethrows exceptions after abandoning the in-flight claim; › rethrows the handler failure even when abandoning the claim fails too | Covered |
| I13 | Stale takeover only without the lock | `IdempotencyLockRaceTest` › allows stale takeover only after the lock is gone and stale_after elapses | Covered |
| I14 | Unique `(bucket, key_hash)` on four engines | `DatabaseIdempotencyStoreTest` › enforces unique bucket and key hash pairs (CI: SQLite, PostgreSQL, MySQL, MariaDB) | Covered |
| I15 | Refusal codes with Retry-After; no debug output; refusals never stored | `HandleIdempotencyMiddlewareTest` › renders in progress refusals with retry-after on the json payload; `SecurityChecklistIdempotencyTest` › shows no debug output on a refusal with app.debug on (I15); › stores nothing for a refused request (I15); `ErrorCodeCatalogueTest` | Covered |
| I16 | Throttle runs outside; stale rate-limit headers stripped on replay | `SecurityChecklistIdempotencyTest` › lets the throttle refuse before the handler and the store when it runs outside (I16); › replays with the throttle's current rate-limit headers, not the stored ones (I16); `ReplayHeaderSanitizerTest` › never replays rate-limit counters, even allowlisted ones (design I16) | Covered |
| E1 | Augmentor touches only machine-door JSON ≥400, never overwrites | `ErrorCodeAugmentorTest` › adds error only on JSON responses with status at least 400; › never overwrites an existing error member | Covered |
| E2 | No Foundation imports in `src` | `PackageArchitectureTest` › src never imports Illuminate Foundation types | Covered |
| E3 | `MachineDoorJson` without an `Accept` header | `ErrorCodeAugmentorTest` › builds a machine-door JSON gate callback from path patterns | Partial: the request in that test has no explicit "no Accept" assertion |

Summary: 37 covered, 4 partial (H5, L7, S6, E3), 0 gap.
