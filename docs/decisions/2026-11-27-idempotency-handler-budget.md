# 2026-11-27: Idempotency handler budget

Retain `max_handler_seconds` for 1.0 as the replay budget required by M7. The
middleware measures the handler with a monotonic clock. When a handler returns
past the budget, it attempts to seal the claim without a response and returns
503. The deployment validator requires a budget below the lock TTL.
`HandleIdempotencyMiddlewareCoverageTest` exercises an over-budget handler and
rejects a budget equal to the lock TTL.

The application runtime owns execution deadlines. Once the lock expires and the
claim is stale, a retry may take over for crash recovery. Use runtime and upstream
timeouts below the lock TTL. Protect side effects with a product operation key,
a transaction or an idempotent downstream request.
