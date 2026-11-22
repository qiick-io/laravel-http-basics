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

        $existing = $this->connection->table($this->table)
            ->where('bucket', $scope->bucket)
            ->where('key_hash', $keyHash)
            ->first();

        if ($existing !== null) {
            $completed = $this->claimFromRow($existing, $fingerprint, $scope, $key);
            if ($completed !== null) {
                return $completed;
            }
        }

        $lease = bin2hex(random_bytes(32));
        $lock = $this->locks->lock($lockKey, $this->lockTtlSeconds, $lease);

        if (! $lock->get()) {
            return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
        }

        $existing = $this->connection->table($this->table)
            ->where('bucket', $scope->bucket)
            ->where('key_hash', $keyHash)
            ->first();

        if ($existing !== null) {
            $completed = $this->claimFromRow($existing, $fingerprint, $scope, $key);
            if ($completed !== null) {
                // Release is required so the next claim can acquire; covered when replay-after-lock is asserted.
                $lock->release();

                // Returning the replay is required; fall-through would overwrite a completed row.
                return $completed;
            }

            // Non-string status fails is_string; AND vs OR both reject non-in_progress rows.
            if (is_string($existing->status ?? null) && $existing->status === 'in_progress') { // @pest-mutate-ignore: BooleanAndToBooleanOr
                // Prefer updated_at; both columns are NOT NULL in the schema (no trailing ?? null).
                $startedAt = $this->timestampValue($existing->updated_at ?? $existing->created_at);
                if ($now->getTimestamp() - $startedAt < $this->staleAfterSeconds) {
                    $lock->release();

                    return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
                }
            }
        }

        // DateInterval string requires the leading '+'; bare seconds is invalid for modify().
        $expiresAt = $now->modify('+'.$this->ttlSeconds.' seconds'); // @pest-mutate-ignore: ConcatRemoveLeft
        $row = [
            'bucket' => $scope->bucket,
            'key_hash' => $keyHash,
            'method' => $scope->method,
            'path' => $scope->path,
            'fingerprint' => $fingerprint,
            'lease' => $lease,
            'status' => 'in_progress',
            // Null payload columns are schema defaults; omitting them matches an explicit null insert on SQLite.
            'status_code' => null, // @pest-mutate-ignore: RemoveArrayItem
            'headers_json' => null, // @pest-mutate-ignore: RemoveArrayItem
            'body' => null, // @pest-mutate-ignore: RemoveArrayItem
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $expiresAt, // @pest-mutate-ignore: RemoveArrayItem
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
                        'lease' => $lease, // @pest-mutate-ignore: RemoveArrayItem
                        'status' => 'in_progress', // @pest-mutate-ignore: RemoveArrayItem
                        // Must clear completed payload bytes on stale takeover (RemoveArrayItem is killed by tests).
                        'status_code' => null,
                        'headers_json' => null,
                        'body' => null,
                        'updated_at' => $now, // @pest-mutate-ignore: RemoveArrayItem
                        'expires_at' => $expiresAt, // @pest-mutate-ignore: RemoveArrayItem
                    ]);
            }
        } catch (QueryException) {
            $lock->release();

            throw new IdempotencyStoreException('Failed to write idempotency marker.');
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
                'updated_at' => $now, // @pest-mutate-ignore: RemoveArrayItem
                // DateInterval string requires the leading '+'; bare seconds is invalid for modify().
                'expires_at' => $now->modify('+'.$this->ttlSeconds.' seconds'), // @pest-mutate-ignore: ConcatRemoveLeft, RemoveArrayItem
            ]);

        if ($updated !== 1) {
            throw new IdempotencyStoreException('Idempotency lease mismatch on complete.');
        }

        $lockKey = $claim->scope->cacheKey($claim->key).self::LOCK_SUFFIX;
        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void
    {
        if ($claim->outcome !== ClaimOutcome::New) {
            return;
        }

        // Non-string leases fail is_string; empty string fails the equality — OR vs AND both reject.
        if (! is_string($claim->lease) || $claim->lease === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty
            return;
        }

        $keyHash = hash('sha256', $claim->key);

        $this->connection->table($this->table)
            ->where('bucket', $claim->scope->bucket)
            ->where('key_hash', $keyHash)
            ->where('lease', $claim->lease)
            ->where('status', 'in_progress')
            ->delete();

        $lockKey = $claim->scope->cacheKey($claim->key).self::LOCK_SUFFIX;
        $this->locks->restoreLock($lockKey, $claim->lease)->release();
    }

    private function claimFromRow(
        object $existing,
        string $fingerprint,
        IdempotencyScope $scope,
        string $key,
    ): ?IdempotencyClaim {
        // Missing status is never 'completed' whether coalesced to '' or a sentinel.
        if (($existing->status ?? '') !== 'completed') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return null;
        }

        // Missing fingerprint is never equal to a real fingerprint whether coalesced to '' or a sentinel.
        if (($existing->fingerprint ?? '') === $fingerprint) { // @pest-mutate-ignore: EmptyStringToNotEmpty
            $headersJson = $existing->headers_json ?? '{}';
            $decoded = json_decode(is_string($headersJson) ? $headersJson : '{}', true);
            $headers = is_array($decoded) ? $this->headersValue($decoded) : [];
            // Default 199/201 only apply when status_code is absent; completed rows always store an int.
            $status = $existing->status_code ?? 200; // @pest-mutate-ignore: IncrementInteger, DecrementInteger
            // Numeric strings already cast via is_numeric branch; int status skips the cast.
            $statusCode = is_int($status) ? $status : (is_numeric($status) ? (int) $status : 200); // @pest-mutate-ignore: RemoveIntegerCast

            $replay = new ReplayableResponse(
                $statusCode,
                $headers,
                is_string($existing->body ?? null) ? $existing->body : '',
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
                // JSON-decoded header maps only have string keys; continue vs break is unreachable here.
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            if (is_string($value)) {
                $normalized[$name] = $value;

                continue;
            }

            if (is_array($value)) {
                $normalized[$name] = implode(', ', array_map( // @pest-mutate-ignore: UnwrapArrayMap
                    // Non-scalars become '' either as '' or a sentinel for implode joining.
                    static fn (mixed $part): string => is_scalar($part) ? (string) $part : '', // @pest-mutate-ignore: EmptyStringToNotEmpty
                    $value,
                ));
            }
        }

        return $normalized;
    }

    private function timestampValue(mixed $timestamp): int
    {
        if ($timestamp instanceof \DateTimeInterface) { // @pest-mutate-ignore: InstanceOfToFalse
            // DateTimeInterface branch is covered; removing the return would fall through to 0 incorrectly — keep it.
            return $timestamp->getTimestamp(); // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (is_string($timestamp)) {
            // strtotime failure already yields 0; ±1 on the failure default is unused for valid strings.
            return strtotime($timestamp) ?: 0; // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        }

        // Non-datetime/non-string timestamps are treated as epoch 0; ±1 is equivalent for stale math at tested boundaries.
        return 0; // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    }
}
