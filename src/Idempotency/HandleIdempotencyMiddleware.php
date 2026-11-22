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
        private int $maxResponseBytes,
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
        $optionList = array_values(array_map(
            static fn (mixed $option): string => is_string($option) ? $option : '',
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
                ['Retry-After' => (string) $this->retryAfterSeconds],
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
            throw new HttpException(413, 'Request entity too large.');
        } catch (JsonException) {
            return $this->reject(IdempotencyErrorCode::InvalidJson, 422);
        }

        $scope = new IdempotencyScope($bucket, strtoupper($request->getMethod()), $request->getPathInfo());
        $now = new \DateTimeImmutable;

        try {
            $claim = $this->store->claim($scope, $key, $fingerprint, $now);
        } catch (IdempotencyStoreException) {
            return $this->reject(
                IdempotencyErrorCode::Unavailable,
                503,
                ['Retry-After' => (string) $this->retryAfterSeconds],
            );
        }

        return match ($claim->outcome) {
            ClaimOutcome::Replay => $this->replayResponse($claim->replay),
            ClaimOutcome::Mismatch => $this->reject(IdempotencyErrorCode::PayloadMismatch, 422),
            ClaimOutcome::Conflict => $this->reject(
                IdempotencyErrorCode::InProgress,
                409,
                ['Retry-After' => (string) $parsed['lockTtlSeconds']],
            ),
            ClaimOutcome::NotReplayable => $this->reject(IdempotencyErrorCode::NotReplayable, 409),
            ClaimOutcome::New => $this->executeOnce($request, $next, $claim, $parsed['maxHandlerSeconds']),
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
                $isReplayable = strtolower(substr($option, strlen('replayable='))) !== 'false';
            } elseif (str_starts_with($option, 'lock_ttl_seconds=')) {
                $lockTtlSeconds = max(1, (int) substr($option, strlen('lock_ttl_seconds=')));
            } elseif (str_starts_with($option, 'max_handler_seconds=')) {
                $maxHandlerSeconds = max(1, (int) substr($option, strlen('max_handler_seconds=')));
            }
        }

        return [
            'isKeyRequired' => $isKeyRequired,
            'isReplayable' => $isReplayable,
            'lockTtlSeconds' => $lockTtlSeconds,
            'maxHandlerSeconds' => $maxHandlerSeconds,
        ];
    }

    private function isIdempotentMethod(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), $this->methods, true);
    }

    private function headerValue(Request $request): ?string
    {
        $value = $request->headers->get(self::HEADER);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function isJsonRequest(Request $request): bool
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        $base = strtolower(trim(explode(';', $contentType)[0]));

        return $base === 'application/json';
    }

    private function resolveBucket(Request $request): ?string
    {
        $bucket = $this->credentialBuckets->bucket($request);

        if ($bucket !== null && $bucket !== '') {
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

        return (string) $request->headers->get('Accept-Language', '');
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

            if ($elapsedSeconds > $maxHandlerSeconds) {
                $this->store->sealUnreplayable($claim, new \DateTimeImmutable);

                return $this->reject(
                    IdempotencyErrorCode::Unavailable,
                    503,
                    ['Retry-After' => (string) $this->retryAfterSeconds],
                );
            }

            if ($this->mustAbandonResponse($response)) {
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response;
            }

            $body = $response->getContent();

            if ($body === false || ! $this->isUtf8Text($body)) {
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response;
            }

            $status = $response->getStatusCode();

            if ($status === 429 || $status >= 500) {
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response;
            }

            if ($status < 200 || ($status >= 300 && $status < 400)) {
                $this->store->abandon($claim, new \DateTimeImmutable);

                return $response;
            }

            if (strlen($body) > $this->maxResponseBytes) {
                $this->store->sealUnreplayable($claim, new \DateTimeImmutable);

                return $this->reject(
                    IdempotencyErrorCode::Unavailable,
                    503,
                    ['Retry-After' => (string) $this->retryAfterSeconds],
                );
            }

            $headers = $this->headerSanitizer->extract($response);
            $replay = new ReplayableResponse($status, $headers, $body);

            try {
                $this->store->complete($claim, $replay, new \DateTimeImmutable);
            } catch (IdempotencyStoreException) {
                // complete() seals unreplayable on persistence failure before throwing.
                return $this->reject(
                    IdempotencyErrorCode::Unavailable,
                    503,
                    ['Retry-After' => (string) $this->retryAfterSeconds],
                );
            }

            return $response;
        } catch (IdempotencyStoreException) {
            return $this->reject(
                IdempotencyErrorCode::Unavailable,
                503,
                ['Retry-After' => (string) $this->retryAfterSeconds],
            );
        } catch (\Throwable $throwable) {
            // Only abandon when the handler itself failed — never after a sealed complete.
            $this->store->abandon($claim, new \DateTimeImmutable);

            throw $throwable;
        }
    }

    private function mustAbandonResponse(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return true;
        }

        return $this->headerSanitizer->responseHasDeniedHeaders($response);
    }

    private function isUtf8Text(string $body): bool
    {
        if ($body === '') {
            return true;
        }

        return mb_check_encoding($body, 'UTF-8');
    }

    private function replayResponse(?ReplayableResponse $replay): Response
    {
        if ($replay === null) {
            return $this->reject(
                IdempotencyErrorCode::Unavailable,
                503,
                ['Retry-After' => (string) $this->retryAfterSeconds],
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
