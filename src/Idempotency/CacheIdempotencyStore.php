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
            if (($existing['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) {
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
            if (($existing['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) {
                // Fall through to overwrite.
            } else {
                $completed = $this->claimFromCompleted($existing, $fingerprint, $scope, $key);

                if ($completed !== null) {
                    $lock->release();

                    return $completed;
                }

                if (($existing['status'] ?? '') === 'in_progress') {
                    $startedAt = $this->intValue($existing['started_at'] ?? 0, 0);

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

        if (! is_string($claim->lease) || $claim->lease === '') {
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $lockKey = $recordKey.self::LOCK_SUFFIX;
        $existing = $this->cache->get($recordKey);

        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on complete.');
        }

        $this->cache->put($recordKey, [
            'fingerprint' => $existing['fingerprint'] ?? '',
            'lease' => $claim->lease,
            'status' => 'completed',
            'status_code' => $response->status,
            'headers' => $response->headers,
            'body' => $response->body,
            'started_at' => $this->intValue($existing['started_at'] ?? $now->getTimestamp(), $now->getTimestamp()),
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds,
        ], $this->ttlSeconds);

        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New) {
            throw new IdempotencyStoreException('Cannot seal a claim that is not new.');
        }

        if (! is_string($claim->lease) || $claim->lease === '') {
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $lockKey = $recordKey.self::LOCK_SUFFIX;
        $existing = $this->cache->get($recordKey);

        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on seal.');
        }

        $this->cache->put($recordKey, [
            'fingerprint' => $existing['fingerprint'] ?? '',
            'lease' => $claim->lease,
            'status' => 'completed',
            'status_code' => null,
            'headers' => null,
            'body' => null,
            'started_at' => $this->intValue($existing['started_at'] ?? $now->getTimestamp(), $now->getTimestamp()),
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds,
        ], $this->ttlSeconds);

        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New) {
            return;
        }

        if (! is_string($claim->lease) || $claim->lease === '') {
            return;
        }

        $recordKey = $claim->scope->cacheKey($claim->key);
        $lockKey = $recordKey.self::LOCK_SUFFIX;
        $existing = $this->cache->get($recordKey);

        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) {
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
        $expiresAt = $this->intValue($existing['expires_at'] ?? 0, 0);

        return $expiresAt > 0 && $expiresAt < $now->getTimestamp();
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
        if (($existing['status'] ?? '') !== 'completed') {
            return null;
        }

        if (! array_key_exists('body', $existing) || $existing['body'] === null) {
            if (($existing['status_code'] ?? null) === null) {
                return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key);
            }
        }

        if (($existing['fingerprint'] ?? '') === $fingerprint) {
            $replay = new ReplayableResponse(
                $this->intValue($existing['status_code'] ?? 200, 200),
                $this->headersValue($existing['headers'] ?? []),
                $this->stringValue($existing['body'] ?? ''),
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

        if (is_string($value) && is_numeric($value)) {
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
                    static fn (mixed $part): string => is_scalar($part) ? (string) $part : '',
                    $value,
                ));
            }
        }

        return $normalized;
    }
}
