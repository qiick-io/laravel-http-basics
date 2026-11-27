<?php

declare(strict_types=1);

use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Testing\FixedClock;
use Qiick\HttpBasics\Testing\InMemoryIdempotencyStore;

it('advances or rewinds the test clock by a signed second count', function (int $seconds): void {
    $initialTime = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $clock = new FixedClock($initialTime);

    $clock->advance($seconds);

    expect($clock->now()->getTimestamp())->toBe($initialTime->getTimestamp() + $seconds);
})->with([5, 0, -5]);

it('replays completed records with the same fingerprint', function (): void {
    $store = new InMemoryIdempotencyStore(lockTtlSeconds: 30, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';
    $fingerprint = 'fp-1';

    $claim = $store->claim($scope, $key, $fingerprint, $now, idempotencyContext());
    expect($claim->outcome)->toBe(ClaimOutcome::New);

    $store->complete($claim, new ReplayableResponse(201, ['Content-Type' => 'application/json'], '{"ok":true}'), $now);

    $replay = $store->claim($scope, $key, $fingerprint, $now, idempotencyContext());
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

it('answers a sealed record like the real stores: never replay, never re-run', function (): void {
    // The double stores typed responses, so a sealed record is its only no-status case.
    $store = new InMemoryIdempotencyStore(lockTtlSeconds: 30, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440099';

    $store->sealUnreplayable($store->claim($scope, $key, 'fp', $now, idempotencyContext()), $now);

    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable)
        ->and($store->claim($scope, $key, 'other', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable);
});

it('keeps a completed record for exactly the default TTL', function (): void {
    $store = new InMemoryIdempotencyStore;
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $store->complete($store->claim($scope, 'ttl', 'fp', $now, idempotencyContext()), new ReplayableResponse(201, ['X' => '1'], 'done'), $now);

    $atExpiry = $store->claim($scope, 'ttl', 'fp', $now->modify('+86400 seconds'), idempotencyContext());

    expect($atExpiry->outcome)->toBe(ClaimOutcome::Replay)
        ->and($atExpiry->replay?->status)->toBe(201)
        ->and($atExpiry->replay?->headers)->toBe(['X' => '1'])
        ->and($atExpiry->replay?->body)->toBe('done')
        ->and($store->claim($scope, 'ttl', 'fp', $now->modify('+86401 seconds'), idempotencyContext())->outcome)->toBe(ClaimOutcome::New);
});

it('holds the lock for exactly the default lock TTL', function (): void {
    // stale_after 0: only the lock can refuse the claim.
    $store = new InMemoryIdempotencyStore(staleAfterSeconds: 0);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $store->claim($scope, 'lock', 'fp', $now, idempotencyContext());

    expect($store->claim($scope, 'lock', 'fp', $now->modify('+59 seconds'), idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict)
        ->and($store->claim($scope, 'lock', 'fp', $now->modify('+60 seconds'), idempotencyContext())->outcome)->toBe(ClaimOutcome::New);
});

it('treats an in-progress record as live for exactly the default stale_after', function (): void {
    // lock TTL 0: only staleness can refuse the claim.
    $store = new InMemoryIdempotencyStore(lockTtlSeconds: 0);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $store->claim($scope, 'stale', 'fp', $now, idempotencyContext());

    expect($store->claim($scope, 'stale', 'fp', $now->modify('+59 seconds'), idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict)
        ->and($store->claim($scope, 'stale', 'fp', $now->modify('+60 seconds'), idempotencyContext())->outcome)->toBe(ClaimOutcome::New);
});

it('hands out a 64-character lease', function (): void {
    $claim = (new InMemoryIdempotencyStore)->claim(new IdempotencyScope('bucket-a', 'POST', '/items'), 'lease', 'fp', new DateTimeImmutable, idempotencyContext());

    expect($claim->lease)->toMatch('/\A[0-9a-f]{64}\z/');
});

it('ignores abandon from a claim that is not new, even with the right lease', function (): void {
    $store = new InMemoryIdempotencyStore;
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $claim = $store->claim($scope, 'held', 'fp', $now, idempotencyContext());

    $store->abandon(new IdempotencyClaim(ClaimOutcome::Replay, null, $claim->lease, $scope, 'held'), $now);

    expect($store->claim($scope, 'held', 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);
});

it('refuses to finish a claim whose lease is missing or empty', function (?string $lease): void {
    $store = new InMemoryIdempotencyStore;
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $store->claim($scope, 'k', 'fp', $now, idempotencyContext());

    expect(fn () => $store->complete(new IdempotencyClaim(ClaimOutcome::New, null, $lease, $scope, 'k'), new ReplayableResponse(201, [], 'x'), $now))
        ->toThrow(IdempotencyStoreException::class, 'Idempotency lease mismatch on complete.');
})->with([null, '']);
