<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Errors\ErrorCode;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @api Per-route idempotency after throttle, auth, scope and cost.
 *
 * Middleware params: `required`|`optional`, `replayable=true|false`, optional
 * `max_handler_seconds=N` (must be strictly less than the store lock TTL).
 * Conflict `Retry-After` always uses the configured store lock TTL; there is no
 * per-route `lock_ttl_seconds=` override (that would not change the store lock).
 */
final class HandleIdempotencyMiddleware
{
    public const string HEADER = 'Idempotency-Key';

    public const string DEFAULT_REPLAY_HEADER = 'Idempotent-Replayed';

    public function __construct(
        private IdempotencyStore $store,
        private Fingerprinter $fingerprinter,
        private IdempotencyKeyValidator $keyValidator,
        private CredentialBucketResolver $credentialBuckets,
        private AnonymousBucketResolver $anonymousBuckets,
        private bool $anonymousEnabled,
        private ErrorRenderer $errorRenderer,
        private ReplayHeaderSanitizer $headerSanitizer,
        private RequestIdResolver $requestIds,
        private MachineDoorClassifier $machineDoors,
        private LocaleNegotiator $locale,
        /** @var list<string> */
        private array $methods,
        private bool $requireJson,
        private int $maxBodyBytes,
        private int $maxResponseBytes,
        private int $defaultLockTtlSeconds,
        private int $defaultMaxHandlerSeconds,
        private int $retryAfterSeconds,
        private ?string $replayHeaderName = self::DEFAULT_REPLAY_HEADER,
    ) {}

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $parsed = $this->parseOptions($options);

        if (! $this->isIdempotentMethod($request)) {
            return $this->ensureResponse($next($request));
        }

        if (! $parsed['isReplayable']) {
            $key = $this->headerValue($request);

            if ($parsed['isKeyRequired'] && ($key === null || ! $this->keyValidator->isValid($key))) {
                return $this->reject(
                    $request,
                    $key === null ? ErrorCode::IdempotencyKeyRequired : ErrorCode::IdempotencyKeyInvalid,
                    400,
                );
            }

            return $this->ensureResponse($next($request));
        }

        $headerValue = $this->headerValue($request);

        if ($headerValue === null) {
            if ($parsed['isKeyRequired']) {
                return $this->reject($request, ErrorCode::IdempotencyKeyRequired, 400);
            }

            return $this->ensureResponse($next($request));
        }

        // The stored key: a quoted sf-string key and its bare form are the same key.
        $key = $this->keyValidator->canonicalKey($headerValue);

        if ($key === null) {
            return $this->reject($request, ErrorCode::IdempotencyKeyInvalid, 400);
        }

        if ($this->requireJson && ! $this->isJsonRequest($request)) {
            return $this->reject($request, ErrorCode::IdempotencyUnsupportedMedia, 415);
        }

        $bucket = $this->resolveBucket($request);

        if ($bucket === null) {
            return $this->unavailable($request);
        }

        $acceptLanguageSuffix = $this->acceptLanguageSuffix($request);

        try {
            $fingerprint = $this->fingerprinter->fingerprint(
                $request,
                $this->maxBodyBytes,
                $acceptLanguageSuffix,
                $bucket,
            );
        } catch (PayloadTooLargeException) {
            throw new HttpException(413, 'Request entity too large.');
        } catch (JsonException) {
            return $this->reject($request, ErrorCode::ValidationFailed, 422);
        }

        $scope = new IdempotencyScope($bucket, $request->getMethod(), $request->getPathInfo());
        $now = new \DateTimeImmutable;

        try {
            $claim = $this->store->claim($scope, $key, $fingerprint, $now, new IdempotencyContext(
                $this->keyValidator->format(),
                requestId: $this->storableRequestId(),
            ));
        } catch (IdempotencyStoreException) {
            return $this->unavailable($request);
        }

