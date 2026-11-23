<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Errors\ErrorCode;
use Qiick\HttpBasics\Idempotency\AnonymousBucketResolver;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\Fingerprinter;
use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyValidator;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\NullCredentialBucketResolver;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Idempotency\ReplayHeaderSanitizer;
use Qiick\HttpBasics\Locale\ConfigDefaultLocaleResolver;
use Qiick\HttpBasics\Locale\ConfigSupportedLocales;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\LocaleResolution;
use Qiick\HttpBasics\Locale\LocaleSource;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\RequestId\RequestIdSource;
use Qiick\HttpBasics\Testing\FakeCredentialBucketResolver;
use Qiick\HttpBasics\Testing\InMemoryIdempotencyStore;
use Qiick\HttpBasics\Tests\Support\FakeHighResolutionClock;
use Qiick\HttpBasics\Tests\Support\FaultyCacheRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->store = new InMemoryIdempotencyStore;
    $this->app->forgetInstance(IdempotencyStore::class);
    $this->app->instance(IdempotencyStore::class, $this->store);
    $this->app->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver('tenant:1'));
});

function idempotencyRequest(string $body = '{"name":"a"}', string $method = 'POST'): Request
{
    return Request::create(
        '/api/widgets',
        $method,
        [],
        [],
        [],
        [
            'HTTP_Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440010',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $body,
    );
}

/**
 * @param  list<string>  $methods
 */
function makeIdempotencyMiddleware(
    IdempotencyStore $store,
    CredentialBucketResolver $credentials,
    bool $anonymousEnabled = false,
    array $methods = ['POST', 'PATCH'],
    bool $requireJson = true,
    int $retryAfterSeconds = 30,
    int $maxBodyBytes = 1048576,
    ?ReplayHeaderSanitizer $headerSanitizer = null,
    ?ErrorRenderer $errorRenderer = null,
    int $maxResponseBytes = 1048576,
    ?string $replayHeaderName = HandleIdempotencyMiddleware::DEFAULT_REPLAY_HEADER,
): HandleIdempotencyMiddleware {
    return new HandleIdempotencyMiddleware(
        $store,
        new Fingerprinter,
        new IdempotencyKeyValidator(IdempotencyKeyFormat::Uuid),
        $credentials,
        new AnonymousBucketResolver(str_repeat('s', 32), ['ip', 'route']),
        $anonymousEnabled,
        $errorRenderer ?? new FleetErrorRenderer,
        $headerSanitizer ?? new ReplayHeaderSanitizer(['Content-Type'], ['Set-Cookie']),
        app(RequestIdResolver::class),
        $doors = new MachineDoorClassifier(['api/*']),
        new LocaleNegotiator(
            new ConfigSupportedLocales(['en', 'it']),
            new ConfigDefaultLocaleResolver('en'),
            $doors,
        ),
        $methods,
        $requireJson,
        $maxBodyBytes,
        $maxResponseBytes,
        60,
        55,
        $retryAfterSeconds,
        $replayHeaderName,
    );
}

it('rejects malformed keys before touching the store', function (): void {
    $request = idempotencyRequest();
    $request->headers->set('Idempotency-Key', 'not-valid');

    $middleware = app(HandleIdempotencyMiddleware::class);
    $response = $middleware->handle($request, static fn () => response()->noContent());

    expect($response->getStatusCode())->toBe(400);
});

it('passes through optional routes without a key', function (): void {
    $request = Request::create(
        '/api/widgets',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        '{}',
    );

    $middleware = app(HandleIdempotencyMiddleware::class);
    $response = $middleware->handle($request, static fn () => response()->json(['ok' => true]), 'optional');

    expect($response->getStatusCode())->toBe(200);
});

it('abandons streamed responses without completing the claim', function (): void {
    $middleware = app(HandleIdempotencyMiddleware::class);
    $request = idempotencyRequest();

    $middleware->handle($request, static fn () => new StreamedResponse(function (): void {
        echo 'stream';
    }));

    $scope = new IdempotencyScope(
        'tenant:1',
        'POST',
        '/api/widgets',
    );
    $again = $this->store->claim(
        $scope,
        '550e8400-e29b-41d4-a716-446655440010',
        app(Fingerprinter::class)->fingerprint($request, 1048576),
        new DateTimeImmutable,
    );

    expect($again->outcome)->toBe(ClaimOutcome::New);
});

it('rethrows exceptions after abandoning the in-flight claim', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = idempotencyRequest();
    $request->headers->set('Idempotency-Key', '550e8400-e29b-41d4-a716-446655440099');

    expect(fn () => $middleware->handle($request, static function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    $again = $this->store->claim(
        new IdempotencyScope('tenant:1', 'POST', '/api/widgets'),
        '550e8400-e29b-41d4-a716-446655440099',
        'fp',
        new DateTimeImmutable,
    );
    expect($again->outcome)->toBe(ClaimOutcome::New);
});

it('passes non-idempotent methods straight through', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = Request::create('/api/widgets', 'GET');

    $response = $middleware->handle($request, static fn () => response('ok', 200));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ok');
});

it('distinguishes missing and invalid keys on non-replayable required routes', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    $missing = Request::create('/api/widgets', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $missingResponse = $middleware->handle($missing, static fn () => response('x'), 'replayable=false');
    expect($missingResponse->getStatusCode())->toBe(400)
        ->and($missingResponse->getData(true)['error'])->toBe('idempotency_key_required');

    $invalid = idempotencyRequest();
    $invalid->headers->set('Idempotency-Key', 'bad');
    $invalidResponse = $middleware->handle($invalid, static fn () => response('x'), 'replayable=false');
    expect($invalidResponse->getStatusCode())->toBe(400)
        ->and($invalidResponse->getData(true)['error'])->toBe('idempotency_key_invalid');
});

it('requires a key when the route says required', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = Request::create('/api/widgets', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

    $response = $middleware->handle($request, static fn () => response('x'), 'required');

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['error'])->toBe('idempotency_key_required');
});

