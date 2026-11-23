<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use DateTimeImmutable;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Qiick\HttpBasics\Contracts\IdempotencyStore;

/**
 * @api Database-backed idempotency store; cache is used only for cross-request locks.
 *
 * Fail-closed on complete: a QueryException after the handler seals the row as
 * completed-without-body so a retry never re-runs the handler (NotReplayable).
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    private const string LOCK_SUFFIX = ':lock';

    private LockProvider $locks;

    public function __construct(
        private ConnectionInterface $connection,
        private CacheRepository $cache,
        private string $table,
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
        $keyHash = hash('sha256', $key);
        $lockKey = $scope->cacheKey($key).self::LOCK_SUFFIX;

        try {
            $existing = $this->findRow($scope->bucket, $keyHash);
        } catch (QueryException $exception) {
            throw new IdempotencyStoreException('Failed to read idempotency marker.', previous: $exception);
        }

        if ($existing !== null) {
            $existingAttributes = get_object_vars($existing);

            // Missing status is not completed.
            if (($existingAttributes['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) {
                // Expired completed keys may be claimed again (overwrite after lock).
            } else {
                $completed = $this->claimFromRow($existing, $fingerprint, $scope, $key);

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

        try {
            $existing = $this->findRow($scope->bucket, $keyHash);
        } catch (QueryException $exception) {
            // Request fails closed either way; lock_ttl recovers an unreleased lease.
            $lock->release();

            throw new IdempotencyStoreException('Failed to read idempotency marker.', previous: $exception);
        }

        if ($existing !== null) {
            $existingAttributes = get_object_vars($existing);

            // Missing status is not completed.
            if (($existingAttributes['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) {
                // Fall through to overwrite the expired completed row.
            } else {
                $completed = $this->claimFromRow($existing, $fingerprint, $scope, $key);

                if ($completed !== null) {
                    $lock->release();

                    return $completed;
                }

                // Non-string status is never in_progress.
                if (is_string($existingAttributes['status'] ?? null) && $existingAttributes['status'] === 'in_progress') {
                    // updated_at wins; created_at is the documented fallback.
                    $startedAt = $this->timestampValue(
                        $existingAttributes['updated_at'] ?? $existingAttributes['created_at'] ?? null,
                    );

                    if ($now->getTimestamp() - $startedAt < $this->staleAfterSeconds) {
                        $lock->release();

                        return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
                    }
                }
            }
        }

        $utcNow = $this->utc($now);
        // Leading '+' is required by DateTimeImmutable::modify.
        $expiresAt = $utcNow->modify('+'.$this->ttlSeconds.' seconds');
        $row = [
            'bucket' => $scope->bucket,
            'key_hash' => $keyHash,
            'method' => $scope->method,
            'path' => $scope->path,
            'fingerprint' => $fingerprint,
            'lease' => $lease,
            'status' => 'in_progress',
            // Explicit nulls match nullable column defaults on insert.
            'status_code' => null,
            'headers_json' => null,
            'body' => null,
            // Omitting these leaves null; stale math already coalesces to created_at.
            'created_at' => $utcNow,
            'updated_at' => $utcNow,
            'expires_at' => $expiresAt,
        ];

        try {
            if ($existing === null) {
                $this->connection->table($this->table)->insert($row);
            } else {
                $this->connection->table($this->table)
                    ->where('bucket', $scope->bucket)
                    ->where('key_hash', $keyHash)
                    ->update([
                        'fingerprint' => $fingerprint,
                        'lease' => $lease,
                        'status' => 'in_progress',
                        'status_code' => null,
                        'headers_json' => null,
                        'body' => null,
                        'updated_at' => $utcNow,
                        'expires_at' => $expiresAt,
                    ]);
            }
        } catch (QueryException $exception) {
            $lock->release();

            throw new IdempotencyStoreException('Failed to write idempotency marker.', previous: $exception);
        }

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

        $keyHash = hash('sha256', $claim->key);
        $utcNow = $this->utc($now);

        try {
            $updated = $this->connection->table($this->table)
                ->where('bucket', $claim->scope->bucket)
                ->where('key_hash', $keyHash)
                ->where('lease', $claim->lease)
                ->where('status', 'in_progress')
                ->update([
                    'status' => 'completed',
                    'status_code' => $response->status,
                    'headers_json' => json_encode($response->headers, JSON_THROW_ON_ERROR),
                    'body' => $response->body,
                    'updated_at' => $utcNow,
                    // Leading '+' is required by DateTimeImmutable::modify.
                    'expires_at' => $utcNow->modify('+'.$this->ttlSeconds.' seconds'),
                ]);
        } catch (QueryException $exception) {
            $this->sealUnreplayable($claim, $now);

            throw new IdempotencyStoreException(
                'Failed to persist idempotent response; sealed unreplayable.',
                previous: $exception,
            );
        }

        if ($updated !== 1) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on complete.');
        }

        $lockKey = $claim->scope->cacheKey($claim->key).self::LOCK_SUFFIX;
        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New) {
            throw new IdempotencyStoreException('Cannot seal a claim that is not new.');
        }

        // Empty lease is rejected the same as a non-string lease.
        if (! is_string($claim->lease) || $claim->lease === '') {
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        $keyHash = hash('sha256', $claim->key);
        $utcNow = $this->utc($now);

        try {
            $updated = $this->connection->table($this->table)
                ->where('bucket', $claim->scope->bucket)
                ->where('key_hash', $keyHash)
                ->where('lease', $claim->lease)
                ->where('status', 'in_progress')
                ->update([
                    'status' => 'completed',
                    // Sealed rows clear payload columns; defaults are already null.
                    'status_code' => null,
                    'headers_json' => null,
                    'body' => null,
                    'updated_at' => $utcNow,
                    // Leading '+' is required by DateTimeImmutable::modify.
                    'expires_at' => $utcNow->modify('+'.$this->ttlSeconds.' seconds'),
                ]);
        } catch (QueryException $exception) {
            throw new IdempotencyStoreException('Failed to seal unreplayable marker.', previous: $exception);
        }

        // Exactly one row must match the lease; 0 and >1 both fail closed.
        if ($updated !== 1) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on seal.');
        }

        // Lock key suffix is required to match claim().
        $lockKey = $claim->scope->cacheKey($claim->key).self::LOCK_SUFFIX;
        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New) {
            return;
        }

        // Empty lease is a no-op abandon either way.
        if (! is_string($claim->lease) || $claim->lease === '') {
            return;
        }

        $keyHash = hash('sha256', $claim->key);

        try {
            $this->connection->table($this->table)
                ->where('bucket', $claim->scope->bucket)
                ->where('key_hash', $keyHash)
                ->where('lease', $claim->lease)
                ->where('status', 'in_progress')
                ->delete();
        } catch (QueryException) {
            // Best-effort clear; caller is already failing.
        }

        $lockKey = $claim->scope->cacheKey($claim->key).self::LOCK_SUFFIX;
        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    /**
     * Deletes expired rows. Used by the prune command and MassPrunable model.
     */
    public function pruneExpired(DateTimeImmutable $now): int
    {
        try {
            return $this->connection->table($this->table)
                ->where('expires_at', '<=', $this->utc($now))
                ->delete();
        } catch (QueryException $exception) {
            throw new IdempotencyStoreException('Failed to prune expired idempotency rows.', previous: $exception);
        }
    }

    private function utc(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }

    private function findRow(string $bucket, string $keyHash): ?object
    {
        $row = $this->connection->table($this->table)
            ->where('bucket', $bucket)
            ->where('key_hash', $keyHash)
            ->first();

        // Query builders return stdClass; other objects are treated the same.
        return $row instanceof \stdClass || is_object($row) ? $row : null;
    }

    private function isExpired(object $existing, DateTimeImmutable $now): bool
    {
        $attributes = get_object_vars($existing);

        if (! array_key_exists('expires_at', $attributes) || $attributes['expires_at'] === null) {
            return false;
        }

        return $this->timestampValue($attributes['expires_at']) < $now->getTimestamp();
    }

    private function claimFromRow(
        object $existing,
        string $fingerprint,
        IdempotencyScope $scope,
        string $key,
    ): ?IdempotencyClaim {
        $attributes = get_object_vars($existing);

        // Missing status is not completed.
        if (($attributes['status'] ?? '') !== 'completed') {
            return null;
        }

        // Completed without a stored body means the handler ran but the response
        // could not be persisted — never re-execute (issue #123).
        // Absent keys are null either with or without coalesce.
        if (($attributes['body'] ?? null) === null && ($attributes['status_code'] ?? null) === null) {
            return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key);
        }

        // Fingerprints are hex strings; rtrim is a no-op on full digests, empty never matches.
        $rawFingerprint = $attributes['fingerprint'] ?? '';
        $storedFingerprint = rtrim(is_string($rawFingerprint) ? $rawFingerprint : '');

        if ($storedFingerprint === $fingerprint) {
            $headersJson = $attributes['headers_json'] ?? '{}';
            $decoded = json_decode(is_string($headersJson) ? $headersJson : '{}', true);
            $headers = is_array($decoded) ? $this->headersValue($decoded) : [];
            // Default 200 matches HTTP OK when status_code was never stored.
            $status = $attributes['status_code'] ?? 200;
            $statusCode = is_int($status) ? $status : (is_numeric($status) ? (int) $status : 200);
            $body = $attributes['body'] ?? null;

            $replay = new ReplayableResponse(
                $statusCode,
                $headers,
                is_string($body) ? $body : '',
            );

            return new IdempotencyClaim(ClaimOutcome::Replay, $replay, null, $scope, $key);
        }

        return new IdempotencyClaim(ClaimOutcome::Mismatch, null, null, $scope, $key);
    }

    /**
     * @param  array<mixed, mixed>  $headers
     * @return array<string, string>
     */
    private function headersValue(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name)) {
                // Breaking would drop later string keys.
                continue;
            }

            if (is_string($value)) {
                $normalized[$name] = $value;

                continue;
            }

            if (is_array($value)) {
                // implode already stringifies scalars; map cast is redundant for strings.
                $normalized[$name] = implode(', ', array_map(
                    static fn (mixed $part): string => is_scalar($part) ? (string) $part : '',
                    $value,
                ));
            }
        }

        return $normalized;
    }

    private function timestampValue(mixed $timestamp): int
    {
        if ($timestamp instanceof \DateTimeInterface) {
            return $timestamp->getTimestamp();
        }

        if (is_string($timestamp)) {
            // Columns hold UTC wall-clock; strtotime() would read them in app.timezone.
            try {
                return (new DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->getTimestamp();
            } catch (\Exception) {
                return 0;
            }
        }

        // Non-string/non-datetime inputs are treated as epoch 0.
        return 0;
    }
}
