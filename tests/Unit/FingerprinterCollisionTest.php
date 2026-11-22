<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Qiick\HttpBasics\Idempotency\Fingerprinter;

/**
 * Fingerprint collisions the adversarial review required to stay distinct.
 */
function fingerprintRequest(string $query, string $body = '{}'): string
{
    $request = Request::create('/api/items?'.$query, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'QUERY_STRING' => $query,
    ], $body);

    return (new Fingerprinter)->fingerprint($request, 1048576);
}

it('keeps repeated query key order so a=1&a=2 differs from a=2', function (): void {
    expect(fingerprintRequest('a=1&a=2'))->not->toBe(fingerprintRequest('a=2'));
});

it('distinguishes a[]=1 from a=1', function (): void {
    expect(fingerprintRequest('a[]=1'))->not->toBe(fingerprintRequest('a=1'));
});

it('distinguishes a[b]=1 from a[c]=1', function (): void {
    expect(fingerprintRequest('a[b]=1'))->not->toBe(fingerprintRequest('a[c]=1'));
});

it('distinguishes empty JSON object from empty JSON array', function (): void {
    expect(fingerprintRequest('', '{}'))->not->toBe(fingerprintRequest('', '[]'));
});

it('preserves integers above 2^63 via JSON_BIGINT_AS_STRING', function (): void {
    $big = '9223372036854775808';
    $withString = fingerprintRequest('', '{"n":'.$big.'}');
    $withQuoted = fingerprintRequest('', '{"n":"'.$big.'"}');

    expect($withString)->toBe($withQuoted)
        ->and($withString)->not->toBe(fingerprintRequest('', '{"n":9223372036854775807}'));
});
