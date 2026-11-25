<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Cors\AllowedOriginsResolver;
use Qiick\HttpBasics\Cors\ConfigureCorsMiddleware;
use Qiick\HttpBasics\Cors\CorsConfigValidator;
use Qiick\HttpBasics\Cors\CorsHeaderCatalog;
use Qiick\HttpBasics\Cors\OriginNormalizer;
use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;

it('exposes the fleet CORS header catalogues without wildcards', function (): void {
    expect(CorsHeaderCatalog::exposedHeaders())->toContain('X-Request-Id', 'Idempotent-Replayed', 'Vary')
        ->and(CorsHeaderCatalog::allowedRequestHeaders())->toContain('Idempotency-Key', 'Authorization')
        ->and(CorsHeaderCatalog::exposedHeaders())->not->toContain('*');
});

it('normalises origins and rejects unsafe forms', function (): void {
    $normalizer = new OriginNormalizer;

    expect($normalizer->normalize('https://API.Example.com:443/path'))->toBeNull()
        ->and($normalizer->normalize('https://API.Example.com:443'))->toBe('https://api.example.com')
        ->and($normalizer->normalize('http://localhost:3000'))->toBe('http://localhost:3000')
        ->and($normalizer->normalize('null'))->toBeNull()
        ->and($normalizer->normalize('https://user:pass@evil.test'))->toBeNull();
});

it('rejects credentialed wildcard origins and exposed headers at boot', function (): void {
    $validator = new CorsConfigValidator;

    expect(fn () => $validator->validate([
        'supports_credentials' => true,
        'allowed_origins' => ['*'],
        'exposed_headers' => ['X-Request-Id'],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $validator->validate([
        'supports_credentials' => true,
        'allowed_origins' => ['https://app.example.com'],
        'exposed_headers' => ['*'],
    ]))->toThrow(InvalidArgumentException::class);

    $validator->validate([
        'supports_credentials' => false,
        'allowed_origins' => ['*'],
        'exposed_headers' => ['*'],
    ]);
});

it('fails provider boot when cors credentials and star collide, with no switch to skip it', function (): void {
    // The removed `validate_on_boot` switch must not come back as a way around the guard.
    config()->set('http-basics.cors.validate_on_boot', false);
    config()->set('cors.supports_credentials', true);
    config()->set('cors.allowed_origins', ['*']);

    /** @var HttpBasicsServiceProvider $provider */
    $provider = $this->app->getProvider(HttpBasicsServiceProvider::class);

    expect(fn () => $provider->boot())->toThrow(InvalidArgumentException::class);
});

it('writes allowed origins before HandleCors so Fruitcake reflects them', function (): void {
    config()->set('cors', [
        'paths' => ['api/*'],
        'allowed_methods' => ['*'],
        'allowed_origins' => ['*'],
        'allowed_origins_patterns' => [],
        'allowed_headers' => ['*'],
        'exposed_headers' => CorsHeaderCatalog::exposedHeaders(),
        'max_age' => 0,
        'supports_credentials' => false,
    ]);

    $resolver = new class implements AllowedOriginsResolver
    {
        public function origins(Request $request): ?array
        {
            return ['https://app.example.com'];
        }
    };

    $configure = new ConfigureCorsMiddleware($resolver);
    $handleCors = $this->app->make(HandleCors::class);

    $request = Request::create('http://localhost/api/items', 'OPTIONS', server: [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $originsSeenByHandleCors = null;
    $response = $configure->handle($request, static function (Request $inner) use ($handleCors, &$originsSeenByHandleCors) {
        $originsSeenByHandleCors = config('cors.allowed_origins');

        return $handleCors->handle($inner, static fn () => response('ok', 200));
    });

    expect($originsSeenByHandleCors)->toBe(['https://app.example.com'])
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com')
        ->and(config('cors.allowed_origins'))->toBe(['*']);
});
