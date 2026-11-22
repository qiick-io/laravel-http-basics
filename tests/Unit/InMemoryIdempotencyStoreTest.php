<?php

declare(strict_types=1);

use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Testing\FixedClock;
use Qiick\HttpBasics\Testing\InMemoryIdempotencyStore;

it('replays completed records with the same fingerprint', function (): void {
    $store = new InMemoryIdempotencyStore(lockTtlSeconds: 30, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';
    $fingerprint = 'fp-1';

    $claim = $store->claim($scope, $key, $fingerprint, $now);
    expect($claim->outcome)->toBe(ClaimOutcome::New);

    $store->complete($claim, new ReplayableResponse(201, ['Content-Type' => 'application/json'], '{"ok":true}'), $now);

    $replay = $store->claim($scope, $key, $fingerprint, $now);
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(201)
        ->and($replay->replay?->body)->toBe('{"ok":true}');
});

it('returns mismatch for the same key with a different fingerprint', function (): void {
    $store = new InMemoryIdempotencyStore;
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claim = $store->claim($scope, $key, 'fp-1', $now);
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);

    $mismatch = $store->claim($scope, $key, 'fp-2', $now);
    expect($mismatch->outcome)->toBe(ClaimOutcome::Mismatch);
});

it('conflicts while a lock is held and allows stale takeover only after the lock expires', function (): void {
    $clock = new FixedClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $store = new InMemoryIdempotencyStore(lockTtlSeconds: 5, staleAfterSeconds: 1);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $first = $store->claim($scope, $key, 'fp-1', $clock->now());
    expect($first->outcome)->toBe(ClaimOutcome::New);

    $conflict = $store->claim($scope, $key, 'fp-1', $clock->now());
    expect($conflict->outcome)->toBe(ClaimOutcome::Conflict);

    $clock->advance(6);
    $takeover = $store->claim($scope, $key, 'fp-1', $clock->now());
    expect($takeover->outcome)->toBe(ClaimOutcome::New);
});

it('isolates buckets', function (): void {
    $store = new InMemoryIdempotencyStore;
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claimA = $store->claim(new IdempotencyScope('bucket-a', 'POST', '/items'), $key, 'fp', $now);
    $store->complete($claimA, new ReplayableResponse(200, [], 'a'), $now);

    $claimB = $store->claim(new IdempotencyScope('bucket-b', 'POST', '/items'), $key, 'fp', $now);
    expect($claimB->outcome)->toBe(ClaimOutcome::New);
});

it('fails closed on lease mismatch complete', function (): void {
    $store = new InMemoryIdempotencyStore;
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claim = $store->claim($scope, $key, 'fp-1', $now);
    $tampered = new IdempotencyClaim(
        ClaimOutcome::New,
        null,
        'wrong-lease',
        $scope,
        $key,
    );

    $store->complete($tampered, new ReplayableResponse(200, [], 'x'), $now);
})->throws(IdempotencyStoreException::class);
