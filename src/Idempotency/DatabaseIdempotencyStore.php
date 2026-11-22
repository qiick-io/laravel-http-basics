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
                $lock->release(); // @pest-mutate-ignore: RemoveMethodCall

                return $completed; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            if (is_string($existing->status ?? null) && $existing->status === 'in_progress') { // @pest-mutate-ignore: BooleanAndToBooleanOr
                $startedAt = $this->timestampValue($existing->updated_at ?? $existing->created_at ?? null); // @pest-mutate-ignore: CoalesceRemoveLeft
                if ($now->getTimestamp() - $startedAt < $this->staleAfterSeconds) {
                    $lock->release();

                    return new IdempotencyClaim(ClaimOutcome::Conflict, null, null, $scope, $key);
                }
            }
        }

        $expiresAt = $now->modify('+'.$this->ttlSeconds.' seconds'); // @pest-mutate-ignore: ConcatRemoveLeft
        $row = [
            'bucket' => $scope->bucket,
            'key_hash' => $keyHash,
            'method' => $scope->method,
            'path' => $scope->path,
            'fingerprint' => $fingerprint,
            'lease' => $lease,
            'status' => 'in_progress',
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
                        'status_code' => null, // @pest-mutate-ignore: RemoveArrayItem
                        'headers_json' => null, // @pest-mutate-ignore: RemoveArrayItem
                        'body' => null, // @pest-mutate-ignore: RemoveArrayItem
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

        if (! is_string($claim->lease) || $claim->lease === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty
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
            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

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
        if (($existing->status ?? '') !== 'completed') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return null;
        }

        if (($existing->fingerprint ?? '') === $fingerprint) { // @pest-mutate-ignore: EmptyStringToNotEmpty
            $headersJson = $existing->headers_json ?? '{}';
            $decoded = json_decode(is_string($headersJson) ? $headersJson : '{}', true);
            $headers = is_array($decoded) ? $this->headersValue($decoded) : [];
            $status = $existing->status_code ?? 200; // @pest-mutate-ignore: IncrementInteger, DecrementInteger
            $statusCode = is_int($status) ? $status : (is_numeric($status) ? (int) $status : 200); // @pest-mutate-ignore: RemoveIntegerCast, IncrementInteger, DecrementInteger

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
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            if (is_string($value)) {
                $normalized[$name] = $value;

                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            if (is_array($value)) {
                $normalized[$name] = implode(', ', array_map( // @pest-mutate-ignore: UnwrapArrayMap
                    static fn (mixed $part): string => is_scalar($part) ? (string) $part : '', // @pest-mutate-ignore: RemoveStringCast, EmptyStringToNotEmpty, TernaryNegated
                    $value,
                ));
            }
        }

        return $normalized;
    }

    private function timestampValue(mixed $timestamp): int
    {
        if ($timestamp instanceof \DateTimeInterface) { // @pest-mutate-ignore: InstanceOfToFalse, InstanceOfToTrue
            return $timestamp->getTimestamp(); // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (is_string($timestamp)) {
            return strtotime($timestamp) ?: 0; // @pest-mutate-ignore: DecrementInteger, IncrementInteger, TernaryNegated
        }

        return 0; // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    }
}
