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

beforeAll(function () use ($httpState): void {
    $server = __DIR__.'/fixtures/server.php';
    $root = dirname(__DIR__, 1);
    $httpState->primaryPort = 8765 + random_int(0, 200);
    $httpState->redirectPort = $httpState->primaryPort + 1;

    $start = function (int $port) use ($server, $root) {
        $cmd = sprintf(
            'HTTP_BASICS_TRUST_PROXIES=%s php -S 127.0.0.1:%d %s',
            escapeshellarg('127.0.0.1'),
            $port,
            escapeshellarg($server),
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir()."/http-basics-{$port}.log", 'w'],
            2 => ['file', sys_get_temp_dir()."/http-basics-{$port}.err", 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $root);
        if (! is_resource($proc)) {
            throw new RuntimeException("Failed to start php -S on {$port}");
        }

        for ($i = 0; $i < 50; $i++) {
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($fp)) {
                fclose($fp);

                return $proc;
            }
            usleep(100_000);
        }

        throw new RuntimeException("php -S on {$port} did not become ready");
    };

    $httpState->primaryProc = $start($httpState->primaryPort);
    $httpState->redirectProc = $start($httpState->redirectPort);
});

afterAll(function () use ($httpState): void {
    foreach ([$httpState->primaryProc, $httpState->redirectProc] as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
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