        return match ($claim->outcome) {
            ClaimOutcome::Replay => $this->replayResponse($request, $claim->replay),
            ClaimOutcome::Mismatch => $this->reject($request, ErrorCode::IdempotencyPayloadMismatch, 422),
            ClaimOutcome::Conflict => $this->reject(
                $request,
                ErrorCode::IdempotencyInProgress,
                409,
                // Store lock TTL is fixed at construction; route options cannot change it.
                ['Retry-After' => (string) $this->defaultLockTtlSeconds],
            ),
            ClaimOutcome::NotReplayable => $this->reject($request, ErrorCode::IdempotencyNotReplayable, 409),
            ClaimOutcome::New => $this->executeOnce($request, $next, $claim, $parsed['maxHandlerSeconds']),
        };
    }

    /**
     * @param  array<array-key, string>  $options
     * @return array{isKeyRequired: bool, isReplayable: bool, maxHandlerSeconds: int}
     */
    private function parseOptions(array $options): array
    {
        $isKeyRequired = true;
        $isReplayable = true;
        $maxHandlerSeconds = $this->defaultMaxHandlerSeconds;

        // Strict grammar: an ignored typo (Replayable=false, replayble=false) would leave a
        // secret-issuing route replayable, so anything unrecognised or repeated throws.
        $seenSettings = [];

        foreach ($options as $rawOption) {
            // Laravel keeps the space after a comma in `:required, replayable=false`.
            [$name, $value] = explode('=', trim($rawOption), 2) + [1 => null];
            // required and optional set the same thing.
            $setting = $name === 'optional' ? 'required' : $name;

            if (in_array($setting, $seenSettings, true)) {
                throw new \InvalidArgumentException("Idempotency middleware option {$setting} is given twice.");
            }

            $seenSettings[] = $setting;

            if ($setting === 'required' && $value === null) {
                $isKeyRequired = $name === 'required';
            } elseif ($name === 'replayable' && $value !== null) {
                $isReplayable = match (strtolower($value)) {
                    'true' => true,
                    'false' => false,
                    default => throw new \InvalidArgumentException('Idempotency middleware option replayable must be true or false.'),
                };
            } elseif ($name === 'lock_ttl_seconds') {
                throw new \InvalidArgumentException(
                    'lock_ttl_seconds middleware option is not supported; configure http-basics.idempotency.lock_ttl_seconds (store lock TTL also drives Conflict Retry-After).',
                );
            } elseif ($name === 'max_handler_seconds' && $value !== null) {
                if (preg_match('/\A-?\d+\z/', $value) !== 1) {
                    throw new \InvalidArgumentException('Idempotency middleware option max_handler_seconds must be an integer.');
                }

                $maxHandlerSeconds = max(1, (int) $value);
            } else {
                throw new \InvalidArgumentException("Unknown idempotency middleware option [{$rawOption}].");
            }
        }

        // Handler budget must stay strictly under the store lock so the lease cannot
        // expire mid-flight while the route still claims the request is within budget.
        if ($maxHandlerSeconds >= $this->defaultLockTtlSeconds) {
            throw new \InvalidArgumentException(
                'max_handler_seconds must be less than the store lock TTL (http-basics.idempotency.lock_ttl_seconds).',
            );
        }

        return [
            'isKeyRequired' => $isKeyRequired,
            'isReplayable' => $isReplayable,
            'maxHandlerSeconds' => $maxHandlerSeconds,
        ];
    }

    private function isIdempotentMethod(Request $request): bool
    {
        // Symfony uppercases getMethod(); the configured methods are uppercased at boot.
        return in_array($request->getMethod(), $this->methods, true);
    }

    private function headerValue(Request $request): ?string
    {
        // Whitespace-only keys count as missing.
        $value = trim($request->headers->get(self::HEADER) ?? '');

        return $value === '' ? null : $value;
    }

    private function isJsonRequest(Request $request): bool
    {
        $contentType = $request->headers->get('Content-Type');

        if ($contentType === null) {
            return false;
        }

        $base = strtolower(trim(explode(';', $contentType)[0]));

        // RFC 6839 §3.1: application/json, or application/<name>+json (merge-patch+json on PATCH)
        // with a non-empty RFC 6838 restricted name before the suffix.
        if ($base === 'application/json') {
            return true;
        }

        return preg_match('/\Aapplication\/[a-z0-9][a-z0-9!#$&^_.+-]*\+json\z/', $base) === 1;
    }

    /**
     * The request id for the audit record, or null when a custom IdGenerator or
     * accept_pattern produced one the record cannot hold: an audit column must never
     * fail the request.
     */
    private function storableRequestId(): ?string
    {
        $requestId = $this->requestIds->current()?->value;

        return $requestId !== null && preg_match(RequestId::PATTERN, $requestId) === 1 ? $requestId : null;
    }

    /**
     * The bucket column holds 128 characters: a longer bucket from a product resolver
     * is stored as its SHA-256, which keeps it unique and never truncated.
     */
    private function storableBucket(string $bucket): string
    {
        return strlen($bucket) > 128 ? 'sha256:'.hash('sha256', $bucket) : $bucket;
    }

    private function resolveBucket(Request $request): ?string
    {
        $bucket = $this->credentialBuckets->bucket($request);

        // Empty string buckets fall through to anonymous/unavailable the same way.
        if ($bucket !== null && $bucket !== '') {
            return $this->storableBucket($bucket);
        }

        if ($this->anonymousEnabled) {
            return $this->anonymousBuckets->bucket($request);
        }

        return null;
    }

    private function acceptLanguageSuffix(Request $request): string
    {
        if (! $this->machineDoors->isMachineDoor($request)) {
            return '';
        }

        // The locale actually served, whatever chose it: "en-US,en;q=0.9", "en", an
        // unsupported "fr" and no header at all all serve "en", so a retry with any of
        // them must replay rather than mismatch.
        return $this->locale->current($request)->locale;
    }

    private function executeOnce(
        Request $request,
        Closure $next,
        IdempotencyClaim $claim,
        int $maxHandlerSeconds,
    ): Response {
        $startedAt = hrtime(true);

        try {
            $response = $this->ensureResponse($next($request));
        } catch (\Throwable $throwable) {
            // The handler failed before answering: free the key so a retry can run it.
            $this->abandon($claim);

            throw $throwable;
        }

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        try {
            // Past the budget the lease may have expired mid-flight, so another request
            // could have run too: seal so retries get 409 instead of running a third time.
            if ($elapsedSeconds > $maxHandlerSeconds) {
                $this->store->sealUnreplayable($claim, new \DateTimeImmutable);

                return $this->unavailable($request);
            }

            $body = $response->getContent();

            if ($body === false || ! $this->isStorable($response, $body)) {
                $this->abandon($claim);

                return $response;
            }

            // Oversize: seal completed-without-body so retries get 409 and never
            // re-run the handler; the first client still receives its response (Q3).
            if (strlen($body) > $this->maxResponseBytes) {
                $this->store->sealUnreplayable($claim, new \DateTimeImmutable);

                return $response;
            }

            $headers = $this->headerSanitizer->extract($response);
            $replay = new ReplayableResponse($response->getStatusCode(), $headers, $body);

            // complete() seals unreplayable on persistence failure before throwing.
            $this->store->complete($claim, $replay, new \DateTimeImmutable);

            return $response;
        } catch (IdempotencyStoreException) {
            return $this->unavailable($request);
        }
    }

    /**
     * Whether a response may be stored and replayed. Streams and files never get
     * here: their getContent() is false.
     */
    private function isStorable(Response $response, string $body): bool
    {
        if ($this->headerSanitizer->responseHasDeniedHeaders($response) || ! mb_check_encoding($body, 'UTF-8')) {
            return false;
        }

        $status = $response->getStatusCode();

        // Final answers only: 2xx and 4xx. 429 and 5xx are transient, 1xx and 3xx not final.
        return ($status >= 200 && $status < 300) || ($status >= 400 && $status < 500 && $status !== 429);
    }

    private function replayResponse(Request $request, ?ReplayableResponse $replay): Response
    {
        if ($replay === null) {
            return $this->unavailable($request);
        }

        $headers = $replay->headers;
        $current = $this->requestIds->current();

        if ($current !== null) {
            $headers[RequestId::HEADER] = $current->value;
        }

        // Empty name disables the header the same as null.
        if ($this->replayHeaderName !== null && $this->replayHeaderName !== '') {
            $headers[$this->replayHeaderName] = 'true';
        }

        return new Response($replay->body, $replay->status, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function reject(Request $request, ErrorCode $code, int $status, array $headers = []): Response
    {
        $rejection = new IdempotencyRejection($code, $status, $headers);

        return $this->errorRenderer->render($rejection, $request);
    }

    /**
     * Best effort: the key goes stale on its own, and a store failure here must never
     * replace the handler's response or hide its exception.
     */
    private function abandon(IdempotencyClaim $claim): void
    {
        try {
            $this->store->abandon($claim, new \DateTimeImmutable);
        } catch (\Throwable) {
            // The handler's answer is what the client must see.
        }
    }

    private function unavailable(Request $request): Response
    {
        return $this->reject(
            $request,
            ErrorCode::IdempotencyUnavailable,
            503,
            ['Retry-After' => (string) $this->retryAfterSeconds],
        );
    }

    private function ensureResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        throw new \UnexpectedValueException('HandleIdempotencyMiddleware expected a Symfony Response from $next.');
    }
}
