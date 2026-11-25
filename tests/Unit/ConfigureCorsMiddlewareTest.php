<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Qiick\HttpBasics\Cors\AllowedOriginsResolver;
use Qiick\HttpBasics\Cors\ConfigureCorsMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param  list<mixed>|null  $resolvedOrigins
 */
function corsMiddlewareReturning(?array $resolvedOrigins): ConfigureCorsMiddleware
{
    return new ConfigureCorsMiddleware(new readonly class($resolvedOrigins) implements AllowedOriginsResolver
    {
        /**
         * @param  list<mixed>|null  $resolvedOrigins
         */
        public function __construct(private ?array $resolvedOrigins) {}

        public function origins(Request $request): ?array
        {
            return $this->resolvedOrigins;
        }
    });
}

/**
 * The CORS config HandleCors would read during the last request: the middleware puts
 * the configured lists back once the response is built.
 *
 * @param  array<string, mixed>|null  $seenConfig
 * @return array<string, mixed>
 */
function corsConfigDuringRequest(?array $seenConfig = null): array
{
    static $lastSeenConfig = [];

    if ($seenConfig !== null) {
        $lastSeenConfig = $seenConfig;
    }

    return $lastSeenConfig;
}

/**
 * @param  list<string|null>  $varyLines
 */
function corsMiddlewareResponse(ConfigureCorsMiddleware $middleware, array $varyLines = []): Response
{
    return $middleware->handle(
        Request::create('http://localhost/api/items'),
        static function () use ($varyLines): Response {
            corsConfigDuringRequest([
                'cors.allowed_origins' => config('cors.allowed_origins'),
                'cors.allowed_origins_patterns' => config('cors.allowed_origins_patterns'),
            ]);
            $response = response('ok');

            foreach ($varyLines as $varyLine) {
                $response->headers->set('Vary', $varyLine, false);
            }

            return $response;
        },
    );
}

it('drops non-string and duplicate resolver origins and keeps the list order', function (): void {
    config()->set('cors.supports_credentials', false);

    corsMiddlewareResponse(corsMiddlewareReturning([
        123,
        'https://app.example.com',
        'https://APP.example.com:443',
        'https://admin.example.com',
        'https://*.example.com',
    ]));

    expect(corsConfigDuringRequest()['cors.allowed_origins'])->toBe([
        'https://app.example.com',
        'https://admin.example.com',
        'https://*.example.com',
    ]);
});

it('treats a truthy non-boolean supports_credentials as credentialed', function (): void {
    config()->set('cors.supports_credentials', 1);

    corsMiddlewareResponse(corsMiddlewareReturning(['https://*', 'https://app.example.com']));

    expect(corsConfigDuringRequest()['cors.allowed_origins'])->toBe(['https://app.example.com']);
});

it('re-normalises configured origins on credentialed requests without a resolver override', function (): void {
    config()->set('cors.supports_credentials', true);
    config()->set('cors.allowed_origins', ['*', 'https://APP.example.com', 'javascript:alert(1)']);
    config()->set('cors.allowed_origins_patterns', ['#.*#']);

    $response = corsMiddlewareResponse(corsMiddlewareReturning(null), ['Accept']);

    expect(corsConfigDuringRequest()['cors.allowed_origins'])->toBe(['https://app.example.com'])
        ->and(corsConfigDuringRequest()['cors.allowed_origins_patterns'])->toBe([])
        ->and($response->headers->all('vary'))->toBe(['Accept']);
});

it('leaves non-credentialed config alone when the resolver does not override', function (): void {
    config()->set('cors.supports_credentials', false);
    config()->set('cors.allowed_origins', ['*']);
    config()->set('cors.allowed_origins_patterns', ['#^https://app\.example\.com$#']);

    corsMiddlewareResponse(corsMiddlewareReturning(null));

    expect(corsConfigDuringRequest()['cors.allowed_origins'])->toBe(['*'])
        ->and(corsConfigDuringRequest()['cors.allowed_origins_patterns'])->toBe(['#^https://app\.example\.com$#']);
});

it('merges Origin into every Vary line without losing or duplicating tokens', function (array $varyLines, string $expectedVary): void {
    config()->set('cors.supports_credentials', false);

    $response = corsMiddlewareResponse(corsMiddlewareReturning(['https://app.example.com']), $varyLines);

    expect($response->headers->all('vary'))->toBe([$expectedVary]);
})->with([
    'no Vary' => [[], 'Origin'],
    'null Vary' => [[null], 'Origin'],
    'two lines' => [['Accept', 'Cookie'], 'Accept, Cookie, Origin'],
    'spaces and empty tokens' => [[' Accept ,, Cookie '], 'Accept, Cookie, Origin'],
    'Origin already present' => [['Origin, Accept'], 'Origin, Accept'],
    'lower-case origin kept as sent' => [['accept-encoding, origin'], 'accept-encoding, origin'],
    'case-insensitive duplicate' => [['Accept', 'ACCEPT'], 'ACCEPT, Origin'],
]);

it('never carries one request\'s origins into the next request of the same process', function (): void {
    // A long-running worker (Octane, RoadRunner, FrankenPHP) keeps the config between requests.
    config([
        'cors.supports_credentials' => true,
        'cors.allowed_origins' => ['https://public.example'],
        'cors.allowed_origins_patterns' => ['#^https://.*\.public\.example$#'],
    ]);
    $originsSeenByHandleCors = [];
    $handler = static function () use (&$originsSeenByHandleCors): Response {
        $originsSeenByHandleCors[] = config('cors.allowed_origins');

        return response('ok');
    };

    corsMiddlewareReturning(['https://tenant.example'])->handle(Request::create('http://localhost/api/items'), $handler);
    corsMiddlewareReturning(null)->handle(Request::create('http://localhost/api/items'), $handler);

    expect($originsSeenByHandleCors)->toBe([['https://tenant.example'], ['https://public.example']])
        ->and(config('cors.allowed_origins'))->toBe(['https://public.example'])
        ->and(config('cors.allowed_origins_patterns'))->toBe(['#^https://.*\.public\.example$#']);
});

it('restores the origins even when the handler throws', function (): void {
    config(['cors.allowed_origins' => ['https://public.example'], 'cors.allowed_origins_patterns' => []]);

    expect(fn () => corsMiddlewareReturning(['https://tenant.example'])->handle(
        Request::create('http://localhost/api/items'),
        static fn (): Response => throw new RuntimeException('handler failed'),
    ))->toThrow(RuntimeException::class, 'handler failed');

    expect(config('cors.allowed_origins'))->toBe(['https://public.example']);
});
