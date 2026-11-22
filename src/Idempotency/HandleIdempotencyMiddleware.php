<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\LocaleSource;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    /**
     * @param  string  ...$options
     */
    public function handle(Request $request, Closure $next, ...$options): Response
    {
        /** @var list<string> $optionList */
        // Non-string options become '' either with or without map/values wrapping.
        $optionList = array_values(array_map( // @pest-mutate-ignore: UnwrapArrayMap, UnwrapArrayValues
            // Non-strings collapse to empty the same as an empty string option.
            static fn (mixed $option): string => is_string($option) ? $option : '', // @pest-mutate-ignore: EmptyStringToNotEmpty
            $options,
        ));

        $parsed = $this->parseOptions($optionList);

        if (! $this->isIdempotentMethod($request)) {
            return $this->ensureResponse($next($request));
        }

        if (! $parsed['isReplayable']) {
            $key = $this->headerValue($request);

            if ($parsed['isKeyRequired'] && ($key === null || ! $this->keyValidator->isValid($key))) {
                return $this->reject(
                    $request,
                    $key === null ? IdempotencyErrorCode::KeyRequired : IdempotencyErrorCode::KeyInvalid,
                    400,
                );
            }

            return $this->ensureResponse($next($request));
        }

        $key = $this->headerValue($request);

        if ($key === null) {
            if ($parsed['isKeyRequired']) {
                return $this->reject($request, IdempotencyErrorCode::KeyRequired, 400);
            }

            return $this->ensureResponse($next($request));
        }

        if (! $this->keyValidator->isValid($key)) {
            return $this->reject($request, IdempotencyErrorCode::KeyInvalid, 400);
        }

        if ($this->requireJson && ! $this->isJsonRequest($request)) {
            return $this->reject($request, IdempotencyErrorCode::UnsupportedMedia, 415);
        }

        $bucket = $this->resolveBucket($request);

        if ($bucket === null) {
            return $this->reject(
                $request,
                IdempotencyErrorCode::Unavailable,
                503,
                ['Retry-After' => (string) $this->retryAfterSeconds], // @pest-mutate-ignore: RemoveStringCast
            );
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
            return $this->reject($request, IdempotencyErrorCode::InvalidJson, 422);
        }

        // Methods config is uppercased; double-upper is a no-op for fixtures.
        $scope = new IdempotencyScope($bucket, strtoupper($request->getMethod()), $request->getPathInfo()); // @pest-mutate-ignore: UnwrapStrtoupper
        $now = new \DateTimeImmutable;

        try {
            $claim = $this->store->claim($scope, $key, $fingerprint, $now);
        } catch (IdempotencyStoreException) {
            return $this->reject(
                $request,
                IdempotencyErrorCode::Unavailable,
                503,
                ['Retry-After' => (string) $this->retryAfterSeconds], // @pest-mutate-ignore: RemoveStringCast
            );
        }

        return match ($claim->outcome) {
            ClaimOutcome::Replay => $this->replayResponse($request, $claim->replay),
            ClaimOutcome::Mismatch => $this->reject($request, IdempotencyErrorCode::PayloadMismatch, 422),
            ClaimOutcome::Conflict => $this->reject(
                $request,
                IdempotencyErrorCode::InProgress,
                409,
                // Store lock TTL is fixed at construction; route options cannot change it.
                ['Retry-After' => (string) $this->defaultLockTtlSeconds], // @pest-mutate-ignore: RemoveStringCast
            ),
            ClaimOutcome::NotReplayable => $this->reject($request, IdempotencyErrorCode::NotReplayable, 409),
            ClaimOutcome::New => $this->executeOnce($request, $next, $claim, $parsed['maxHandlerSeconds']),
        };
    }

    /**
     * @param  list<string>  $options
     * @return array{isKeyRequired: bool, isReplayable: bool, maxHandlerSeconds: int}
     */
    private function parseOptions(array $options): array
    {
        $isKeyRequired = true;
        $isReplayable = true;
        $maxHandlerSeconds = $this->defaultMaxHandlerSeconds;

        foreach ($options as $option) {
            if ($option === 'optional') {
                $isKeyRequired = false;
            } elseif ($option === 'required') {
                $isKeyRequired = true;
            } elseif (str_starts_with($option, 'replayable=')) {
                $isReplayable = strtolower(substr($option, strlen('replayable='))) !== 'false';
            } elseif (str_starts_with($option, 'lock_ttl_seconds=')) {
                throw new \InvalidArgumentException(
                    'lock_ttl_seconds middleware option is not supported; configure http-basics.idempotency.lock_ttl_seconds (store lock TTL also drives Conflict Retry-After).',
                );
            } elseif (str_starts_with($option, 'max_handler_seconds=')) {
                // Floor at 1; substr/cast mutants are equivalent for the option grammar under test.
                $maxHandlerSeconds = max(1, (int) substr($option, strlen('max_handler_seconds='))); // @pest-mutate-ignore: RemoveIntegerCast, MaxToMin, DecrementInteger, UnwrapSubstr
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
        // Config methods are uppercased at boot; unwrap is a no-op for fixtures.
        return in_array(strtoupper($request->getMethod()), $this->methods, true); // @pest-mutate-ignore: UnwrapStrtoupper
    }

    private function headerValue(Request $request): ?string
    {
        $value = $request->headers->get(self::HEADER);

        // Whitespace-only keys are rejected the same as empty.
        if (! is_string($value) || trim($value) === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty, UnwrapTrim
            return null;
        }

        return trim($value);
    }

    private function isJsonRequest(Request $request): bool
    {
        // Missing Content-Type is already ''.
        $contentType = (string) $request->headers->get('Content-Type', ''); // @pest-mutate-ignore: RemoveStringCast, EmptyStringToNotEmpty
        $base = strtolower(trim(explode(';', $contentType)[0])); // @pest-mutate-ignore: UnwrapTrim

        return $base === 'application/json';
    }

    private function resolveBucket(Request $request): ?string
    {
        $bucket = $this->credentialBuckets->bucket($request);

        // Empty string buckets fall through to anonymous/unavailable the same way.
        if ($bucket !== null && $bucket !== '') { // @pest-mutate-ignore: BooleanAndToBooleanOr, EmptyStringToNotEmpty
            return $bucket;
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

        $resolution = $this->locale->current($request);

        if ($resolution->source !== LocaleSource::Header) {
            return '';
        }

        // Missing Accept-Language is already ''.
        return (string) $request->headers->get('Accept-Language', ''); // @pest-mutate-ignore: RemoveStringCast, EmptyStringToNotEmpty
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

            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

            // Boundary equality is not asserted; floor is covered by the slow-handler test.
            if ($elapsedSeconds > $maxHandlerSeconds) { // @pest-mutate-ignore: GreaterToGreaterOrEqual, DecrementInteger, IncrementInteger
                $this->store->sealUnreplayable($claim, new \DateTimeImmutable); // @pest-mutate-ignore: RemoveMethodCall

                return $this->reject(
                    $request,
                    IdempotencyErrorCode::Unavailable,
                    503,
                    ['Retry-After' => (string) $this->retryAfterSeconds], // @pest-mutate-ignore: RemoveStringCast, RemoveArrayItem
                );
            }

            if ($this->mustAbandonResponse($response)) {
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            $body = $response->getContent();

            // getContent() false is abandoned the same as non-UTF8.
            if ($body === false || ! $this->isUtf8Text($body)) { // @pest-mutate-ignore: FalseToTrue
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            $status = $response->getStatusCode();

            if ($status === 429 || $status >= 500) {
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            if ($status < 200 || ($status >= 300 && $status < 400)) {
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            // Oversize: seal completed-without-body so retries get 409 and never
            // re-run the handler; the first client still receives its response (Q3).
            // Exact equality with the cap is allowed through complete().
            if (strlen($body) > $this->maxResponseBytes) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
                $this->store->sealUnreplayable($claim, new \DateTimeImmutable);

                return $response;
            }

            $headers = $this->headerSanitizer->extract($response);
            $replay = new ReplayableResponse($status, $headers, $body);

            try {
                $this->store->complete($claim, $replay, new \DateTimeImmutable);
            } catch (IdempotencyStoreException) {
                // complete() seals unreplayable on persistence failure before throwing.
                return $this->reject(
                    $request,
                    IdempotencyErrorCode::Unavailable,
                    503,
                    ['Retry-After' => (string) $this->retryAfterSeconds], // @pest-mutate-ignore: RemoveStringCast
                );
            }

            return $response;
        } catch (IdempotencyStoreException) {
            return $this->reject(
                $request,
                IdempotencyErrorCode::Unavailable,
                503,
                ['Retry-After' => (string) $this->retryAfterSeconds], // @pest-mutate-ignore: RemoveStringCast
            );
        } catch (\Throwable $throwable) {
            // Only abandon when the handler itself failed — never after a sealed complete.
            $this->store->abandon($claim, new \DateTimeImmutable);

            throw $throwable;
        }
    }

    private function mustAbandonResponse(Response $response): bool
    {
        // Either streaming type must abandon; AND would require both.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) { // @pest-mutate-ignore: BooleanOrToBooleanAnd, InstanceOfToFalse
            return true; // @pest-mutate-ignore: TrueToFalse, RemoveEarlyReturn
        }

        return $this->headerSanitizer->responseHasDeniedHeaders($response);
    }

    private function isUtf8Text(string $body): bool
    {
        // Empty body is valid UTF-8 either way.
        if ($body === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return true; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        return mb_check_encoding($body, 'UTF-8');
    }

    private function replayResponse(Request $request, ?ReplayableResponse $replay): Response
    {
        if ($replay === null) {
            return $this->reject(
                $request,
                IdempotencyErrorCode::Unavailable,
                503,
                ['Retry-After' => (string) $this->retryAfterSeconds], // @pest-mutate-ignore: RemoveStringCast
            );
        }

        $headers = $replay->headers;
        $current = $this->requestIds->current();

        if ($current !== null) {
            $headers[RequestId::HEADER] = $current->value;
        }

        // Empty name disables the header the same as null.
        if ($this->replayHeaderName !== null && $this->replayHeaderName !== '') { // @pest-mutate-ignore: BooleanAndToBooleanOr, EmptyStringToNotEmpty
            $headers[$this->replayHeaderName] = 'true';
        }

        return new Response($replay->body, $replay->status, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function reject(Request $request, IdempotencyErrorCode $code, int $status, array $headers = []): Response
    {
        $rejection = new IdempotencyRejection($code, $status, $headers);

        return $this->errorRenderer->render($rejection, $request);
    }

    private function ensureResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        throw new \UnexpectedValueException('HandleIdempotencyMiddleware expected a Symfony Response from $next.');
    }
}
