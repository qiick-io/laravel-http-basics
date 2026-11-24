<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use DateTimeImmutable;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyContext;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

/**
 * In-memory idempotency store for unit and kernel tests: one process, so no
 * re-read under the lock is needed, but the same outcomes, leases, lock TTL,
 * stale takeover and expiry as the real stores.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /**
     * `completed` is null while the claim is in progress; a completed record without
     * a replay is sealed (the handler ran, there is nothing to replay).
     *
     * @var array<string, array{fingerprint: string, lease: string, startedAt: int, completed: array{replay: ?ReplayableResponse, expiresAt: int}|null}>
     */
    private array $records = [];

    /** @var array<string, int> lock expiry per record key */
    private array $lockExpiries = [];

    public function __construct(
        private int $ttlSeconds = 86400,
        private int $lockTtlSeconds = 60,
        private int $staleAfterSeconds = 60,
    ) {}

    public function claim(
        IdempotencyScope $scope,
        string $key,
        string $fingerprint,
        DateTimeImmutable $now,
        IdempotencyContext $context,
    ): IdempotencyClaim {
        $recordKey = $scope->cacheKey($key);
        $record = $this->records[$recordKey] ?? null;
        $completed = $record === null ? null : $record['completed'];

        if ($record !== null && $completed !== null && $completed['expiresAt'] >= $now->getTimestamp()) {
            return $this->settledClaim($record['fingerprint'], $completed['replay'], $fingerprint, $scope, $key);
        }

        $isLocked = isset($this->lockExpiries[$recordKey]) && $this->lockExpiries[$recordKey] > $now->getTimestamp();
        // An in-progress record younger than stale_after belongs to a live request.
        $isInFlight = $record !== null && $completed === null
            && $now->getTimestamp() - $record['startedAt'] < $this->staleAfterSeconds;

        if ($isLocked || $isInFlight) {
            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        $lease = bin2hex(random_bytes(32));
        $this->lockExpiries[$recordKey] = $now->getTimestamp() + $this->lockTtlSeconds;
        $this->records[$recordKey] = [
            'fingerprint' => $fingerprint,
            'lease' => $lease,
            'startedAt' => $now->getTimestamp(),
            'completed' => null,
        ];

        return new IdempotencyClaim(ClaimOutcome::New, null, $lease, $scope, $key);
    }

    public function complete(
        IdempotencyClaim $claim,
        ReplayableResponse $response,
        DateTimeImmutable $now,
    ): void {
        $this->finish($claim, $now, 'Cannot complete a claim that is not new.', 'Idempotency lease mismatch on complete.', $response);
    }

    public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        $this->finish($claim, $now, 'Cannot seal a claim that is not new.', 'Idempotency lease mismatch on seal.', null);
    }

    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        $recordKey = $claim->scope->cacheKey($claim->key);

        // Only a new claim's own marker and lock are removed: anything else is a no-op.
        if ($claim->outcome === ClaimOutcome::New && ($this->records[$recordKey]['lease'] ?? null) === $claim->lease) {
            unset($this->records[$recordKey], $this->lockExpiries[$recordKey]);
        }
    }

    /**
     * Completes the record (sealed when `$replay` is null) under the claim's own
     * lease, then frees its lock.
     *
     * @throws IdempotencyStoreException when the claim is not new or does not own the record
     */
    private function finish(
        IdempotencyClaim $claim,
        DateTimeImmutable $now,
        string $notNewMessage,
        string $mismatchMessage,
        ?ReplayableResponse $replay,
    ): void {
        if ($claim->outcome !== ClaimOutcome::New) {
            throw new IdempotencyStoreException($notNewMessage);
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $record = $this->records[$recordKey] ?? null;

        // A missing or empty lease never matches the 64-character lease of a record.
        if ($record === null || $record['lease'] !== $claim->lease) {
            throw new IdempotencyStoreException($mismatchMessage);
        }

        $record['completed'] = ['replay' => $replay, 'expiresAt' => $now->getTimestamp() + $this->ttlSeconds];
        $this->records[$recordKey] = $record;
        unset($this->lockExpiries[$recordKey]);
    }

    /**
     * The answer a live completed record gives: NotReplayable when sealed, otherwise
     * a replay for the same fingerprint and a mismatch for any other.
     */
    private function settledClaim(
        string $storedFingerprint,
        ?ReplayableResponse $replay,
        string $fingerprint,
        IdempotencyScope $scope,
        string $key,
    ): IdempotencyClaim {
        if ($replay === null) {
            return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key);
        }

        return $storedFingerprint === $fingerprint
            ? new IdempotencyClaim(ClaimOutcome::Replay, $replay, null, $scope, $key)
            : new IdempotencyClaim(ClaimOutcome::Mismatch, null, null, $scope, $key);
    }
}