it('treats replayable=FALSE as disabled case-insensitively', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = idempotencyRequest();

    $response = $middleware->handle($request, static fn () => response('once', 201), 'replayable=FALSE');

    expect($response->getContent())->toBe('once');
    $again = $middleware->handle($request, static fn () => response('twice', 201), 'replayable=FALSE');
    expect($again->getContent())->toBe('twice');
});

it('clamps max handler seconds to at least one and uses the store lock for Conflict Retry-After', function (): void {
    $store = new class implements IdempotencyStore
    {
        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void {}

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void {}

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void {}
    };

    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'));
    $response = $middleware->handle(
        idempotencyRequest(),
        static fn () => response('x'),
        'max_handler_seconds=-5',
    );

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Retry-After'))->toBe('60');
});

it('rejects the lock_ttl_seconds middleware option', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    expect(fn () => $middleware->handle(
        idempotencyRequest(),
        static fn () => response('x'),
        'lock_ttl_seconds=9',
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects max_handler_seconds that meet or exceed the store lock TTL before running the handler', function (): void {
    $runs = 0;
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    expect(fn () => $middleware->handle(
        idempotencyRequest(),
        static function () use (&$runs) {
            $runs++;

            return response('x');
        },
        'max_handler_seconds=60',
    ))->toThrow(InvalidArgumentException::class);

    expect($runs)->toBe(0);

    expect(fn () => $middleware->handle(
        idempotencyRequest(),
        static function () use (&$runs) {
            $runs++;

            return response('x');
        },
        'max_handler_seconds=120',
    ))->toThrow(InvalidArgumentException::class);

    expect($runs)->toBe(0);
});

it('abandons status boundaries around the completable window', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $keyBase = '550e8400-e29b-41d4-a716-4466554401';

    foreach ([199 => '20', 200 => '21', 299 => '22', 300 => '23', 399 => '24', 400 => '25', 499 => '26'] as $status => $suffix) {
        $request = idempotencyRequest();
        $request->headers->set('Idempotency-Key', $keyBase.$suffix);
        $middleware->handle($request, static fn () => response('body', $status));
    }

    foreach (['20', '23', '24'] as $suffix) {
        $again = $this->store->claim(
            new IdempotencyScope('tenant:1', 'POST', '/api/widgets'),
            $keyBase.$suffix,
            'fp',
            new DateTimeImmutable,
        );
        expect($again->outcome)->toBe(ClaimOutcome::New);
    }

    foreach (['21', '22', '25', '26'] as $suffix) {
        $again = $this->store->claim(
            new IdempotencyScope('tenant:1', 'POST', '/api/widgets'),
            $keyBase.$suffix,
            'fp',
            new DateTimeImmutable,
        );
        expect($again->outcome)->toBe(ClaimOutcome::Mismatch);
    }
});

it('completes empty utf-8 bodies', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    $empty = idempotencyRequest();
    $empty->headers->set('Idempotency-Key', '550e8400-e29b-41d4-a716-446655440130');
    $middleware->handle($empty, static fn () => response('', 204));

    $second = $middleware->handle($empty, static fn () => response('fresh', 200));
    expect($second->getStatusCode())->toBe(204)
        ->and($second->getContent())->toBe('');
});

it('rejects missing keys on required non-replayable routes and passes valid keys through', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    $missing = Request::create('/api/widgets', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    expect($middleware->handle($missing, static fn () => response('x'), 'replayable=false')->getStatusCode())->toBe(400);

    $invalid = idempotencyRequest();
    $invalid->headers->set('Idempotency-Key', 'bad');
    expect($middleware->handle($invalid, static fn () => response('x'), 'replayable=false')->getStatusCode())->toBe(400);

    $valid = idempotencyRequest();
    expect($middleware->handle($valid, static fn () => response('once', 201), 'required', 'replayable=false')->getContent())->toBe('once');
});

