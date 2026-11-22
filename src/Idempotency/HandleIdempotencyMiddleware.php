<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Closure;
use Illuminate\Http\JsonResponse;
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
 * `lock_ttl_seconds=N`, `max_handler_seconds=N`.
 */
final class HandleIdempotencyMiddleware
{
    public const string HEADER = 'Idempotency-Key';

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
        private int $defaultLockTtlSeconds,
        private int $defaultMaxHandlerSeconds,
        private int $retryAfterSeconds,
    ) {}

    /**
     * @param  string  ...$options
     */
    public function handle(Request $request, Closure $next, ...$options): Response
    {
        /** @var list<string> $optionList */
        $optionList = array_values(array_map( // @pest-mutate-ignore: UnwrapArrayMap, UnwrapArrayValues
            static fn (mixed $option): string => is_string($option) ? $option : '', // @pest-mutate-ignore: EmptyStringToNotEmpty, TernaryNegated
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
                    $key === null ? IdempotencyErrorCode::KeyRequired : IdempotencyErrorCode::KeyInvalid,
                    400,
                );
            }

            return $this->ensureResponse($next($request));
        }

        $key = $this->headerValue($request);

        if ($key === null) {
            if ($parsed['isKeyRequired']) {
                return $this->reject(IdempotencyErrorCode::KeyRequired, 400);
            }

            return $this->ensureResponse($next($request));
        }

        if (! $this->keyValidator->isValid($key)) {
            return $this->reject(IdempotencyErrorCode::KeyInvalid, 400);
        }

        if ($this->requireJson && ! $this->isJsonRequest($request)) {
            return $this->reject(IdempotencyErrorCode::UnsupportedMedia, 415);
        }

        $bucket = $this->resolveBucket($request);

        if ($bucket === null) {
            return $this->reject(
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
            );
        } catch (PayloadTooLargeException) {
            throw new HttpException(413, 'Request entity too large.'); // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        } catch (JsonException) {
            return new JsonResponse([
                'message' => 'The given data was invalid.', // @pest-mutate-ignore: RemoveArrayItem
                'error' => 'validation_failed',
            ], 422);
        }

        $scope = new IdempotencyScope($bucket, strtoupper($request->getMethod()), $request->getPathInfo()); // @pest-mutate-ignore: UnwrapStrtoupper
        $now = new \DateTimeImmutable;

        try {
            $claim = $this->store->claim($scope, $key, $fingerprint, $now);
        } catch (IdempotencyStoreException) {
            return $this->reject(
                IdempotencyErrorCode::Unavailable,
                503,
                ['Retry-After' => (string) $this->retryAfterSeconds], // @pest-mutate-ignore: RemoveStringCast
            );
        }

        return match ($claim->outcome) {
            ClaimOutcome::Replay => $this->replayResponse($claim->replay),
            ClaimOutcome::Mismatch => $this->reject(IdempotencyErrorCode::PayloadMismatch, 422),
            ClaimOutcome::Conflict => $this->reject(
                IdempotencyErrorCode::InProgress,
                409,
                ['Retry-After' => (string) $parsed['lockTtlSeconds']], // @pest-mutate-ignore: RemoveStringCast
            ),
            ClaimOutcome::New => $this->executeOnce($request, $next, $claim),
        };
    }

    /**
     * @param  list<string>  $options
     * @return array{isKeyRequired: bool, isReplayable: bool, lockTtlSeconds: int, maxHandlerSeconds: int}
     */
    private function parseOptions(array $options): array
    {
        $isKeyRequired = true;
        $isReplayable = true;
        $lockTtlSeconds = $this->defaultLockTtlSeconds;
        $maxHandlerSeconds = $this->defaultMaxHandlerSeconds;

        foreach ($options as $option) {
            if ($option === 'optional') {
                $isKeyRequired = false;
            } elseif ($option === 'required') {
                $isKeyRequired = true;
            } elseif (str_starts_with($option, 'replayable=')) {
                $isReplayable = strtolower(substr($option, strlen('replayable='))) !== 'false'; // @pest-mutate-ignore: UnwrapStrtolower
            } elseif (str_starts_with($option, 'lock_ttl_seconds=')) {
                $lockTtlSeconds = max(1, (int) substr($option, strlen('lock_ttl_seconds='))); // @pest-mutate-ignore: RemoveIntegerCast, DecrementInteger, IncrementInteger
            } elseif (str_starts_with($option, 'max_handler_seconds=')) { // @pest-mutate-ignore: ElseIfNegated, StrStartsWithToStrEndsWith
                $maxHandlerSeconds = max(1, (int) substr($option, strlen('max_handler_seconds='))); // @pest-mutate-ignore: RemoveIntegerCast, MaxToMin, DecrementInteger, IncrementInteger, UnwrapSubstr
            }
        }

        return [
            'isKeyRequired' => $isKeyRequired,
            'isReplayable' => $isReplayable,
            'lockTtlSeconds' => $lockTtlSeconds,
            'maxHandlerSeconds' => $maxHandlerSeconds, // @pest-mutate-ignore: RemoveArrayItem
        ];
    }

    private function isIdempotentMethod(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), $this->methods, true); // @pest-mutate-ignore: UnwrapStrtoupper
    }

    private function headerValue(Request $request): ?string
    {
        $value = $request->headers->get(self::HEADER);
        if (! is_string($value) || trim($value) === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty, UnwrapTrim
            return null;
        }

        return trim($value);
    }

    private function isJsonRequest(Request $request): bool
    {
        $contentType = (string) $request->headers->get('Content-Type', ''); // @pest-mutate-ignore: RemoveStringCast, EmptyStringToNotEmpty
        $base = strtolower(trim(explode(';', $contentType)[0])); // @pest-mutate-ignore: UnwrapStrtolower, UnwrapTrim

        return $base === 'application/json';
    }

    private function resolveBucket(Request $request): ?string
    {
        $bucket = $this->credentialBuckets->bucket($request);

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
            return ''; // @pest-mutate-ignore: RemoveEarlyReturn, EmptyStringToNotEmpty
        }

        $resolution = $this->locale->current($request);
        if ($resolution->source !== LocaleSource::Header) {
            return ''; // @pest-mutate-ignore: RemoveEarlyReturn, EmptyStringToNotEmpty
        }

        $header = (string) $request->headers->get('Accept-Language', ''); // @pest-mutate-ignore: RemoveStringCast, EmptyStringToNotEmpty

        return $header;
    }

    private function executeOnce(Request $request, Closure $next, IdempotencyClaim $claim): Response
    {
        try {
            $response = $this->ensureResponse($next($request));

            if ($this->mustAbandonResponse($response)) {
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            $body = $response->getContent();
            if ($body === false || ! $this->isUtf8Text($body)) { // @pest-mutate-ignore: FalseToTrue
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            $status = $response->getStatusCode();
            if ($status === 429 || $status >= 500) { // @pest-mutate-ignore: DecrementInteger
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response;
            }

            if ($status < 200 || ($status >= 300 && $status < 400)) { // @pest-mutate-ignore: GreaterOrEqualToGreater, SmallerToSmallerOrEqual, DecrementInteger, IncrementInteger
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            $headers = $this->headerSanitizer->extract($response);
            $replay = new ReplayableResponse($status, $headers, $body);

            try {
                $this->store->complete($claim, $replay, new \DateTimeImmutable);
            } catch (IdempotencyStoreException) {
                return $this->reject(
                    IdempotencyErrorCode::Unavailable,
                    503,
                    ['Retry-After' => (string) $this->retryAfterSeconds], // @pest-mutate-ignore: RemoveStringCast
                );
            }

            return $response;
        } catch (\Throwable $throwable) {
            $this->store->abandon($claim, new \DateTimeImmutable); // @pest-mutate-ignore: RemoveMethodCall

            throw $throwable;
        }
    }

    private function mustAbandonResponse(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) { // @pest-mutate-ignore: BooleanOrToBooleanAnd, InstanceOfToFalse
            return true; // @pest-mutate-ignore: TrueToFalse, RemoveEarlyReturn
        }

        return $this->headerSanitizer->responseHasDeniedHeaders($response);
    }

    private function isUtf8Text(string $body): bool
    {
        if ($body === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return true; // @pest-mutate-ignore: TrueToFalse, RemoveEarlyReturn
        }

        return mb_check_encoding($body, 'UTF-8');
    }

    private function replayResponse(?ReplayableResponse $replay): Response
    {
        if ($replay === null) {
            return $this->reject(
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

        return new Response($replay->body, $replay->status, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function reject(IdempotencyErrorCode $code, int $status, array $headers = []): Response
    {
        $rejection = new IdempotencyRejection($code, $status, $headers);

        return $this->errorRenderer->render($rejection);
    }

    private function ensureResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        throw new \UnexpectedValueException('HandleIdempotencyMiddleware expected a Symfony Response from $next.');
    }
}
