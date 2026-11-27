<?php

declare(strict_types=1);

/*
 * The real-HTTP tests must talk to the server they started. A port already held by
 * another server (one left from an earlier test, or a shared one) used to pass the
 * readiness probe, and the test then ran against that server and its config.
 */

require_once __DIR__.'/Support/php-built-in-server.php';

it('refuses a port another process already listens on', function (): void {
    $occupant = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($occupant)->not->toBeFalse();
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($occupant, false), ':'), 1);

    try {
        expect(fn () => startPhpBuiltInServer($port, __DIR__.'/fixtures/idempotency-server.php', dirname(__DIR__), '127.0.0.1'))
            ->toThrow(RuntimeException::class, "php -S could not listen on {$port}");
    } finally {
        fclose($occupant);
    }
})->group('http-server');

it('hands out a port nothing listens on', function (): void {
    $port = freeLocalPort();

    expect($port)->toBeGreaterThan(0)
        ->and(@fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2))->toBeFalse();
})->group('http-server');