it('uses the store lock TTL for Conflict Retry-After regardless of max_handler_seconds', function (): void {
    $store = new class implements IdempotencyStore
    {
        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void {}

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void {}

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void {}
    };

    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'));
    $response = $middleware->handle(
        idempotencyRequest(),
        static fn () => response('nope'),
        'max_handler_seconds=3',
        'required',
    );

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Retry-After'))->toBe('60');
});

it('rejects when credentials and anonymous buckets are unavailable', function (): void {
    $middleware = makeIdempotencyMiddleware(
        $this->store,
        new NullCredentialBucketResolver,
        anonymousEnabled: false,
        retryAfterSeconds: 17,
    );

    $response = $middleware->handle(idempotencyRequest(), static fn () => response('x'));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('17');
});

it('falls back to the anonymous bucket when credentials are empty', function (): void {
    $middleware = makeIdempotencyMiddleware(
        $this->store,
        new FakeCredentialBucketResolver(''),
        anonymousEnabled: true,
    );

    $response = $middleware->handle(
        idempotencyRequest(),
        static fn () => response()->json(['ok' => true], 201),
    );

    expect($response->getStatusCode())->toBe(201);
});

it('maps store claim failures to unavailable', function (): void {
    $store = new class implements IdempotencyStore
    {
        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            throw new IdempotencyStoreException('down');
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void {}

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void {}

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void {}
    };

    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'), retryAfterSeconds: 12);
    $response = $middleware->handle(idempotencyRequest(), static fn () => response('x'));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('12');
});

it('replays completed claims and overlays the current request id', function (): void {
    $store = new class implements IdempotencyStore
    {
        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            return new IdempotencyClaim(
                ClaimOutcome::Replay,
                new ReplayableResponse(200, ['Content-Type' => 'application/json'], '{"cached":true}'),
                null,
                $scope,
                $key,
            );
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void {}

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void {}

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void {}
    };

    Context::add(RequestIdResolver::VISIBLE_CONTEXT_KEY, '11111111-1111-4111-8111-111111111111');
    Context::addHidden('http_basics.request_id_source', RequestIdSource::Generated->value);

    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'));
    $response = $middleware->handle(idempotencyRequest(), static fn () => response('fresh'));

    expect($response->getContent())->toBe('{"cached":true}')
        ->and($response->headers->get(RequestId::HEADER))->toBe('11111111-1111-4111-8111-111111111111');
});

it('treats a null replay payload as unavailable', function (): void {
    $store = new class implements IdempotencyStore
    {
        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            return new IdempotencyClaim(ClaimOutcome::Replay, null, null, $scope, $key);
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void {}

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void {}

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void {}
    };

    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'), retryAfterSeconds: 4);
    $response = $middleware->handle(idempotencyRequest(), static fn () => response('x'));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('4');
});

it('maps mismatch claims to payload mismatch', function (): void {
    $store = new class implements IdempotencyStore
    {
        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            return new IdempotencyClaim(ClaimOutcome::Mismatch, null, null, $scope, $key);
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void {}

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void {}

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void {}
    };

    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'));
    expect($middleware->handle(idempotencyRequest(), static fn () => response('x'))->getStatusCode())->toBe(422);
});

it('turns oversized payloads into http 413', function (): void {
    $middleware = makeIdempotencyMiddleware(
        $this->store,
        new FakeCredentialBucketResolver('tenant:1'),
        maxBodyBytes: 8,
    );

    try {
        $middleware->handle(idempotencyRequest('{"name":"too-large"}'), static fn () => response('x'));
        expect(false)->toBeTrue();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(413);
    }
});

it('returns 422 when the fingerprinter rejects json', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $response = $middleware->handle(idempotencyRequest('{not-json'), static fn () => response('x'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toBe('validation_failed');
});

it('abandons binary file responses, non-utf8 bodies, 429, redirects and denied headers', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $keyBase = '550e8400-e29b-41d4-a716-4466554400';

    $tmp = tempnam(sys_get_temp_dir(), 'idem');
    expect($tmp)->not->toBeFalse();
    file_put_contents((string) $tmp, 'bin');

    $binaryRequest = idempotencyRequest();
    $binaryRequest->headers->set('Idempotency-Key', $keyBase.'11');
    $middleware->handle($binaryRequest, static fn () => new BinaryFileResponse((string) $tmp));

    $utfRequest = idempotencyRequest();
    $utfRequest->headers->set('Idempotency-Key', $keyBase.'12');
    $middleware->handle($utfRequest, static fn () => response("\xFF\xFE", 200));

    $rateRequest = idempotencyRequest();
    $rateRequest->headers->set('Idempotency-Key', $keyBase.'13');
    $middleware->handle($rateRequest, static fn () => response('slow', 429));

    $redirectRequest = idempotencyRequest();
    $redirectRequest->headers->set('Idempotency-Key', $keyBase.'14');
    $middleware->handle($redirectRequest, static fn () => response('go', 302));

    $deniedRequest = idempotencyRequest();
    $deniedRequest->headers->set('Idempotency-Key', $keyBase.'15');
    $middleware->handle($deniedRequest, static function () {
        $response = response('cookie', 200);
        $response->headers->set('Set-Cookie', 'a=b');

        return $response;
    });

    foreach (['11', '12', '13', '14', '15'] as $suffix) {
        $again = $this->store->claim(
            new IdempotencyScope('tenant:1', 'POST', '/api/widgets'),
            $keyBase.$suffix,
            'fp',
            new DateTimeImmutable,
        );
        expect($again->outcome)->toBe(ClaimOutcome::New);
    }

    @unlink((string) $tmp);
});

