<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdPropagator;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\AlwaysTrustedProxyDetector;
use Qiick\HttpBasics\Testing\FixedIdGenerator;

/**
 * Real HTTP against php -S (fleet rule). Group http-server is required by CI.
 */
$httpState = new class
{
    public int $primaryPort = 0;

    public int $redirectPort = 0;

    /** @var resource|null */
    public $primaryProc = null;

    /** @var resource|null */
    public $redirectProc = null;
};

/**
 * Start php -S so the tracked PID is the server itself (not an `sh -c` parent).
 * Prefers `setsid` + array form (process group kill); falls back to `exec php -S`.
 *
 * @return resource
 */
function startPhpBuiltInServer(int $port, string $server, string $root, string $trustProxies)
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', sys_get_temp_dir()."/http-basics-{$port}.log", 'w'],
        2 => ['file', sys_get_temp_dir()."/http-basics-{$port}.err", 'w'],
    ];

    $setsid = null;
    foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
        if (is_executable($candidate)) {
            $setsid = $candidate;
            break;
        }
    }

    if ($setsid !== null) {
        $environment = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }
        $environment['HTTP_BASICS_TRUST_PROXIES'] = $trustProxies;
        $command = [$setsid, PHP_BINARY, '-S', "127.0.0.1:{$port}", $server];
        $proc = proc_open($command, $descriptors, $pipes, $root, $environment);
    } else {
        // exec replaces the shell so proc_terminate hits php -S, not sh.
        $command = sprintf(
            'exec env HTTP_BASICS_TRUST_PROXIES=%s %s -S 127.0.0.1:%d %s',
            escapeshellarg($trustProxies),
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($server),
        );
        $proc = proc_open($command, $descriptors, $pipes, $root);
    }

    if (! is_resource($proc)) {
        throw new RuntimeException("Failed to start php -S on {$port}");
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);

            return $proc;
        }
        usleep(100_000);
    }

    stopPhpBuiltInServer($proc);

    throw new RuntimeException("php -S on {$port} did not become ready");
}

/**
 * @param  resource  $proc
 */
function stopPhpBuiltInServer($proc): void
{
    if (! is_resource($proc)) {
        return;
    }

    $status = proc_get_status($proc);
    $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;

    if ($pid > 0 && function_exists('posix_kill')) {
        // Negative PID = process group when started under setsid; also kill the PID.
        @posix_kill(-$pid, SIGTERM);
        @posix_kill($pid, SIGTERM);
        usleep(50_000);
        @posix_kill(-$pid, SIGKILL);
        @posix_kill($pid, SIGKILL);
    }

    proc_terminate($proc);
    proc_close($proc);
}

beforeAll(function () use ($httpState): void {
    $server = __DIR__.'/fixtures/server.php';
    $root = dirname(__DIR__, 1);
    $httpState->primaryPort = 8765 + random_int(0, 200);
    $httpState->redirectPort = $httpState->primaryPort + 1;

    $httpState->primaryProc = startPhpBuiltInServer(
        $httpState->primaryPort,
        $server,
        $root,
        '127.0.0.1',
    );
    $httpState->redirectProc = startPhpBuiltInServer(
        $httpState->redirectPort,
        $server,
        $root,
        '127.0.0.1',
    );
});

afterAll(function () use ($httpState): void {
    foreach ([$httpState->primaryProc, $httpState->redirectProc] as $proc) {
        if (is_resource($proc)) {
            stopPhpBuiltInServer($proc);
        }
    }
});

