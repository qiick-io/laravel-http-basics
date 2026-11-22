<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Idempotency\AnonymousBucketResolver;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\Fingerprinter;
use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyErrorCode;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyValidator;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\NullCredentialBucketResolver;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Idempotency\ReplayHeaderSanitizer;
use Qiick\HttpBasics\Locale\ConfigDefaultLocaleResolver;
use Qiick\HttpBasics\Locale\ConfigSupportedLocales;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\RequestId\RequestIdSource;
use Qiick\HttpBasics\Testing\FakeCredentialBucketResolver;
use Qiick\HttpBasics\Testing\InMemoryIdempotencyStore;
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
): HandleIdempotencyMiddleware {
    return new HandleIdempotencyMiddleware(
        $store,
        new Fingerprinter,
        new IdempotencyKeyValidator(IdempotencyKeyFormat::Uuid),
        $credentials,
        new AnonymousBucketResolver(str_repeat('s', 32), ['ip', 'route']),
        $anonymousEnabled,
        new FleetErrorRenderer,
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
        1048576,
        60,
        55,
        $retryAfterSeconds,
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

it('lets required override optional in middleware options', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $request = Request::create('/api/widgets', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

    $response = $middleware->handle($request, static fn () => response('x'), 'optional', 'required');

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

it('clamps lock ttl and max handler seconds to at least one', function (): void {
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
        'lock_ttl_seconds=0',
        'max_handler_seconds=-5',
    );

    expect($response->headers->get('Retry-After'))->toBe('1');
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

it('parses lock ttl and max handler options for conflict retry-after', function (): void {
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
        'lock_ttl_seconds=9',
        'max_handler_seconds=3',
        'required',
    );

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Retry-After'))->toBe('9');
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
        ->and($response->getData(true)['error'])->toBe(IdempotencyErrorCode::NotReplayable->value);
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
        ->and($second->getData(true)['error'])->toBe(IdempotencyErrorCode::NotReplayable->value)
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

    expect($second->getStatusCode())->toBe(409)->and($runs)->toBe(1);
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
        ->and($response->getData(true)['error'])->toBe(IdempotencyErrorCode::Unavailable->value);
});

it('renders malformed JSON through ErrorRenderer as validation_failed', function (): void {
    $middleware = makeIdempotencyMiddleware($this->store, new FakeCredentialBucketResolver('tenant:1'));
    $response = $middleware->handle(idempotencyRequest('{not-json'), static fn () => response('x'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toBe('validation_failed')
        ->and($response->getData(true)['message'])->toBe('The given data was invalid.');
});
