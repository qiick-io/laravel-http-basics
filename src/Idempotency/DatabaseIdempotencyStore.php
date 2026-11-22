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
            $lock->release();

            throw new IdempotencyStoreException('Failed to read idempotency marker.', previous: $exception);
        }

        if ($existing !== null) {
            $existingAttributes = get_object_vars($existing);

            if (($existingAttributes['status'] ?? '') === 'completed' && $this->isExpired($existing, $now)) {
                // Fall through to overwrite the expired completed row.
            } else {
                $completed = $this->claimFromRow($existing, $fingerprint, $scope, $key);

                if ($completed !== null) {
                    $lock->release();

                    return $completed;
                }

                if (is_string($existingAttributes['status'] ?? null) && $existingAttributes['status'] === 'in_progress') {
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

        $expiresAt = $now->modify('+'.$this->ttlSeconds.' seconds');
        $row = [
            'bucket' => $scope->bucket,
            'key_hash' => $keyHash,
            'method' => $scope->method,
            'path' => $scope->path,
            'fingerprint' => $fingerprint,
            'lease' => $lease,
            'status' => 'in_progress',
            'status_code' => null,
            'headers_json' => null,
            'body' => null,
            'created_at' => $now,
            'updated_at' => $now,
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
                        'updated_at' => $now,
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
                    'updated_at' => $now,
                    'expires_at' => $now->modify('+'.$this->ttlSeconds.' seconds'),
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

        if (! is_string($claim->lease) || $claim->lease === '') {
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        $keyHash = hash('sha256', $claim->key);

        try {
            $updated = $this->connection->table($this->table)
                ->where('bucket', $claim->scope->bucket)
                ->where('key_hash', $keyHash)
                ->where('lease', $claim->lease)
                ->where('status', 'in_progress')
                ->update([
                    'status' => 'completed',
                    'status_code' => null,
                    'headers_json' => null,
                    'body' => null,
                    'updated_at' => $now,
                    'expires_at' => $now->modify('+'.$this->ttlSeconds.' seconds'),
                ]);
        } catch (QueryException $exception) {
            throw new IdempotencyStoreException('Failed to seal unreplayable marker.', previous: $exception);
        }

        if ($updated !== 1) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on seal.');
        }

        $lockKey = $claim->scope->cacheKey($claim->key).self::LOCK_SUFFIX;
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
                ->where('expires_at', '<=', $now)
                ->delete();
        } catch (QueryException $exception) {
            throw new IdempotencyStoreException('Failed to prune expired idempotency rows.', previous: $exception);
        }
    }

    private function findRow(string $bucket, string $keyHash): ?object
    {
        $row = $this->connection->table($this->table)
            ->where('bucket', $bucket)
            ->where('key_hash', $keyHash)
            ->first();

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

        if (($attributes['status'] ?? '') !== 'completed') {
            return null;
        }

        // Completed without a stored body means the handler ran but the response
        // could not be persisted — never re-execute (issue #123).
        if (($attributes['body'] ?? null) === null && ($attributes['status_code'] ?? null) === null) {
            return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key);
        }

        if (($attributes['fingerprint'] ?? '') === $fingerprint) {
            $headersJson = $attributes['headers_json'] ?? '{}';
            $decoded = json_decode(is_string($headersJson) ? $headersJson : '{}', true);
            $headers = is_array($decoded) ? $this->headersValue($decoded) : [];
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

    private function timestampValue(mixed $timestamp): int
    {
        if ($timestamp instanceof \DateTimeInterface) {
            return $timestamp->getTimestamp();
        }

        if (is_string($timestamp)) {
            return strtotime($timestamp) ?: 0;
        }

        return 0;
    }
}
