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

    private SealedKeyMarker $sealedKeys;

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

        $keyHash = hash('sha256', $key);

        // A live completed row answers before contending for the lock.
        $existing = $this->findRow($scope->bucket, $keyHash);
        $settled = $this->settledClaim($existing, $fingerprint, $scope, $key, $now);

        if ($settled !== null) {
            return $settled;
        }

        $lease = bin2hex(random_bytes(32));

        if (! $this->acquireLock($scope, $key, $lease)) {
            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        try {
            // Re-read under the lock: another request may have sealed or finished meanwhile.
            $sealed = $this->sealedKeys->isMarked($scope, $key);
            $existing = $this->findRow($scope->bucket, $keyHash);
        } catch (IdempotencyStoreException $exception) {
            // Request fails closed either way; releasing spares retries the lock_ttl wait.
            $this->releaseLock($scope, $key, $lease);

            throw $exception;
        }

        $settled = $sealed
            ? new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key)
            : $this->settledClaim($existing, $fingerprint, $scope, $key, $now);

        if ($settled !== null) {
            $this->releaseLock($scope, $key, $lease);

            return $settled;
        }

        if ($existing !== null && $this->isInFlight($existing, $now)) {
            $this->releaseLock($scope, $key, $lease);

            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        $utcNow = $this->utc($now);
        $claimedColumns = [
            'fingerprint' => $fingerprint,
            'lease' => $lease,
            'status' => 'in_progress',
            'updated_at' => $utcNow,
            'expires_at' => $this->expiresAt($utcNow),
        ];

        try {
            if ($existing === null) {
                $this->connection->table($this->table)->insert([
                    'bucket' => $scope->bucket,
                    'key_hash' => $keyHash,
                    'method' => $scope->method,
                    'path' => $scope->path,
                    'created_at' => $utcNow,
                ] + $claimedColumns);
            } else {
                // Taking over an expired or stale row must drop its old payload.
                $this->connection->table($this->table)
                    ->where('bucket', $scope->bucket)
                    ->where('key_hash', $keyHash)
                    ->update($claimedColumns + [
                        'status_code' => null,
                        'headers_json' => null,
                        'body' => null,
                    ]);
            }
        } catch (QueryException $exception) {
            $this->releaseLock($scope, $key, $lease);

            throw new IdempotencyStoreException('Failed to write idempotency marker.', previous: $exception);
        }

        return new IdempotencyClaim(ClaimOutcome::New, null, $lease, $scope, $key);
    }

    public function complete(
        IdempotencyClaim $claim,
        ReplayableResponse $response,
        DateTimeImmutable $now,
    ): void {
        $lease = $this->leaseToFinish($claim, 'Cannot complete a claim that is not new.');

        try {
            $updated = $this->finishInProgressRow($claim, $lease, $now, [
                'status_code' => $response->status,
                'headers_json' => json_encode($response->headers, JSON_THROW_ON_ERROR),
                'body' => $response->body,
            ]);
        } catch (QueryException $exception) {
            try {
                $this->sealUnreplayable($claim, $now);
            } catch (IdempotencyStoreException $sealFailure) {
                throw new IdempotencyStoreException('Failed to persist idempotent response and to seal it unreplayable.', previous: $sealFailure);
            }

            throw new IdempotencyStoreException(
                'Failed to persist idempotent response; sealed unreplayable.',
                previous: $exception,
            );
        }

        if ($updated !== 1) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on complete.');
        }

        $this->releaseLock($claim->scope, $claim->key, $lease);
    }

    /**
     * Marks the row completed with a null status_code, the "handler ran, response
     * not stored" marker that {@see settledClaim()} answers with NotReplayable.
     */
    public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        $lease = $this->leaseToFinish($claim, 'Cannot seal a claim that is not new.');

        try {
            $updated = $this->finishInProgressRow($claim, $lease, $now, [
                'status_code' => null,
                'headers_json' => null,
                'body' => null,
            ]);
        } catch (QueryException $exception) {
            // The row stays in progress; the cache marker keeps a stale takeover from re-running.
            if (! $this->sealedKeys->mark($claim->scope, $claim->key)) {
                throw new IdempotencyStoreException('Failed to seal unreplayable marker.', previous: $exception);
            }

            $this->releaseLock($claim->scope, $claim->key, $lease);

            return;
        }

        // Exactly one row must match the lease; 0 and >1 both fail closed.
        if ($updated !== 1) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on seal.');
        }

        $this->releaseLock($claim->scope, $claim->key, $lease);
    }

    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        // Nothing of ours to clear: skip the database round trip entirely.
        if ($claim->outcome !== ClaimOutcome::New || ! is_string($claim->lease) || $claim->lease === '') {
            return;
        }

        try {
            $this->connection->table($this->table)
                ->where('bucket', $claim->scope->bucket)
                ->where('key_hash', hash('sha256', $claim->key))
                ->where('lease', $claim->lease)
                ->where('status', 'in_progress')
                ->delete();
        } catch (QueryException) {
            // Best-effort clear; caller is already failing.
        }

        $this->releaseLock($claim->scope, $claim->key, $claim->lease);
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

    private function expiresAt(DateTimeImmutable $utcNow): DateTimeImmutable
    {
        return $utcNow->setTimestamp($utcNow->getTimestamp() + $this->ttlSeconds);
    }

    private function lockKey(IdempotencyScope $scope, string $key): string
    {
        return $scope->cacheKey($key).self::LOCK_SUFFIX;
    }

    /**
     * @return bool false when another request holds the lock
     *
     * @throws IdempotencyStoreException when the lock backend fails
     */
    private function acquireLock(IdempotencyScope $scope, string $key, string $owner): bool
    {
        try {
            return $this->locks->lock($this->lockKey($scope, $key), $this->lockTtlSeconds, $owner)->get() === true;
        } catch (\Throwable $exception) {
            throw new IdempotencyStoreException('Failed to acquire idempotency lock.', previous: $exception);
        }
    }

    /**
     * Best effort: an unreleased lock runs out after lock_ttl_seconds, and failing to
     * release it must not turn finished work into an error.
     */
    private function releaseLock(IdempotencyScope $scope, string $key, string $owner): void
    {
        try {
            $this->locks->restoreLock($this->lockKey($scope, $key), $owner)->release();
        } catch (\Throwable) {
            // The lock expires on its own.
        }
    }

    /**
     * @return array<mixed>|null
     *
     * @throws IdempotencyStoreException when the read fails
     */
    private function findRow(string $bucket, string $keyHash): ?array
    {
        try {
            $row = $this->connection->table($this->table)
                ->where('bucket', $bucket)
                ->where('key_hash', $keyHash)
                ->first();
        } catch (QueryException $exception) {
            throw new IdempotencyStoreException('Failed to read idempotency marker.', previous: $exception);
        }

        return is_object($row) ? get_object_vars($row) : null;
    }

    /**
     * @throws IdempotencyStoreException when the claim does not own a lease
     */
    private function leaseToFinish(IdempotencyClaim $claim, string $notNewMessage): string
    {
        if ($claim->outcome !== ClaimOutcome::New) {
            throw new IdempotencyStoreException($notNewMessage);
        }

        if (! is_string($claim->lease) || $claim->lease === '') {
            throw new IdempotencyStoreException('Missing lease on idempotency claim.');
        }

        return $claim->lease;
    }

    /**
     * Completes the row only while it is still in progress under this lease, so a
     * request whose stale row was taken over can never overwrite the new owner.
     *
     * @param  array{status_code: int|null, headers_json: string|null, body: string|null}  $payloadColumns
     * @return int rows updated
     */
    private function finishInProgressRow(
        IdempotencyClaim $claim,
        string $lease,
        DateTimeImmutable $now,
        array $payloadColumns,
    ): int {
        $utcNow = $this->utc($now);

        return $this->connection->table($this->table)
            ->where('bucket', $claim->scope->bucket)
            ->where('key_hash', hash('sha256', $claim->key))
            ->where('lease', $lease)
            ->where('status', 'in_progress')
            ->update([
                'status' => 'completed',
                'updated_at' => $utcNow,
                'expires_at' => $this->expiresAt($utcNow),
            ] + $payloadColumns);
    }

    /**
     * @param  array<mixed>  $row
     */
    private function isExpired(array $row, DateTimeImmutable $now): bool
    {
        $expiresAt = $this->timestampValue($row['expires_at'] ?? null);

        return $expiresAt !== null && $expiresAt < $now->getTimestamp();
    }

    /**
     * An in-progress row blocks new claims until it goes stale (its owner died).
     *
     * @param  array<mixed>  $row
     */
    private function isInFlight(array $row, DateTimeImmutable $now): bool
    {
        if (($row['status'] ?? null) !== 'in_progress') {
            return false;
        }

        // updated_at wins; created_at is the documented fallback. Unreadable means stale.
        $startedAt = $this->timestampValue($row['updated_at'] ?? $row['created_at'] ?? null);

        return $startedAt !== null && $now->getTimestamp() - $startedAt < $this->staleAfterSeconds;
    }

    /**
     * The final answer a live completed row gives, or null when the key may be
     * claimed (no row, a row still in progress, or an expired completed row).
     *
     * @param  array<mixed>|null  $row
     */
    private function settledClaim(
        ?array $row,
        string $fingerprint,
        IdempotencyScope $scope,
        string $key,
        DateTimeImmutable $now,
    ): ?IdempotencyClaim {
        if ($row === null || ($row['status'] ?? null) !== 'completed' || $this->isExpired($row, $now)) {
            return null;
        }

        $status = $row['status_code'] ?? null;

        // A completed row without a status code means the handler ran but the response
        // could not be stored (sealed): never re-execute (issue #123).
        if (! is_numeric($status)) {
            return new IdempotencyClaim(ClaimOutcome::NotReplayable, null, null, $scope, $key);
        }

        if (($row['fingerprint'] ?? null) !== $fingerprint) {
            return new IdempotencyClaim(ClaimOutcome::Mismatch, null, null, $scope, $key);
        }

        $headersJson = $row['headers_json'] ?? null;
        $decodedHeaders = is_string($headersJson) ? json_decode($headersJson, true) : null;
        $body = $row['body'] ?? null;

        $replay = new ReplayableResponse(
            (int) $status,
            is_array($decodedHeaders) ? $this->headersValue($decodedHeaders) : [],
            is_string($body) ? $body : '',
        );

        return new IdempotencyClaim(ClaimOutcome::Replay, $replay, null, $scope, $key);
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

    /**
     * Columns hold UTC wall-clock; strtotime() would read them in app.timezone.
     */
    private function timestampValue(mixed $timestamp): ?int
    {
        if (! is_string($timestamp)) {
            return null;
        }

        try {
            return (new DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