/**
 * @param  array<string, string>  $headers
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function realHttpRequest(int $port, string $path, string $method = 'GET', array $headers = []): array
{
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('curl failed: '.curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $parts = explode("\r\n\r\n", $raw, 2);
    $headerBlob = $parts[0];
    $body = $parts[1] ?? '';
    $parsed = [];
    foreach (explode("\r\n", $headerBlob) as $line) {
        if (str_contains($line, ':')) {
            [$n, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($n))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $parsed, 'body' => $body];
}

it('echoes a generated X-Request-Id over real HTTP', function () use ($httpState): void {
    $result = realHttpRequest($httpState->primaryPort, '/ping');

    expect($result['status'])->toBe(200)
        ->and($result['headers'])->toHaveKey(strtolower(RequestId::HEADER))
        ->and($result['headers'][strtolower(RequestId::HEADER)])->toBe('http-server-generated');
})->group('http-server');

it('rejects log-injection inbound ids over real HTTP', function () use ($httpState): void {
    $result = realHttpRequest($httpState->primaryPort, '/ping', headers: [
        RequestId::HEADER => 'bad id value',
    ]);

    expect($result['headers'][strtolower(RequestId::HEADER)])->toBe('http-server-generated');
})->group('http-server');

it('keeps a trusted proxy id over real HTTP', function () use ($httpState): void {
    $result = realHttpRequest($httpState->primaryPort, '/ping', headers: [
        RequestId::HEADER => 'trusted-real-01',
    ]);

    expect($result['headers'][strtolower(RequestId::HEADER)])->toBe('trusted-real-01');
})->group('http-server');

it('carries X-Request-Id on 404 and CORS preflight over real HTTP', function () use ($httpState): void {
    $missing = realHttpRequest($httpState->primaryPort, '/missing-route');
    expect($missing['status'])->toBe(404)
        ->and($missing['headers'])->toHaveKey(strtolower(RequestId::HEADER));

    $cors = realHttpRequest($httpState->primaryPort, '/cors', 'OPTIONS');
    expect($cors['status'])->toBe(204)
        ->and($cors['headers'])->toHaveKey(strtolower(RequestId::HEADER));
})->group('http-server');

it('strips X-Request-Id on a cross-port redirect followed by the propagator', function () use ($httpState): void {
    Context::flush();
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('mesh-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    $resolver->bind(Request::create('/', 'GET'));

    $primary = "http://127.0.0.1:{$httpState->primaryPort}";
    $redirect = "http://127.0.0.1:{$httpState->redirectPort}/ping?dump_headers=1";

    // Allow first hop only — second port must not receive the header.
    $propagator = new RequestIdPropagator(
        ["127.0.0.1:{$httpState->primaryPort}"],
        $resolver,
    );

    $pending = $propagator->applyTo(Http::withOptions(['allow_redirects' => true]));
    $response = $pending->get($primary.'/ping?redirect_to='.urlencode($redirect));

    expect($response->status())->toBe(200);
    $payload = $response->json();
    expect($response->body())->toContain('raw_inbound_x_request_id')
        ->and($payload)->toBeArray()
        ->and(array_key_exists('raw_inbound_x_request_id', $payload))->toBeTrue()
        ->and($payload['raw_inbound_x_request_id'])->toBeNull();
})->group('http-server');

it('does not forward Authorization or Cookie on a cross-host redirect hop', function () use ($httpState): void {
    Context::flush();
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('mesh-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    $resolver->bind(Request::create('/', 'GET'));

    $primary = "http://127.0.0.1:{$httpState->primaryPort}";
    $redirect = "http://127.0.0.1:{$httpState->redirectPort}/ping?dump_headers=1";

    $propagator = new RequestIdPropagator(['*'], $resolver);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => true])
        ->withHeaders([
            'Authorization' => 'Bearer should-not-leak',
            'Cookie' => 'session=should-not-leak',
        ]))
        ->get($primary.'/ping?redirect_to='.urlencode($redirect));

    expect($response->status())->toBe(200);
    $payload = $response->json();
    expect($payload)->toBeArray()
        ->and(array_key_exists('raw_authorization', $payload))->toBeTrue()
        ->and($payload['raw_authorization'])->toBeNull()
        ->and(array_key_exists('raw_cookie', $payload))->toBeTrue()
        ->and($payload['raw_cookie'])->toBeNull();
})->group('http-server');

it('follows a 303 like Guzzle: POST becomes GET without a body', function () use ($httpState): void {
    Context::flush();
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('mesh-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    $resolver->bind(Request::create('/', 'GET'));

    $primary = "http://127.0.0.1:{$httpState->primaryPort}";
    $redirect = "http://127.0.0.1:{$httpState->redirectPort}/ping?dump_headers=1";

    $propagator = new RequestIdPropagator(['*'], $resolver);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => true]))
        ->withBody('posted-body', 'text/plain')
        ->post($primary.'/ping?redirect_to='.urlencode($redirect).'&redirect_status=303');

    expect($response->status())->toBe(200);
    $payload = $response->json();
    expect($payload)->toBeArray()
        ->and($payload['raw_method'] ?? null)->toBe('GET')
        ->and(in_array($payload['raw_content_length'] ?? null, [null, '', '0'], true))->toBeTrue();
})->group('http-server');
