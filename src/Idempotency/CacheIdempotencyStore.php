<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use DateTimeImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Qiick\HttpBasics\Contracts\IdempotencyStore;

/**
 * @api Cache-backed idempotency store with driver-native locks.
 */
final class CacheIdempotencyStore implements IdempotencyStore
{
    private const string LOCK_SUFFIX = ':lock';

    private LockProvider $locks;

    private SealedKeyMarker $sealedKeys;

    public function __construct(
        private Repository $cache,
        private int $ttlSeconds,
        private int $lockTtlSeconds,
        private int $staleAfterSeconds,
    ) {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new \InvalidArgumentException('The cache store must support atomic locks.');
        }

        $this->locks = $store;
        $this->sealedKeys = new SealedKeyMarker($cache, $ttlSeconds);
    }

    public function claim(
        IdempotencyScope $scope,
        string $key,
        string $fingerprint,
        DateTimeImmutable $now,
    ): IdempotencyClaim {
        if ($this->sealedKeys->isMarked($scope, $key)) {
            return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key);
        }

        $recordKey = $scope->cacheKey($key);
        $existing = $this->liveRecord($recordKey, $now);

        if ($existing !== null) {
            $completed = $this->claimFromCompleted($existing, $fingerprint, $scope, $key);

            if ($completed !== null) {
                return $completed;
            }
        }

        $lease = bin2hex(random_bytes(32));
        $lock = $this->lockFor($recordKey, $lease);

        if (! $lock->get()) {
            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        // Re-read under the lock: another worker may have claimed or completed meanwhile.
        $existing = $this->liveRecord($recordKey, $now);

        if ($existing !== null) {
            $completed = $this->claimFromCompleted($existing, $fingerprint, $scope, $key);

            if ($completed !== null) {
                $lock->release();

                return $completed;
            }

            // An in-progress claim younger than stale_after belongs to a live worker; one
            // without a readable start time is stale and may be taken over.
            $startedAt = $this->intValue($existing['started_at'] ?? null);

            if ($startedAt !== null && $now->getTimestamp() - $startedAt < $this->staleAfterSeconds) {
                $lock->release();

                return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
            }
        }

        $marker = [
            'fingerprint' => $fingerprint,
            'lease' => $lease,
            'status' => 'in_progress',
            'started_at' => $now->getTimestamp(),
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds,
        ];
        $this->cache->put($recordKey, $marker, $this->ttlSeconds);

        return new IdempotencyClaim(ClaimOutcome::New, null, $lease, $scope, $key);
    }

    public function complete(
        IdempotencyClaim $claim,
        ReplayableResponse $response,
        DateTimeImmutable $now,
    ): void {
        if ($claim->outcome !== ClaimOutcome::New) {
            throw new IdempotencyStoreException('Cannot complete a claim that is not new.');
        }

        if (! $this->hasLease($claim)) {
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $existing = $this->cache->get($recordKey);

        if (! is_array($existing) || ($existing['lease'] ?? null) !== $claim->lease) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on complete.');
        }

        $payload = $this->completedRecord($existing, $claim, $now, $response->status, $response->headers, $response->body);

        try {
            $written = $this->cache->put($recordKey, $payload, $this->ttlSeconds);
        } catch (\Throwable $exception) {
            $this->failComplete($claim, $now, $exception);
        }

        if ($written === false) {
            $this->failComplete($claim, $now, null);
        }

        $this->lockFor($recordKey, $claim->lease)->release();
    }

    public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New) {
            throw new IdempotencyStoreException('Cannot seal a claim that is not new.');
        }

        if (! $this->hasLease($claim)) {
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $existing = $this->cache->get($recordKey);

        if (! is_array($existing) || ($existing['lease'] ?? null) !== $claim->lease) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on seal.');
        }

        // A sealed record has no status code: claim() answers NotReplayable for it.
        try {
            $isSealed = $this->cache->put($recordKey, $this->completedRecord($existing, $claim, $now, null, null, null), $this->ttlSeconds);
        } catch (\Throwable) {
            $isSealed = false;
        }

        if (! $isSealed && ! $this->sealedKeys->mark($claim->scope, $claim->key)) {
            // Nothing durable says the handler ran; the lock is kept until lock_ttl.
            throw new IdempotencyStoreException('Failed to seal unreplayable marker.');
        }

        $this->lockFor($recordKey, $claim->lease)->release();
    }

    /**
     * After the handler ran and its response could not be stored, seal the key so a
     * retry cannot run the handler again, and say whether even that failed.
     *
     * @throws IdempotencyStoreException always
     */
    private function failComplete(IdempotencyClaim $claim, DateTimeImmutable $now, ?\Throwable $cause): never
    {
        try {
            $this->sealUnreplayable($claim, $now);
        } catch (IdempotencyStoreException $sealFailure) {
            throw new IdempotencyStoreException('Failed to persist idempotent response and to seal it unreplayable.', previous: $sealFailure);
        }

        throw new IdempotencyStoreException('Failed to persist idempotent response; sealed unreplayable.', previous: $cause);
    }

    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New || ! $this->hasLease($claim)) {
            return;
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $existing = $this->cache->get($recordKey);

        // Only the claim's own marker is removed: a foreign lease is a no-op.
        if (! is_array($existing) || ($existing['lease'] ?? null) !== $claim->lease) {
            return;
        }

        $this->cache->forget($recordKey);
        $this->lockFor($recordKey, $claim->lease)->release();
    }

    /**
     * The stored record, or null when there is none or it is a completed record past
     * its `expires_at` (the cache TTL may not have evicted it yet), which may be
     * claimed again.
     *
     * @return array<string, mixed>|null
     */
    private function liveRecord(string $recordKey, DateTimeImmutable $now): ?array
    {
        $existing = $this->cache->get($recordKey);

        if (! is_array($existing)) {
            return null;
        }

        /** @var array<string, mixed> $existing */
        $expiresAt = $this->intValue($existing['expires_at'] ?? null);
        $isExpiredCompletion = ($existing['status'] ?? null) === 'completed'
            && $expiresAt !== null
            && $expiresAt < $now->getTimestamp();

        return $isExpiredCompletion ? null : $existing;
    }

    private function lockFor(string $recordKey, string $owner): Lock
    {
        return $this->locks->lock($recordKey.self::LOCK_SUFFIX, $this->lockTtlSeconds, $owner);
    }

    /**
     * @phpstan-assert-if-true non-empty-string $claim->lease
     */
    private function hasLease(IdempotencyClaim $claim): bool
    {
        return is_string($claim->lease) && $claim->lease !== '';
    }

    /**
     * @param  array<mixed>  $existing
     * @param  array<string, string>|null  $headers
     * @return array<string, mixed>
     */
    private function completedRecord(
        array $existing,
        IdempotencyClaim $claim,
        DateTimeImmutable $now,
        ?int $statusCode,
        ?array $headers,
        ?string $body,
    ): array {
        return [
            'fingerprint' => $existing['fingerprint'] ?? '',
            'lease' => $claim->lease,
            'status' => 'completed',
            'status_code' => $statusCode,
            'headers' => $headers,
            'body' => $body,
            'started_at' => $this->intValue($existing['started_at'] ?? null) ?? $now->getTimestamp(),
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds,
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function claimFromCompleted(
        array $existing,
        string $fingerprint,
        IdempotencyScope $scope,
        string $key,
    ): ?IdempotencyClaim {
        if (($existing['status'] ?? null) !== 'completed') {
            return null;
        }

        $statusCode = $this->intValue($existing['status_code'] ?? null);

        // A completed record without a readable status code means the handler ran but
        // its response was not stored (sealed, or corrupt): never re-execute or guess.
        if ($statusCode === null) {
            return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key);
        }

        if (($existing['fingerprint'] ?? null) === $fingerprint) {
            $replay = new ReplayableResponse(
                $statusCode,
                $this->headersValue($existing['headers'] ?? null),
                $this->stringValue($existing['body'] ?? null),
            );

            return new IdempotencyClaim(ClaimOutcome::Replay, $replay, null, $scope, $key);
        }

        return new IdempotencyClaim(ClaimOutcome::Mismatch, null, null, $scope, $key);
    }

    /**
     * Records written by other drivers or versions may hold numeric strings.
     */
    private function intValue(mixed $value): ?int
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (int) $value;
        }

        return is_int($value) ? $value : null;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * complete() stores the sanitized `array<string, string>` headers; a list value
     * from another writer is joined, and anything else is dropped.
     *
     * @return array<string, string>
     */
    private function headersValue(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            if (is_string($value)) {
                $normalized[$name] = $value;
            } elseif (is_array($value)) {
                $normalized[$name] = implode(', ', array_map(
                    static fn (mixed $part): string => is_scalar($part) ? (string) $part : '',
                    $value,
                ));
            }
        }

        return $normalized;
    }
}