it('maps complete failures to unavailable after the handler succeeds', function (): void {
    $store = new class implements IdempotencyStore
    {
        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            return new IdempotencyClaim(ClaimOutcome::New, null, str_repeat('a', 64), $scope, $key);
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void
        {
            throw new IdempotencyStoreException('cannot complete');
        }

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void {}

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void {}
    };

    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'), retryAfterSeconds: 8);
    $response = $middleware->handle(idempotencyRequest(), static fn () => response('ok', 200));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('8');
});

it('rejects blank and whitespace-only idempotency keys', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = idempotencyRequest();
    $request->headers->set('Idempotency-Key', '   ');

    expect($middleware->handle($request, static fn () => response('x'))->getStatusCode())->toBe(400);
});

it('trims idempotency keys before validation', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = idempotencyRequest();
    $request->headers->set('Idempotency-Key', '  550e8400-e29b-41d4-a716-446655440010  ');

    $response = $middleware->handle($request, static fn () => response('ok', 200));

    expect($response->getContent())->toBe('ok');
});

it('allows non-json content type when require_json is off', function (): void {
    $middleware = makeIdempotencyMiddleware(
        $this->store,
        new FakeCredentialBucketResolver('tenant:1'),
        requireJson: false,
    );
    $request = Request::create(
        '/api/widgets',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440016',
            'CONTENT_TYPE' => 'text/plain',
        ],
        '{}',
    );

    expect($middleware->handle($request, static fn () => response('ok', 200))->getContent())->toBe('ok');
});

it('rejects non-response return values from $next', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    expect(fn () => $middleware->handle(idempotencyRequest(), static fn () => 'string'))
        ->toThrow(UnexpectedValueException::class);
});

it('includes accept-language in the fingerprint for machine doors', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    $withLanguage = idempotencyRequest();
    $withLanguage->headers->set('Accept-Language', 'it');
    $withLanguage->headers->set('Idempotency-Key', '550e8400-e29b-41d4-a716-446655440017');
    $middleware->handle($withLanguage, static fn () => response('first', 200));

    $withoutLanguage = idempotencyRequest();
    $withoutLanguage->headers->set('Idempotency-Key', '550e8400-e29b-41d4-a716-446655440017');
    $second = $middleware->handle($withoutLanguage, static fn () => response('second', 200));

    expect($second->getStatusCode())->toBe(422);
});

it('returns 409 not_replayable for sealed completed-without-body claims', function (): void {
    $store = new InMemoryIdempotencyStore;
    $scope = new IdempotencyScope('tenant:1', 'POST', '/api/widgets');
    $now = new DateTimeImmutable;
    $key = '550e8400-e29b-41d4-a716-446655440090';
    $claim = $store->claim($scope, $key, app(Fingerprinter::class)->fingerprint(idempotencyRequest(), 1048576), $now);
    $store->sealUnreplayable($claim, $now);

    $this->app->instance(IdempotencyStore::class, $store);
    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'));
    $request = idempotencyRequest();
    $request->headers->set('Idempotency-Key', $key);

    $response = $middleware->handle($request, static function (): never {
        throw new RuntimeException('handler must not run');
    });

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['error'])->toBe(ErrorCode::IdempotencyNotReplayable->value);
});

