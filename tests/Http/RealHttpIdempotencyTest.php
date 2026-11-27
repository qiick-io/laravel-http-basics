<?php

declare(strict_types=1);

use Qiick\HttpBasics\Errors\ErrorCode;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;

require_once __DIR__.'/Support/php-built-in-server.php';

/**
 * Real HTTP idempotency against dual php -S workers sharing a file cache.
 *
 * php -S is single-threaded, so concurrency needs two processes and a shared
 * LockProvider store (file cache) for overlapping Idempotency-Key claims.
 */
$idempotencyHttpState = new class
{
    public int $portA = 0;

    public int $portB = 0;

    public string $cachePath = '';

    /** @var resource|null */
    public $procA = null;

    /** @var resource|null */
    public $procB = null;
};

beforeAll(function () use ($idempotencyHttpState): void {
    $server = __DIR__.'/fixtures/idempotency-server.php';
    $root = dirname(__DIR__, 1);
    $idempotencyHttpState->cachePath = sys_get_temp_dir().'/http-basics-idempotency-'.getmypid().'-'.bin2hex(random_bytes(4));
    if (! mkdir($idempotencyHttpState->cachePath, 0777, true) && ! is_dir($idempotencyHttpState->cachePath)) {
        throw new RuntimeException('Failed to create shared cache path');
    }

    $idempotencyHttpState->portA = freeLocalPort();

    do {
        $idempotencyHttpState->portB = freeLocalPort();
    } while ($idempotencyHttpState->portB === $idempotencyHttpState->portA);

    $environment = [
        'HTTP_BASICS_CACHE_PATH' => $idempotencyHttpState->cachePath,
        'HTTP_BASICS_IDEMPOTENCY_LOCK_TTL' => '2',
        'HTTP_BASICS_IDEMPOTENCY_STALE_AFTER' => '1',
        // Must stay strictly under lock TTL (middleware refuses max_handler >= lock).
        'HTTP_BASICS_IDEMPOTENCY_MAX_HANDLER' => '1',
    ];

    $idempotencyHttpState->procA = startPhpBuiltInServer(
        $idempotencyHttpState->portA,
        $server,
        $root,
        '127.0.0.1',
        $environment,
    );
    $idempotencyHttpState->procB = startPhpBuiltInServer(
        $idempotencyHttpState->portB,
        $server,
        $root,
        '127.0.0.1',
        $environment,
    );
});

