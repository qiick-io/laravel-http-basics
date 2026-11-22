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
 * @param  list<string|null>  $varyLines
 */
function corsMiddlewareResponse(ConfigureCorsMiddleware $middleware, array $varyLines = []): Response
{
    return $middleware->handle(
        Request::create('http://localhost/api/items'),
        static function () use ($varyLines): Response {
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

    expect(config('cors.allowed_origins'))->toBe([
        'https://app.example.com',
        'https://admin.example.com',
        'https://*.example.com',
    ]);
});

it('treats a truthy non-boolean supports_credentials as credentialed', function (): void {
    config()->set('cors.supports_credentials', 1);

    corsMiddlewareResponse(corsMiddlewareReturning(['https://*', 'https://app.example.com']));

    expect(config('cors.allowed_origins'))->toBe(['https://app.example.com']);
});

it('re-normalises configured origins on credentialed requests without a resolver override', function (): void {
    config()->set('cors.supports_credentials', true);
    config()->set('cors.allowed_origins', ['*', 'https://APP.example.com', 'javascript:alert(1)']);
    config()->set('cors.allowed_origins_patterns', ['#.*#']);

    $response = corsMiddlewareResponse(corsMiddlewareReturning(null), ['Accept']);

    expect(config('cors.allowed_origins'))->toBe(['https://app.example.com'])
        ->and(config('cors.allowed_origins_patterns'))->toBe([])
        ->and($response->headers->all('vary'))->toBe(['Accept']);
});

it('leaves non-credentialed config alone when the resolver does not override', function (): void {
    config()->set('cors.supports_credentials', false);
    config()->set('cors.allowed_origins', ['*']);
    config()->set('cors.allowed_origins_patterns', ['#^https://app\.example\.com$#']);

    corsMiddlewareResponse(corsMiddlewareReturning(null));

    expect(config('cors.allowed_origins'))->toBe(['*'])
        ->and(config('cors.allowed_origins_patterns'))->toBe(['#^https://app\.example\.com$#']);
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