it('seals unreplayable when the response exceeds max_response_bytes', function (): void {
    $middleware = makeIdempotencyMiddleware(
        $this->store,
        new FakeCredentialBucketResolver('tenant:1'),
        maxBodyBytes: 1048576,
    );

    // Rebuild with tiny max_response_bytes
    $middleware = new HandleIdempotencyMiddleware(
        $this->store,
        new Fingerprinter,
        new IdempotencyKeyValidator(IdempotencyKeyFormat::Uuid),
        new FakeCredentialBucketResolver('tenant:1'),
        new AnonymousBucketResolver(str_repeat('s', 32), ['ip', 'route']),
        false,
        new FleetErrorRenderer,
        new ReplayHeaderSanitizer(['Content-Type'], ['Set-Cookie']),
        app(RequestIdResolver::class),
        $doors = new MachineDoorClassifier(['api/*']),
        new LocaleNegotiator(
            new ConfigSupportedLocales(['en', 'it']),
            new ConfigDefaultLocaleResolver('en'),
            $doors,
        ),
        ['POST', 'PATCH'],
        true,
        1048576,
        16,
        60,
        55,
        30,
    );

    $key = '550e8400-e29b-41d4-a716-446655440091';
    $request = idempotencyRequest();
    $request->headers->set('Idempotency-Key', $key);
    $runs = 0;

    $first = $middleware->handle($request, static function () use (&$runs) {
        $runs++;

        return response(str_repeat('y', 64), 201);
    });

    expect($first->getStatusCode())->toBe(201)
        ->and($first->getContent())->toBe(str_repeat('y', 64))
        ->and($runs)->toBe(1);

    $second = $middleware->handle($request, static function () use (&$runs) {
        $runs++;

        return response('nope', 201);
    });

    expect($second->getStatusCode())->toBe(409)
        ->and($second->getData(true)['error'])->toBe(ErrorCode::IdempotencyNotReplayable->value)
        ->and($runs)->toBe(1);
});

it('sets Idempotent-Replayed on cache replays', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $key = '550e8400-e29b-41d4-a716-446655440093';
    $request = idempotencyRequest();
    $request->headers->set('Idempotency-Key', $key);

    $first = $middleware->handle($request, static fn () => response('ok', 201, ['Content-Type' => 'text/plain']));
    $second = $middleware->handle($request, static fn () => response('nope', 201));

    expect($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($second->getStatusCode())->toBe(201)
        ->and($second->getContent())->toBe('ok')
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('enforces max_handler_seconds and seals unreplayable after a slow handler', function (): void {
    $middleware = new HandleIdempotencyMiddleware(
        $this->store,
        new Fingerprinter,
        new IdempotencyKeyValidator(IdempotencyKeyFormat::Uuid),
        new FakeCredentialBucketResolver('tenant:1'),
        new AnonymousBucketResolver(str_repeat('s', 32), ['ip', 'route']),
        false,
        new FleetErrorRenderer,
        new ReplayHeaderSanitizer(['Content-Type'], ['Set-Cookie']),
        app(RequestIdResolver::class),
        $doors = new MachineDoorClassifier(['api/*']),
        new LocaleNegotiator(
            new ConfigSupportedLocales(['en', 'it']),
            new ConfigDefaultLocaleResolver('en'),
            $doors,
        ),
        ['POST', 'PATCH'],
        true,
        1048576,
        1048576,
        60,
        55,
        30,
    );

    $key = '550e8400-e29b-41d4-a716-446655440092';
    $request = idempotencyRequest();
    $request->headers->set('Idempotency-Key', $key);
    $runs = 0;

    $first = $middleware->handle($request, static function () use (&$runs) {
        $runs++;
        usleep(1_200_000);

        return response('ok', 201);
    }, 'max_handler_seconds=1');

    expect($first->getStatusCode())->toBe(503)->and($runs)->toBe(1);

    $second = $middleware->handle($request, static function () use (&$runs) {
        $runs++;

        return response('again', 201);
    }, 'max_handler_seconds=1');

    // Sealed, not merely still locked: the retry is told the response is gone for good.
    expect($second->getStatusCode())->toBe(409)
        ->and($second->getData(true)['error'] ?? null)->toBe(ErrorCode::IdempotencyNotReplayable->value)
        ->and($runs)->toBe(1);
});

it('accepts application/json content type case-insensitively with parameters', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = Request::create(
        '/api/widgets',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440093',
            'CONTENT_TYPE' => 'Application/JSON; charset=utf-8',
        ],
        '{}',
    );

    expect($middleware->handle($request, static fn () => response('ok', 200))->getContent())->toBe('ok');
});

it('accepts application/merge-patch+json and other application/*+json types', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    $mergePatch = Request::create(
        '/api/widgets',
        'PATCH',
        [],
        [],
        [],
        [
            'HTTP_Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440094',
            'CONTENT_TYPE' => 'application/merge-patch+json',
        ],
        '{"name":"patched"}',
    );

    expect($middleware->handle($mergePatch, static fn () => response('patched', 200))->getContent())->toBe('patched');

    $problemJson = Request::create(
        '/api/widgets',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440095',
            'CONTENT_TYPE' => 'application/vnd.api+json',
        ],
        '{}',
    );

    expect($middleware->handle($problemJson, static fn () => response('ok', 200))->getContent())->toBe('ok');

    $notStructuredSuffix = Request::create(
        '/api/widgets',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440096',
            'CONTENT_TYPE' => 'application/jsonx',
        ],
        '{}',
    );

    expect($middleware->handle($notStructuredSuffix, static fn () => response('x', 200))->getStatusCode())->toBe(415);
});