afterAll(function () use ($idempotencyHttpState): void {
    foreach ([$idempotencyHttpState->procA, $idempotencyHttpState->procB] as $proc) {
        if (is_resource($proc)) {
            stopPhpBuiltInServer($proc);
        }
    }

    if ($idempotencyHttpState->cachePath !== '' && is_dir($idempotencyHttpState->cachePath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($idempotencyHttpState->cachePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }
        @rmdir($idempotencyHttpState->cachePath);
    }
});

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function idempotencyJsonPost(int $port, string $key, array $payload, int $sleepMs = 0): array
{
    $path = '/api/items';
    if ($sleepMs > 0) {
        $path .= '?sleep_ms='.$sleepMs;
    }

    return realHttpRequest(
        $port,
        $path,
        'POST',
        [
            HandleIdempotencyMiddleware::HEADER => $key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
}

it('returns in-progress 409 for a concurrent duplicate Idempotency-Key over real HTTP', function () use ($idempotencyHttpState): void {
    $key = '550e8400-e29b-41d4-a716-446655440101';
    $body = json_encode(['name' => 'concurrent'], JSON_THROW_ON_ERROR);
    $headers = [
        HandleIdempotencyMiddleware::HEADER => $key,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
    $headerLines = array_map(
        static fn (string $name, string $value): string => "{$name}: {$value}",
        array_keys($headers),
        array_values($headers),
    );
    // Same path on both so fingerprints match; conflict never reaches the handler.
    // Stay under max_handler_seconds (1) so the winner completes as 201, not 503.
    $path = '/api/items?sleep_ms=800';
    $enteredFile = $idempotencyHttpState->cachePath.DIRECTORY_SEPARATOR.'handler_entered';
    @unlink($enteredFile);

    // Start A first and wait until its handler is entered (claim held) before B.
    $multi = curl_multi_init();
    $slow = curl_init("http://127.0.0.1:{$idempotencyHttpState->portA}{$path}");
    curl_setopt_array($slow, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_multi_add_handle($multi, $slow);

    $running = null;
    $deadline = microtime(true) + 3.0;

    do {
        curl_multi_exec($multi, $running);
        if (is_file($enteredFile)) {
            break;
        }
        curl_multi_select($multi, 0.05);
    } while (microtime(true) < $deadline);

    expect(is_file($enteredFile))->toBeTrue();

    $conflict = realHttpRequest(
        $idempotencyHttpState->portB,
        $path,
        'POST',
        $headers,
        $body,
    );

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running && $status === CURLM_OK);

    $raw = curl_multi_getcontent($slow);
    if ($raw === false || $raw === null) {
        throw new RuntimeException('slow concurrent request failed: '.curl_error($slow));
    }
    $winnerStatus = (int) curl_getinfo($slow, CURLINFO_RESPONSE_CODE);
    $parts = explode("\r\n\r\n", $raw, 2);
    $winnerBody = $parts[1] ?? '';
    curl_multi_remove_handle($multi, $slow);
    curl_close($slow);
    curl_multi_close($multi);

    expect($winnerStatus)->toBe(201)
        ->and($conflict['status'])->toBe(409)
        ->and(json_decode($winnerBody, true)['created'] ?? null)->toBeTrue()
        ->and(json_decode($conflict['body'], true)['error'] ?? null)->toBe(ErrorCode::IdempotencyInProgress->value)
        ->and($conflict['headers'])->toHaveKey('retry-after');

    $countResponse = realHttpRequest($idempotencyHttpState->portA, '/api/handler-count', 'GET');
    expect($countResponse['status'])->toBe(200)
        ->and(json_decode($countResponse['body'], true)['count'] ?? null)->toBe(1);
})->group('http-server');

it('rejects replay with a different body as 422 idempotency_payload_mismatch over real HTTP', function () use ($idempotencyHttpState): void {
    $key = '550e8400-e29b-41d4-a716-446655440102';

    $first = idempotencyJsonPost($idempotencyHttpState->portA, $key, ['name' => 'alpha']);
    expect($first['status'])->toBe(201);

    $mismatch = idempotencyJsonPost($idempotencyHttpState->portB, $key, ['name' => 'beta']);

    expect($mismatch['status'])->toBe(422)
        ->and(json_decode($mismatch['body'], true)['error'] ?? null)
        ->toBe(ErrorCode::IdempotencyPayloadMismatch->value);
})->group('http-server');

it('allows lock-expiry takeover after stale_after when the lock is gone over real HTTP', function () use ($idempotencyHttpState): void {
    $key = '550e8400-e29b-41d4-a716-446655440103';
    $body = json_encode(['name' => 'stale-takeover'], JSON_THROW_ON_ERROR);
    $headers = [
        HandleIdempotencyMiddleware::HEADER => $key,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    // Hold the first claim past lock_ttl (2s) and stale_after (1s) without completing.
    $multi = curl_multi_init();
    $slow = curl_init("http://127.0.0.1:{$idempotencyHttpState->portA}/api/items?sleep_ms=5000");
    curl_setopt_array($slow, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => array_map(
            static fn (string $name, string $value): string => "{$name}: {$value}",
            array_keys($headers),
            array_values($headers),
        ),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_multi_add_handle($multi, $slow);

    $running = null;
    curl_multi_exec($multi, $running);
    // Let the first worker claim the key and write the in_progress marker.
    usleep(300_000);
    curl_multi_exec($multi, $running);

    // Wait until the file-cache lock TTL expires and the marker is stale.
    sleep(3);

    $takeover = idempotencyJsonPost($idempotencyHttpState->portB, $key, ['name' => 'stale-takeover']);

    expect($takeover['status'])->toBe(201)
        ->and(json_decode($takeover['body'], true)['created'] ?? null)->toBeTrue();

    // Drain the slow request (may 503 on lease mismatch after takeover — not asserted).
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running && $status === CURLM_OK);

    curl_multi_remove_handle($multi, $slow);
    curl_close($slow);
    curl_multi_close($multi);
})->group('http-server');

it('does not re-run the handler when an oversized response is sealed unreplayable over real HTTP', function (): void {
    $server = __DIR__.'/fixtures/idempotency-server.php';
    $root = dirname(__DIR__, 1);
    $cachePath = sys_get_temp_dir().'/http-basics-idempotency-large-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($cachePath, 0777, true);
    $port = freeLocalPort();
    $environment = [
        'HTTP_BASICS_CACHE_PATH' => $cachePath,
        'HTTP_BASICS_IDEMPOTENCY_LOCK_TTL' => '5',
        'HTTP_BASICS_IDEMPOTENCY_STALE_AFTER' => '5',
        'HTTP_BASICS_IDEMPOTENCY_MAX_HANDLER' => '4',
        'HTTP_BASICS_IDEMPOTENCY_MAX_RESPONSE' => '64',
    ];
    $proc = startPhpBuiltInServer($port, $server, $root, '127.0.0.1', $environment);

    try {
        $key = '550e8400-e29b-41d4-a716-446655440301';
        $headers = [
            HandleIdempotencyMiddleware::HEADER => $key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $first = realHttpRequest($port, '/api/large', 'POST', $headers, '{}');
        expect($first['status'])->toBe(201)
            ->and(strlen($first['body']))->toBeGreaterThan(64);

        $second = realHttpRequest($port, '/api/large', 'POST', $headers, '{}');
        expect($second['status'])->toBe(409)
            ->and(json_decode($second['body'], true)['error'] ?? null)->toBe(ErrorCode::IdempotencyNotReplayable->value);

        $countResponse = realHttpRequest($port, '/api/handler-count', 'GET');
        expect(json_decode($countResponse['body'], true)['count'] ?? null)->toBe(1);
    } finally {
        stopPhpBuiltInServer($proc);
        // best-effort cleanup
        foreach (glob($cachePath.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($cachePath);
    }
})->group('http-server');
