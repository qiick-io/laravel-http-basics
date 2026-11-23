# 2026-11-23: SHOULD items of the standards review before and after 1.0

Decided by the maintainer's coordinator on 2026-11-23, from the standards review of
this package (S-items). The rule applied: anything that would break the public API or
the schema if added later lands before 1.0; additive items may follow in 1.x.

| Item | Decision | Why |
| --- | --- | --- |
| S2 CSP nonce and hash seam (`CspNonceProvider`) | **Before 1.0** | Strict CSP with nonces is the standard safe practice (CSP3 `nonce-source`, `'strict-dynamic'`); without it the first adopting product keeps `csp.mode=off`, and that adoption is part of 1.0. |
| S10 `v` field in the cache-store payload | **Before 1.0** | A few lines; it mirrors `schema_version` so a later payload change never has to infer an unversioned entry. |
| S1 W3C Trace Context module | 1.x | Additive: a new middleware, config and value object. The `trace_id` column and `IdempotencyContext::$traceId` already exist, so no API or schema change. |
| S3 `Reporting-Endpoints` and CSP `report-to` | 1.x | Additive config keys, active only when set; `report-uri` stays. No API or schema change. |
| S4 Permissions-Policy as a Structured Field map | 1.x | Additive optional map; the raw string keeps winning. No API or schema change. |
| S11 `CredentialIdentityResolver` | 1.x | Additive optional interface; it fills `IdempotencyContext::$credentialPublicId`, which exists in the contract and the schema already. |
| S12 `upgrade-insecure-requests` and Trusted Types flags | 1.x | Additive: flags with defaults appended to `SecurityHeadersPolicy` plus config keys, off by default. No API or schema change. |
