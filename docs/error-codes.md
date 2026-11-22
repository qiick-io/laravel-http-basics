# Fleet `error` codes

Stable `snake_case` values for the top-level JSON `error` member (never translated).
Source of truth: `Qiick\HttpBasics\Errors\ErrorCode`. A Pest catalogue test fails when
this table and the enum diverge.

| Code | Description |
| --- | --- |
| `validation_failed` | Request body or fields failed validation. |
| `unauthenticated` | Authentication is required or the credential is invalid. |
| `forbidden` | The caller is authenticated but not allowed to perform this action. |
| `not_found` | The requested resource or route does not exist. |
| `method_not_allowed` | The HTTP method is not allowed for this route. |
| `too_many_requests` | The caller exceeded a rate limit. |
| `conflict` | The request conflicts with the current resource state. |
| `unsupported_media_type` | The request Content-Type is not supported. |
| `service_unavailable` | The service is temporarily unavailable. |
| `http_error` | Other client HTTP error without a more specific fleet code. |
| `server_error` | Unexpected server failure. |
| `idempotency_key_required` | Idempotency-Key header is required on this route. |
| `idempotency_key_invalid` | Idempotency-Key header failed format validation. |
| `idempotency_payload_mismatch` | Payload fingerprint does not match the original claim. |
| `idempotency_in_progress` | Another request with this key is still in progress. |
| `idempotency_not_replayable` | The prior response cannot be replayed. |
| `idempotency_unavailable` | The idempotency store could not be reached. |
| `idempotency_unsupported_media` | Idempotent routes require application/json or application/*+json. |
