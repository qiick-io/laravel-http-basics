<?php

declare(strict_types=1);

/**
 * Front controller for real-HTTP idempotency Pest tests (php -S).
 *
 * Env:
 * - HTTP_BASICS_CACHE_PATH          shared file-cache directory (required for dual-server concurrency)
 * - HTTP_BASICS_IDEMPOTENCY_LOCK_TTL / STALE_AFTER / MAX_HANDLER
 * - HTTP_BASICS_TRUST_PROXIES
 *
 * Routes:
 * - GET  /ping
 * - POST /api/items?sleep_ms=N  → JSON create behind HandleIdempotencyMiddleware
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Foundation\Application;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;
use Qiick\HttpBasics\RequestId\AssignRequestIdMiddleware;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\FakeCredentialBucketResolver;
use Qiick\HttpBasics\Testing\FixedIdGenerator;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$cachePath = getenv('HTTP_BASICS_CACHE_PATH');
if (! is_string($cachePath) || $cachePath === '') {
    fwrite(STDERR, "HTTP_BASICS_CACHE_PATH is required\n");
    exit(1);
}

if (! is_dir($cachePath) && ! mkdir($cachePath, 0777, true) && ! is_dir($cachePath)) {
    fwrite(STDERR, "Failed to create cache path {$cachePath}\n");
    exit(1);
}

$lockTtl = (int) (getenv('HTTP_BASICS_IDEMPOTENCY_LOCK_TTL') ?: '2');
$staleAfter = (int) (getenv('HTTP_BASICS_IDEMPOTENCY_STALE_AFTER') ?: '1');
$maxHandler = (int) (getenv('HTTP_BASICS_IDEMPOTENCY_MAX_HANDLER') ?: '1');
$maxResponse = (int) (getenv('HTTP_BASICS_IDEMPOTENCY_MAX_RESPONSE') ?: '1048576');

$app = Application::create(
    basePath: null,
    resolvingCallback: null,
    options: [
        'extra' => [
            'providers' => [HttpBasicsServiceProvider::class],
        ],
    ],
);

$app->instance(IdGenerator::class, new FixedIdGenerator('http-server-generated'));
$app->forgetInstance(RequestIdResolver::class);
$app->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver('tenant:http-server'));

$config = $app['config'];
$config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
$config->set('cache.default', 'file');
$config->set('cache.stores.file', [
    'driver' => 'file',
    'path' => $cachePath,
    'lock_path' => $cachePath,
]);
$config->set('http-basics.request_id.accept_from', 'trusted_proxy');
$config->set('http-basics.idempotency.store', 'cache');
$config->set('http-basics.idempotency.cache_store', 'file');
$config->set('http-basics.idempotency.lock_ttl_seconds', max(1, $lockTtl));
$config->set('http-basics.idempotency.stale_after_seconds', max(1, $staleAfter));
$config->set('http-basics.idempotency.max_handler_seconds', max(1, min($maxHandler, $lockTtl)));
$config->set('http-basics.idempotency.ttl_seconds', 86400);
$config->set('http-basics.idempotency.max_response_bytes', max(1, $maxResponse));
$config->set('http-basics.idempotency.anonymous.enabled', false);

// Re-bind store/middleware after config overrides (provider registered with defaults).
$app->forgetInstance(IdempotencyStore::class);
$app->forgetInstance(HandleIdempotencyMiddleware::class);

$proxies = getenv('HTTP_BASICS_TRUST_PROXIES');
if (is_string($proxies) && $proxies !== '') {
    TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
}

$kernel = $app->make(Kernel::class);
$kernel->prependMiddleware(AssignRequestIdMiddleware::class);

Route::get('/ping', function () {
    return response('pong', 200);
});

$handlerCountFile = $cachePath.DIRECTORY_SEPARATOR.'handler_count';

Route::post('/api/items', function (Request $request) use ($handlerCountFile) {
    $sleepMs = (int) $request->query('sleep_ms', 0);
    if ($sleepMs <= 0) {
        $sleepMs = (int) ($request->headers->get('X-Sleep-Ms') ?? 0);
    }
    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }

    $count = is_file($handlerCountFile) ? (int) file_get_contents($handlerCountFile) : 0;
    file_put_contents($handlerCountFile, (string) ($count + 1), LOCK_EX);

    return response()->json(['created' => true, 'name' => $request->input('name')], 201);
})->middleware(HandleIdempotencyMiddleware::class);

Route::get('/api/handler-count', function () use ($handlerCountFile) {
    $count = is_file($handlerCountFile) ? (int) file_get_contents($handlerCountFile) : 0;

    return response()->json(['count' => $count]);
});

Route::post('/api/large', function () use ($handlerCountFile) {
    $count = is_file($handlerCountFile) ? (int) file_get_contents($handlerCountFile) : 0;
    file_put_contents($handlerCountFile, (string) ($count + 1), LOCK_EX);

    return response(str_repeat('Z', 80_000), 201, ['Content-Type' => 'application/json']);
})->middleware(HandleIdempotencyMiddleware::class);

$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
