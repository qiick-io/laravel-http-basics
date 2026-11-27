<?php

declare(strict_types=1);

/**
 * Security repros from the adversarial review of #12 (must stay red until the fix).
 */

use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Cors\AllowedOriginsResolver;
use Qiick\HttpBasics\Cors\ConfigureCorsMiddleware;
use Qiick\HttpBasics\Cors\CorsConfigValidator;
use Qiick\HttpBasics\Cors\CorsHeaderCatalog;

/**
 * @return array{status: int, headers: array<string, string>, body: string, corsConfigDuringRequest: array<string, mixed>}
 */
function corsPreflight(array $corsConfig, string $origin, ?AllowedOriginsResolver $resolver = null): array
{
    config()->set('cors', array_merge([
        'paths' => ['api/*'],
        'allowed_methods' => ['GET', 'POST'],
        'allowed_origins' => [],
        'allowed_origins_patterns' => [],
        'allowed_headers' => ['Content-Type', 'Authorization'],
        'exposed_headers' => CorsHeaderCatalog::exposedHeaders(),
        'max_age' => 0,
        'supports_credentials' => true,
    ], $corsConfig));

    $configure = new ConfigureCorsMiddleware(
        $resolver ?? new class implements AllowedOriginsResolver
        {
            public function origins(Request $request): ?array
            {
                return null;
            }
        },
    );
    $handleCors = app(HandleCors::class);

    $request = Request::create('http://localhost/api/items', 'OPTIONS', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    // What HandleCors read: the middleware puts the configured lists back afterwards.
    $corsConfigDuringRequest = [];
    $response = $configure->handle($request, static function (Request $inner) use ($handleCors, &$corsConfigDuringRequest) {
        $corsConfigDuringRequest = [
            'allowed_origins' => config('cors.allowed_origins'),
            'allowed_origins_patterns' => config('cors.allowed_origins_patterns'),
        ];

        return $handleCors->handle($inner, static fn () => response('ok', 200));
    });

    /** @var array<string, string> $headers */
    $headers = [];
    foreach ($response->headers->all() as $name => $values) {
        $headers[strtolower((string) $name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
    }

    return [
        'status' => $response->getStatusCode(),
        'headers' => $headers,
        'body' => (string) $response->getContent(),
        'corsConfigDuringRequest' => $corsConfigDuringRequest,
    ];
}

it('rejects credentialed fruitcake wildcards at boot (review B1 table)', function (array $origins, array $patterns): void {
    $validator = new CorsConfigValidator;

    expect(fn () => $validator->validate([
        'supports_credentials' => true,
        'allowed_origins' => $origins,
        'allowed_origins_patterns' => $patterns,
        'exposed_headers' => ['X-Request-Id'],
    ]))->toThrow(InvalidArgumentException::class);
})->with([
    'https://*' => [['https://*'], []],
    '*.*' => [['*.*'], []],
    'https://*example.com' => [['https://*example.com'], []],
    'https://*.example.com still a wildcard' => [['https://*.example.com'], []],
    'pattern #.*#' => [[], ['#.*#']],
    'pattern unanchored' => [[], ['#evil\\.com#']],
]);

it('does not reflect evil origins through credentialed fruitcake wildcards', function (array $cors, string $origin): void {
    // Until boot validation is fixed, this documents the live HandleCors bypass.
    $response = corsPreflight($cors, $origin);

    expect($response['headers']['access-control-allow-origin'] ?? null)->not->toBe($origin)
        ->and($response['headers']['access-control-allow-credentials'] ?? null)->not->toBe('true');
})->with([
    'https://*' => [['allowed_origins' => ['https://*']], 'https://evil.com'],
    '*.*' => [['allowed_origins' => ['*.*']], 'https://evil.com'],
    'pattern' => [['allowed_origins_patterns' => ['#.*#']], 'https://evil.com'],
    'suffix glue' => [['allowed_origins' => ['https://*example.com']], 'https://evilexample.com'],
]);

it('normalises and drops unsafe resolver origins before HandleCors (review B2)', function (): void {
    $resolver = new class implements AllowedOriginsResolver
    {
        public function origins(Request $request): ?array
        {
            return ['*', 'https://*', 'https://APP.Example.com:443', 'https://evil.com/path', 'null'];
        }
    };

    $response = corsPreflight([
        'allowed_origins' => ['https://benign.example.com'],
        'supports_credentials' => true,
    ], 'https://app.example.com', $resolver);

    expect($response['corsConfigDuringRequest']['allowed_origins'])->toBe(['https://app.example.com'])
        ->and($response['headers']['access-control-allow-origin'] ?? null)->toBe('https://app.example.com')
        ->and($response['headers']['vary'] ?? '')->toContain('Origin');

    $evil = corsPreflight([
        'allowed_origins' => ['https://benign.example.com'],
        'supports_credentials' => true,
    ], 'https://evil.com', $resolver);

    // Browsers only accept ACAO that exactly equals the request Origin.
    expect($evil['headers']['access-control-allow-origin'] ?? null)->not->toBe('https://evil.com');
});

it('purges credentialed patterns when the resolver overrides the origins (re-review R1)', function (): void {
    $resolver = new class implements AllowedOriginsResolver
    {
        public function origins(Request $request): ?array
        {
            return ['https://app.example.com'];
        }
    };

    $response = corsPreflight(['allowed_origins_patterns' => ['#.*#']], 'https://evil.com', $resolver);

    expect($response['corsConfigDuringRequest']['allowed_origins_patterns'])->toBe([])
        ->and($response['headers']['access-control-allow-origin'] ?? null)->toBe('https://app.example.com');
});
