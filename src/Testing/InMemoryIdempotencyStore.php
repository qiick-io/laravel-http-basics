<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use DateTimeImmutable;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

/**
 * In-memory idempotency store for unit/kernel tests (single process, real locks).
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array<string, mixed>> */
    private array $records = [];

    /** @var array<string, array{lease: string, expires_at: int}> */
    private array $locks = [];

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
    ): IdempotencyClaim {
        $recordKey = $scope->cacheKey($key);
        $lockKey = $recordKey.':lock';

        $existing = $this->records[$recordKey] ?? null;
        if (is_array($existing)) {
            if (($existing['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) {
                // Expired completed keys may be claimed again.
            } else {
                $completed = $this->claimFromCompleted($existing, $fingerprint, $scope, $key);
                if ($completed !== null) {
                    return $completed;
                }
            }
        }

        if ($this->isLockHeld($lockKey, $now)) {
            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        $lease = bin2hex(random_bytes(32));
        $this->acquireLock($lockKey, $lease, $now);

        $existing = $this->records[$recordKey] ?? null;
        if (is_array($existing)) {
            if (($existing['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) {
                // Fall through to overwrite.
            } else {
                $completed = $this->claimFromCompleted($existing, $fingerprint, $scope, $key);
                if ($completed !== null) {
                    $this->releaseLock($lockKey, $lease);

                    return $completed;
                }

                if (($existing['status'] ?? '') === 'in_progress') {
                    $startedAt = $this->intValue($existing['started_at'] ?? 0, 0);
                    if ($now->getTimestamp() - $startedAt < $this->staleAfterSeconds) {
                        $this->releaseLock($lockKey, $lease);

                        return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
                    }
                }
            }
        }

        $this->records[$recordKey] = [
            'fingerprint' => $fingerprint,
            'lease' => $lease,
            'status' => 'in_progress',
            'started_at' => $now->getTimestamp(),
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds,
        ];

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
        $lockKey = $recordKey.':lock';
        $existing = $this->records[$recordKey] ?? null;

        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on complete.');
        }

        $this->records[$recordKey] = [
            'fingerprint' => $existing['fingerprint'] ?? '',
            'lease' => $claim->lease,
            'status' => 'completed',
            'status_code' => $response->status,
            'headers' => $response->headers,
            'body' => $response->body,
            'started_at' => $this->intValue($existing['started_at'] ?? $now->getTimestamp(), $now->getTimestamp()),
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds,
        ];

        $this->releaseLock($lockKey, $claim->lease);
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
        $lockKey = $recordKey.':lock';
        $existing = $this->records[$recordKey] ?? null;

        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on seal.');
        }

        $this->records[$recordKey] = [
            'fingerprint' => $existing['fingerprint'] ?? '',
            'lease' => $claim->lease,
            'status' => 'completed',
            'status_code' => null,
            'headers' => null,
            'body' => null,
            'started_at' => $this->intValue($existing['started_at'] ?? $now->getTimestamp(), $now->getTimestamp()),
            'expires_at' => $now->getTimestamp() + $this->ttlSeconds,
        ];

        $this->releaseLock($lockKey, $claim->lease);
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
        $lockKey = $recordKey.':lock';
        $existing = $this->records[$recordKey] ?? null;

        if (! is_array($existing) || ($existing['lease'] ?? '') !== $claim->lease) {
            return;
        }

        unset($this->records[$recordKey]);
        $this->releaseLock($lockKey, $claim->lease);
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

        if ((! array_key_exists('body', $existing) || $existing['body'] === null)
            && ($existing['status_code'] ?? null) === null) {
            return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key);
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

    /**
     * @param  array<string, mixed>  $existing
     */
    private function isExpired(array $existing, DateTimeImmutable $now): bool
    {
        $expiresAt = $this->intValue($existing['expires_at'] ?? 0, 0);

        return $expiresAt > 0 && $expiresAt < $now->getTimestamp();
    }

    private function isLockHeld(string $lockKey, DateTimeImmutable $now): bool
    {
        $lock = $this->locks[$lockKey] ?? null;
        if (! is_array($lock)) {
            return false;
        }

        return $lock['expires_at'] > $now->getTimestamp();
    }

    private function acquireLock(string $lockKey, string $lease, DateTimeImmutable $now): void
    {
        $this->locks[$lockKey] = [
            'lease' => $lease,
            'expires_at' => $now->getTimestamp() + $this->lockTtlSeconds,
        ];
    }

    private function releaseLock(string $lockKey, string $lease): void
    {
        $lock = $this->locks[$lockKey] ?? null;
        if (is_array($lock) && ($lock['lease'] ?? '') === $lease) {
            unset($this->locks[$lockKey]);
        }
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
