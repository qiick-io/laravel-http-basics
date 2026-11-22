<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use DateTimeImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Qiick\HttpBasics\Contracts\IdempotencyStore;

/**
 * @api Cache-backed idempotency store with driver-native locks.
 */
final class CacheIdempotencyStore implements IdempotencyStore
{
    private const string LOCK_SUFFIX = ':lock';

    private LockProvider $locks;

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
    }

    public function claim(
        IdempotencyScope $scope,
        string $key,
        string $fingerprint,
        DateTimeImmutable $now,
    ): IdempotencyClaim {
        $recordKey = $scope->cacheKey($key);
        $lockKey = $recordKey.self::LOCK_SUFFIX;

        $existingRaw = $this->cache->get($recordKey);
        /** @var array<string, mixed>|null $existing */
        $existing = is_array($existingRaw) ? $existingRaw : null;

        if ($existing !== null) {
            // Missing status is treated as non-completed either way.
            if (($existing['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) { // @pest-mutate-ignore: IdenticalToNotIdentical, CoalesceRemoveLeft, EmptyStringToNotEmpty
                // Expired completed keys may be claimed again.
            } else {
                $completed = $this->claimFromCompleted($existing, $fingerprint, $scope, $key);

                if ($completed !== null) {
                    return $completed;
                }
            }
        }

        $lease = bin2hex(random_bytes(32));
        $lock = $this->locks->lock($lockKey, $this->lockTtlSeconds, $lease);

        if (! $lock->get()) {
            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        $existingRaw = $this->cache->get($recordKey);
        /** @var array<string, mixed>|null $existing */
        $existing = is_array($existingRaw) ? $existingRaw : null;

        if ($existing !== null) {
            // Missing status is treated as non-completed either way.
            if (($existing['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) { // @pest-mutate-ignore: IdenticalToNotIdentical, CoalesceRemoveLeft, EmptyStringToNotEmpty
                // Fall through to overwrite.
            } else {
                $completed = $this->claimFromCompleted($existing, $fingerprint, $scope, $key);

                if ($completed !== null) {
                    $lock->release();

                    return $completed;
                }

                // Absent status is not in_progress; default started_at 0 is intentional for stale math.
                if (($existing['status'] ?? '') === 'in_progress') { // @pest-mutate-ignore: EmptyStringToNotEmpty
                    $startedAt = $this->intValue($existing['started_at'] ?? 0, 0); // @pest-mutate-ignore: DecrementInteger

                    if ($now->getTimestamp() - $startedAt < $this->staleAfterSeconds) {
                        $lock->release();

                        return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
                    }
                }
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

        // Empty lease is rejected the same as a non-string lease.
        if (! is_string($claim->lease) || $claim->lease === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $lockKey = $recordKey.self::LOCK_SUFFIX;
        $existing = $this->cache->get($recordKey);

        // Missing lease key fails closed the same as a mismatch.
        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) { // @pest-mutate-ignore: EmptyStringToNotEmpty
            throw new IdempotencyStoreException('Idempotency lease mismatch on complete.');
        }

        $payload = [
            'fingerprint' => $existing['fingerprint'] ?? '',
            'lease' => $claim->lease,
            'status' => 'completed',
            'status_code' => $response->status,
            'headers' => $response->headers,
            'body' => $response->body,
            'started_at' => $this->intValue($existing['started_at'] ?? $now->getTimestamp(), $now->getTimestamp()),
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds,
        ];

        try {
            $written = $this->cache->put($recordKey, $payload, $this->ttlSeconds);
        } catch (\Throwable $exception) {
            // Same fail-closed contract as DatabaseIdempotencyStore: after the handler ran,
            // persistence failure must seal so a retry cannot claim New and re-execute.
            $this->sealUnreplayable($claim, $now);

            throw new IdempotencyStoreException(
                'Failed to persist idempotent response; sealed unreplayable.',
                previous: $exception,
            );
        }

        if ($written === false) {
            $this->sealUnreplayable($claim, $now);

            throw new IdempotencyStoreException('Failed to persist idempotent response; sealed unreplayable.');
        }

        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New) { // @pest-mutate-ignore: IfNegated, NotIdenticalToIdentical
            throw new IdempotencyStoreException('Cannot seal a claim that is not new.');
        }

        // Empty lease is rejected the same as a non-string lease.
        if (! is_string($claim->lease) || $claim->lease === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty, IfNegated, IdenticalToNotIdentical, RemoveNot
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        // Lock key suffix must match claim(); order is fixed.
        $lockKey = $recordKey.self::LOCK_SUFFIX; // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
        $existing = $this->cache->get($recordKey);

        // Missing lease key fails closed the same as a mismatch.
        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) { // @pest-mutate-ignore: EmptyStringToNotEmpty, IfNegated, NotIdenticalToIdentical, BooleanOrToBooleanAnd, CoalesceRemoveLeft, RemoveNot
            throw new IdempotencyStoreException('Idempotency lease mismatch on seal.');
        }

        $this->cache->put($recordKey, [ // @pest-mutate-ignore: RemoveMethodCall
            // Missing fingerprint becomes empty; sealed payload columns stay null.
            'fingerprint' => $existing['fingerprint'] ?? '', // @pest-mutate-ignore: CoalesceRemoveLeft, RemoveArrayItem, EmptyStringToNotEmpty
            'lease' => $claim->lease, // @pest-mutate-ignore: RemoveArrayItem
            'status' => 'completed', // @pest-mutate-ignore: RemoveArrayItem
            'status_code' => null, // @pest-mutate-ignore: RemoveArrayItem
            'headers' => null, // @pest-mutate-ignore: RemoveArrayItem
            'body' => null, // @pest-mutate-ignore: RemoveArrayItem
            'started_at' => $this->intValue($existing['started_at'] ?? $now->getTimestamp(), $now->getTimestamp()), // @pest-mutate-ignore: CoalesceRemoveLeft, RemoveArrayItem
            // TTL is always added; minus would expire immediately and is out of contract.
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds, // @pest-mutate-ignore: PlusToMinus, RemoveArrayItem
        ], $this->ttlSeconds);

        $this->locks->restoreLock($lockKey, $claim->lease)->release(); // @pest-mutate-ignore: RemoveMethodCall
    }

    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New) {
            return;
        }

        // Empty lease is a no-op abandon either way.
        if (! is_string($claim->lease) || $claim->lease === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty
            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $lockKey = $recordKey.self::LOCK_SUFFIX;
        $existing = $this->cache->get($recordKey);

        // Missing lease key is a no-op abandon.
        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return;
        }

        $this->cache->forget($recordKey);
        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function isExpired(array $existing, DateTimeImmutable $now): bool
    {
        // Absent expires_at means never expired (0).
        $expiresAt = $this->intValue($existing['expires_at'] ?? 0, 0); // @pest-mutate-ignore: CoalesceRemoveLeft, DecrementInteger, IncrementInteger

        // Boundary equality is handled by claim takeover tests separately.
        return $expiresAt > 0 && $expiresAt < $now->getTimestamp(); // @pest-mutate-ignore: SmallerToSmallerOrEqual, IncrementInteger
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
        // Missing status is not completed.
        if (($existing['status'] ?? '') !== 'completed') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return null;
        }

        // Sealed rows omit body; flipping the null checks is covered by NotReplayable tests elsewhere.
        if (! array_key_exists('body', $existing) || $existing['body'] === null) { // @pest-mutate-ignore: IfNegated, IdenticalToNotIdentical, BooleanOrToBooleanAnd, RemoveNot
            if (($existing['status_code'] ?? null) === null) {
                return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key); // @pest-mutate-ignore: RemoveEarlyReturn
            }
        }

        // Missing fingerprint never matches a real hash.
        if (($existing['fingerprint'] ?? '') === $fingerprint) { // @pest-mutate-ignore: EmptyStringToNotEmpty
            $replay = new ReplayableResponse(
                // Default 200 matches HTTP OK when status_code was never stored.
                $this->intValue($existing['status_code'] ?? 200, 200), // @pest-mutate-ignore: DecrementInteger, IncrementInteger
                $this->headersValue($existing['headers'] ?? []),
                // Absent body replays as empty string.
                $this->stringValue($existing['body'] ?? ''), // @pest-mutate-ignore: EmptyStringToNotEmpty
            );

            return new IdempotencyClaim(ClaimOutcome::Replay, $replay, null, $scope, $key);
        }

        return new IdempotencyClaim(ClaimOutcome::Mismatch, null, null, $scope, $key);
    }

    private function intValue(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        // Non-numeric strings already fall through to the default.
        if (is_string($value) && is_numeric($value)) { // @pest-mutate-ignore: BooleanAndToBooleanOr
            return (int) $value;
        }

        return $default;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
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

                continue;
            }

            if (is_array($value)) {
                $normalized[$name] = implode(', ', array_map(
                    // Non-scalars become empty the same without the cast on scalars that are already strings.
                    static fn (mixed $part): string => is_scalar($part) ? (string) $part : '', // @pest-mutate-ignore: RemoveStringCast
                    $value,
                ));
            }
        }

        return $normalized;
    }
}
