<?php

declare(strict_types=1);

use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdSource;

it('accepts a single PATTERN-matching value', function (): void {
    $id = RequestId::tryFromHeaderValues(['abc-def-12']);

    expect($id)->not->toBeNull()
        ->and($id?->value)->toBe('abc-def-12');
});

it('rejects values outside the PATTERN', function (string $bad): void {
    expect(RequestId::tryFromHeaderValues([$bad]))->toBeNull();
})->with([
    'too short' => ['abc'],
    'space' => ['bad id value'],
    'slash' => ['bad/id/value'],
    'empty' => [''],
    'punctuation only' => ['--------'],
    'dots only' => ['........'],
]);

it('rejects duplicate header values even when each matches', function (): void {
    expect(RequestId::tryFromHeaderValues(['abcdefgh', 'abcdefgh']))->toBeNull();
});

it('rejects when any sibling value fails the PATTERN', function (): void {
    expect(RequestId::tryFromHeaderValues(['abcdefgh', 'bad id']))->toBeNull();
});

it('returns outbound headers keyed by X-Request-Id', function (): void {
    $id = new RequestId('outbound-id-1', RequestIdSource::Generated);

    expect($id->outboundHeaders())->toBe([RequestId::HEADER => 'outbound-id-1']);
});

it('skips non-string header values and keeps a single valid candidate', function (): void {
    $id = RequestId::tryFromHeaderValues([null, 'abcdefgh']);

    expect($id)->not->toBeNull()
        ->and($id?->value)->toBe('abcdefgh');
});