it('falls through empty credential buckets to anonymous when enabled', function (): void {
    $middleware = makeIdempotencyMiddleware(
        $this->store,
        new FakeCredentialBucketResolver(''),
        anonymousEnabled: true,
    );

    $response = $middleware->handle(idempotencyRequest(), static fn () => response('anon', 201));

    expect($response->getContent())->toBe('anon');
});

it('maps claim QueryException-equivalent store failures to 503 with Retry-After', function (): void {
    $store = new class implements IdempotencyStore
    {
        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            throw new IdempotencyStoreException('Failed to read idempotency marker.');
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void {}

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void {}

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void {}
    };

    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'), retryAfterSeconds: 9);
    $response = $middleware->handle(idempotencyRequest(), static fn () => response('x'));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('9')
        ->and($response->getData(true)['error'])->toBe(ErrorCode::IdempotencyUnavailable->value);
});

it('renders malformed JSON through ErrorRenderer as validation_failed', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $response = $middleware->handle(idempotencyRequest('{not-json'), static fn () => response('x'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toBe('validation_failed')
        ->and($response->getData(true)['message'])->toBe('The given data was invalid.');
});

/**
 * Error renderer that keeps every rejection so tests can inspect its exact headers.
 */
function capturingErrorRenderer(): ErrorRenderer
{
    return new class implements ErrorRenderer
    {
        /** @var list<IdempotencyRejection> */
        public array $rejections = [];

        public function render(IdempotencyRejection $rejection, Request $request): JsonResponse
        {
            $this->rejections[] = $rejection;

            return (new FleetErrorRenderer)->render($rejection, $request);
        }
    };
}

it('hands the renderer Retry-After values as strings', function (): void {
    $renderer = capturingErrorRenderer();
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'), errorRenderer: $renderer);
    $unbucketed = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver(null), errorRenderer: $renderer, retryAfterSeconds: 7);

    $this->store->claim(
        new IdempotencyScope('tenant:1', 'POST', '/api/widgets'),
        '550e8400-e29b-41d4-a716-446655440010',
        'held',
        new DateTimeImmutable,
    );
    $middleware->handle(idempotencyRequest(), static fn () => response('x'));
    $unbucketed->handle(idempotencyRequest(), static fn () => response('x'));

    expect($renderer->rejections[0]->getHeaders())->toBe(['Retry-After' => '60'])
        ->and($renderer->rejections[1]->getHeaders())->toBe(['Retry-After' => '7']);
});

it('runs the handler under a max_handler_seconds override and floors it at one second', function (string $option): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    $first = $middleware->handle(idempotencyRequest(), static fn () => response('ok', 201), $option);
    $retry = $middleware->handle(idempotencyRequest(), static fn () => response('again', 201), $option);

    expect($first->getStatusCode())->toBe(201)
        ->and($retry->getContent())->toBe('ok')
        ->and($retry->headers->get('Idempotent-Replayed'))->toBe('true');
})->with([
    'within the lock TTL' => ['max_handler_seconds=2'],
    'zero floors to one' => ['max_handler_seconds=0'],
]);

it('rejects a request without Content-Type when JSON is required', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = idempotencyRequest();
    $request->headers->remove('Content-Type');
    $runs = 0;

    $response = $middleware->handle($request, static function () use (&$runs) {
        $runs++;

        return response('x');
    });

    expect($response->getStatusCode())->toBe(415)->and($runs)->toBe(0);
});

it('accepts a JSON media type with whitespace before its parameters', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = idempotencyRequest();
    $request->headers->set('Content-Type', 'application/json ; charset=utf-8');

    expect($middleware->handle($request, static fn () => response('ok', 201))->getStatusCode())->toBe(201);
});

it('treats an empty credential bucket as no bucket', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver(''));
    $runs = 0;

    $response = $middleware->handle(idempotencyRequest(), static function () use (&$runs) {
        $runs++;

        return response('x');
    });

    expect($response->getStatusCode())->toBe(503)->and($runs)->toBe(0);
});

it('folds the served locale into machine-door fingerprints and nothing into web ones', function (
    string $path,
    ?string $acceptLanguage,
    ?LocaleResolution $resolvedLocale,
    string $expectedSuffix,
): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = Request::create($path, 'POST', [], [], [], [
        'HTTP_Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440094',
        'CONTENT_TYPE' => 'application/json',
    ], '{"name":"a"}');

    if ($acceptLanguage !== null) {
        $request->headers->set('Accept-Language', $acceptLanguage);
    }

    if ($resolvedLocale !== null) {
        $request->attributes->set(LocaleNegotiator::ATTRIBUTE, $resolvedLocale);
    }

    $middleware->handle($request, static fn () => response('ok', 201));

    $expectedFingerprint = (new Fingerprinter)->fingerprint($request, 1048576, $expectedSuffix, 'tenant:1');
    $retry = $this->store->claim(
        new IdempotencyScope('tenant:1', 'POST', $path),
        '550e8400-e29b-41d4-a716-446655440094',
        $expectedFingerprint,
        new DateTimeImmutable,
    );

    expect($retry->outcome)->toBe(ClaimOutcome::Replay);
})->with([
    'web route ignores even a header-sourced locale' => ['/widgets', 'it', new LocaleResolution('it', LocaleSource::Header, true), ''],
    'machine door, unsupported header falls back' => ['/api/widgets', 'fr', null, 'en'],
    'machine door, negotiated region tag' => ['/api/widgets', 'it-CH,it;q=0.9', null, 'it'],
]);

