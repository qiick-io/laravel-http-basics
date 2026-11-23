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

    $claim = $store->claim($scope, $key, $fingerprint, $now, $context);
    expect($claim->outcome)->toBe(ClaimOutcome::New);

    $store->complete($claim, new ReplayableResponse(201, ['Content-Type' => 'application/json'], '{"ok":true}'), $now);

    $replay = $store->claim($scope, $key, $fingerprint, $now, $context);
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(201)
        ->and($replay->replay?->body)->toBe('{"ok":true}');
});

it('returns mismatch for the same key with a different fingerprint', function (): void {
    $store = new InMemoryIdempotencyStore;
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claim = $store->claim($scope, $key, 'fp-1', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);

    $mismatch = $store->claim($scope, $key, 'fp-2', $now, idempotencyContext());
    expect($mismatch->outcome)->toBe(ClaimOutcome::Mismatch);
});

it('conflicts while a lock is held and allows stale takeover only after the lock expires', function (): void {
    $clock = new FixedClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $store = new InMemoryIdempotencyStore(lockTtlSeconds: 5, staleAfterSeconds: 1);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $first = $store->claim($scope, $key, 'fp-1', $clock->now(), idempotencyContext());
    expect($first->outcome)->toBe(ClaimOutcome::New);

    $conflict = $store->claim($scope, $key, 'fp-1', $clock->now(), idempotencyContext());
    expect($conflict->outcome)->toBe(ClaimOutcome::Conflict);

    $clock->advance(6);
    $takeover = $store->claim($scope, $key, 'fp-1', $clock->now(), idempotencyContext());
    expect($takeover->outcome)->toBe(ClaimOutcome::New);
});

it('isolates buckets', function (): void {
    $store = new InMemoryIdempotencyStore;
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claimA = $store->claim(new IdempotencyScope('bucket-a', 'POST', '/items'), $key, 'fp', $now, idempotencyContext());
    $store->complete($claimA, new ReplayableResponse(200, [], 'a'), $now);

    $claimB = $store->claim(new IdempotencyScope('bucket-b', 'POST', '/items'), $key, 'fp', $now, idempotencyContext());
    expect($claimB->outcome)->toBe(ClaimOutcome::New);
});

it('fails closed on lease mismatch complete', function (): void {
    $store = new InMemoryIdempotencyStore;
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claim = $store->claim($scope, $key, 'fp-1', $now, idempotencyContext());
    $tampered = new IdempotencyClaim(
        ClaimOutcome::New,
        null,
        'wrong-lease',
        $scope,
        $key,
    );

    $store->complete($tampered, new ReplayableResponse(200, [], 'x'), $now);
})->throws(IdempotencyStoreException::class);

it('answers a completed record with an unreadable status code like the real stores', function (mixed $statusCode): void {
    $store = new InMemoryIdempotencyStore(lockTtlSeconds: 30, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440099';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(201, [], 'stored'), $now);

    // The double has no public way to corrupt a record; the real stores can read one back.
    $records = new ReflectionProperty($store, 'records');
    $stored = $records->getValue($store);
    $recordKey = array_key_first($stored);
    $stored[$recordKey]['status_code'] = $statusCode;
    $records->setValue($store, $stored);

    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable);
})->with(['not numeric' => ['abc'], 'missing' => [null]]);
