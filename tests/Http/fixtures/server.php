<?php

declare(strict_types=1);

/**
 * Front controller for real-HTTP Pest tests (php -S).
 *
 * Query flags:
 * - redirect_to=<url>       → redirect with Location (status via redirect_status, default 302)
 * - redirect_status=<int>   → 301/302/303/307/308
 * - status=<int>            → arbitrary status (default 200)
 * - dump_headers=1          → body is JSON including raw inbound headers of interest
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Foundation\Application;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;
use Qiick\HttpBasics\RequestId\AssignRequestIdMiddleware;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\FixedIdGenerator;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Capture wire headers before Laravel normalizes / middleware rewrites them.
$GLOBALS['http_basics_raw_inbound_id'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
$GLOBALS['http_basics_raw_authorization'] = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$GLOBALS['http_basics_raw_cookie'] = $_SERVER['HTTP_COOKIE'] ?? null;
$GLOBALS['http_basics_raw_method'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$GLOBALS['http_basics_raw_content_length'] = $_SERVER['CONTENT_LENGTH'] ?? null;

$app = Application::create(
    basePath: null,
    resolvingCallback: null,
    options: [
        'extra' => [
            'providers' => [HttpBasicsServiceProvider::class],
        ],
    ],
);

// After providers register — override the generator used by RequestIdResolver.
$app->instance(IdGenerator::class, new FixedIdGenerator('http-server-generated'));
$app->forgetInstance(RequestIdResolver::class);

$config = $app['config'];
$config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
$config->set('http-basics.request_id.accept_from', getenv('HTTP_BASICS_REQUEST_ID_ACCEPT_FROM') ?: 'trusted_proxy');
$config->set(
    'http-basics.request_id.trust_wildcard_proxies',
    filter_var(getenv('HTTP_BASICS_TRUST_WILDCARD_PROXIES') ?: 'false', FILTER_VALIDATE_BOOLEAN),
);

$proxies = getenv('HTTP_BASICS_TRUST_PROXIES');
if (is_string($proxies) && $proxies !== '') {
    TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
}

$kernel = $app->make(Kernel::class);
$kernel->prependMiddleware(AssignRequestIdMiddleware::class);

Route::any('/ping', function (Request $request) {
    if ($request->query->has('redirect_to')) {
        $status = (int) $request->query('redirect_status', 302);

        return redirect()->away((string) $request->query('redirect_to'), $status);
    }

    if ($request->boolean('dump_headers')) {
        return response()->json([
            'raw_inbound_x_request_id' => $GLOBALS['http_basics_raw_inbound_id'],
            'raw_authorization' => $GLOBALS['http_basics_raw_authorization'],
            'raw_cookie' => $GLOBALS['http_basics_raw_cookie'],
            'raw_method' => $GLOBALS['http_basics_raw_method'],
            'raw_content_length' => $GLOBALS['http_basics_raw_content_length'],
        ]);
    }

    $status = (int) $request->query('status', 200);

    return response('pong', $status);
});

Route::options('/cors', function () {
    return response('', 204)->header('Access-Control-Allow-Origin', '*');
});

$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