it('replays a response whose size equals the response cap', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'), maxResponseBytes: 2);

    $middleware->handle(idempotencyRequest(), static fn () => response('ok', 201));
    $retry = $middleware->handle(idempotencyRequest(), static fn () => response('no', 201));

    expect($retry->getContent())->toBe('ok')
        ->and($retry->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('omits the replay header when its name is null or empty', function (?string $replayHeaderName): void {
    $middleware = makeIdempotencyMiddleware(
        $this->store,
        new FakeCredentialBucketResolver('tenant:1'),
        replayHeaderName: $replayHeaderName,
    );

    $middleware->handle(idempotencyRequest(), static fn () => response('ok', 201));
    $retry = $middleware->handle(idempotencyRequest(), static fn () => response('no', 201));

    expect($retry->getContent())->toBe('ok')
        ->and(array_keys($retry->headers->all()))->not->toContain('')
        ->and($retry->headers->has('Idempotent-Replayed'))->toBeFalse();
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('abandons the claim when the handler itself throws a store exception', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    // The handler's own failure propagates: it is not the middleware's store failing.
    expect(fn () => $middleware->handle(idempotencyRequest(), static function (): never {
        throw new IdempotencyStoreException('nested store failure');
    }))->toThrow(IdempotencyStoreException::class, 'nested store failure');

    $retry = $middleware->handle(idempotencyRequest(), static fn () => response('ran', 201));

    expect($retry->getStatusCode())->toBe(201)->and($retry->getContent())->toBe('ran');
});

it('replays a retry whose Accept-Language negotiates to the same served locale', function (?string $firstHeader): void {
    // fr is unsupported and a missing header falls back: both serve the default en.
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $first = idempotencyRequest();
    $first->headers->remove('Accept-Language');

    if ($firstHeader !== null) {
        $first->headers->set('Accept-Language', $firstHeader);
    }

    $retry = idempotencyRequest();
    $retry->headers->set('Accept-Language', 'en');

    $middleware->handle($first, static fn () => response('first', 201));
    $response = $middleware->handle($retry, static fn () => response('second', 201));

    expect($response->getContent())->toBe('first')
        ->and($response->headers->get('Idempotent-Replayed'))->toBe('true');
})->with(['unsupported language' => ['fr'], 'no header' => [null]]);

it('measures the handler budget in exact nanoseconds', function (int $elapsedNanoseconds, int $expectedStatus): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    FakeHighResolutionClock::queue(1_000, 1_000 + $elapsedNanoseconds);

    try {
        $response = $middleware->handle(idempotencyRequest(), static fn () => response('ok', 201));
    } finally {
        FakeHighResolutionClock::reset();
    }

    expect($response->getStatusCode())->toBe($expectedStatus);
})->with([
    'exactly the 55-second budget' => [55_000_000_000, 201],
    'one nanosecond over' => [55_000_000_001, 503],
]);

it('rethrows the handler failure even when abandoning the claim fails too', function (): void {
    $store = new class(new InMemoryIdempotencyStore) implements IdempotencyStore
    {
        public function __construct(private InMemoryIdempotencyStore $inner) {}

        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            return $this->inner->claim($scope, $key, $fingerprint, $now);
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void
        {
            $this->inner->complete($claim, $response, $now);
        }

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void
        {
            $this->inner->sealUnreplayable($claim, $now);
        }

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
        {
            throw new IdempotencyStoreException('cache down');
        }
    };
    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'));

    expect(fn () => $middleware->handle(idempotencyRequest(), static function (): never {
        throw new RuntimeException('handler failed');
    }))->toThrow(RuntimeException::class, 'handler failed');
});

it('rejects a replayable option that is not true or false instead of replaying secrets', function (string $option): void {
    // Red-team finding: replayable=0 used to mean "replayable" and replayed the first secret.
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $runs = 0;

    expect(fn () => $middleware->handle(idempotencyRequest(), static function () use (&$runs) {
        $runs++;

        return response('SECRET', 201);
    }, $option))->toThrow(InvalidArgumentException::class, 'replayable must be true or false');

    expect($runs)->toBe(0);
})->with(['replayable=0', 'replayable=no', 'replayable=off', 'replayable=', 'replayable=1']);

it('accepts replayable=true in any letter case', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    $middleware->handle(idempotencyRequest(), static fn () => response('first', 201), 'replayable=TRUE');
    $retry = $middleware->handle(idempotencyRequest(), static fn () => response('second', 201), 'replayable=True');

    expect($retry->getContent())->toBe('first');
});

it('answers a cache outage with 503 and Retry-After, without running the handler', function (): void {
    $repository = new FaultyCacheRepository(new ArrayStore);
    $repository->isGetThrowing = true;
    $store = new CacheIdempotencyStore($repository, 3600, 60, 5);
    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'), retryAfterSeconds: 7);
    $runs = 0;

    $response = $middleware->handle(idempotencyRequest(), static function () use (&$runs) {
        $runs++;

        return response('x', 201);
    });

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('7')
        ->and($runs)->toBe(0);
});

it('refuses unknown, misspelled or duplicated options instead of replaying secrets', function (array $options, string $message): void {
    // Review of #21: an unrecognised option was ignored, so a typo left the route replayable.
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $runs = 0;

    expect(fn () => $middleware->handle(idempotencyRequest(), static function () use (&$runs) {
        $runs++;

        return response('SECRET', 201);
    }, ...$options))->toThrow(InvalidArgumentException::class, $message);

    expect($runs)->toBe(0);
})->with([
    'capitalised key' => [['Replayable=false'], 'Unknown idempotency middleware option'],
    'space before =' => [['replayable =false'], 'Unknown idempotency middleware option'],
    'misspelled key' => [['replayble=false'], 'Unknown idempotency middleware option'],
    'empty option' => [['required', ''], 'Unknown idempotency middleware option'],
    'value on a flag' => [['required=true'], 'Unknown idempotency middleware option'],
    'flag without value' => [['replayable'], 'Unknown idempotency middleware option'],
    'replayable twice' => [['replayable=false', 'replayable=true'], 'given twice'],
    'required and optional' => [['required', 'optional'], 'given twice'],
    'max_handler_seconds twice' => [['max_handler_seconds=5', 'max_handler_seconds=6'], 'given twice'],
    'max_handler_seconds not a number' => [['max_handler_seconds=abc'], 'max_handler_seconds must be an integer'],
    'a second = in the value' => [['replayable=false=true'], 'replayable must be true or false'],
]);

it('trims each option, as Laravel keeps the space after a comma', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));

    $middleware->handle(idempotencyRequest(), static fn () => response('SECRET-FIRST', 201), 'required', ' replayable=false');
    $second = $middleware->handle(idempotencyRequest(), static fn () => response('SECRET-SECOND', 201), 'required', ' replayable=false');

    expect($second->getContent())->toBe('SECRET-SECOND')
        ->and($second->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('keeps the handler response when abandoning the claim fails', function (): void {
    $store = new class(new InMemoryIdempotencyStore) implements IdempotencyStore
    {
        public function __construct(private InMemoryIdempotencyStore $inner) {}

        public function claim(IdempotencyScope $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyClaim
        {
            return $this->inner->claim($scope, $key, $fingerprint, $now);
        }

        public function complete(IdempotencyClaim $claim, ReplayableResponse $response, DateTimeImmutable $now): void
        {
            $this->inner->complete($claim, $response, $now);
        }

        public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void
        {
            $this->inner->sealUnreplayable($claim, $now);
        }

        public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
        {
            throw new IdempotencyStoreException('lock release failed');
        }
    };
    $middleware = makeIdempotencyMiddleware($store, new FakeCredentialBucketResolver('tenant:1'));

    // A 500 from the handler is not stored: the claim is abandoned, and that fails too.
    $response = $middleware->handle(idempotencyRequest(), static fn () => response('handler broke', 500));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->toBe('handler broke');
});

it('refuses a structured-syntax suffix with no subtype before it', function (string $contentType): void {
    // RFC 6839 §3.1 needs a non-empty subtype name before "+json".
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = idempotencyRequest();
    $request->headers->set('Content-Type', $contentType);

    expect($middleware->handle($request, static fn () => response('x', 201))->getStatusCode())->toBe(415);
})->with(['application/+json', 'application/ +json', 'application/a b+json']);

it('names both accepted JSON media types in the 415 message', function (string $locale, string $expected): void {
    $middleware = makeIdempotencyMiddleware(
        $this->store,
        new FakeCredentialBucketResolver('tenant:1'),
        errorRenderer: new FleetErrorRenderer(null, app('translator')),
    );
    $request = idempotencyRequest();
    $request->headers->set('Content-Type', 'text/plain');
    $request->setLocale($locale);

    expect($middleware->handle($request, static fn () => response('x', 201))->getData(true)['message'])->toBe($expected);
})->with([
    'en' => ['en', 'Idempotent routes require Content-Type: application/json or application/*+json.'],
    'it' => ['it', 'Le route idempotenti richiedono Content-Type: application/json oppure application/*+json.'],
]);
