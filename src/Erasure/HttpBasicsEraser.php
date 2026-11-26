<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Erasure;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use Qiick\HttpBasics\Events\SubjectErasedEvent;
use Qiick\HttpBasics\Idempotency\SealedKeyMarker;
use RuntimeException;
use Throwable;

/**
 * @api Erases database-backed idempotency data using the fleet subject contract.
 *
 * The product revokes access before calling this eraser. Matching uses ownership
 * columns because an opaque bucket cannot safely identify its subject or tenant.
 */
final class HttpBasicsEraser
{
    public const string DELETE = 'delete';

    public const string ANONYMISE = 'anonymise';

    public function __construct(
        private readonly Dispatcher $events,
        private readonly Store $cache,
        private readonly string $cacheName,
        private readonly string $table,
        private readonly ?Connection $connection,
        private readonly ?Connection $cacheTransactionConnection = null,
    ) {}

    /**
     * A caller-owned transaction gets provisional seal counts. The post-commit event carries the final counts.
     *
     * @param  array<array-key, mixed>  $publicIds  principal and credential UUIDs from the product snapshot
     * @param  array<array-key, mixed>  $identifiers  free-text identifiers, validated but never searched across owners
     *
     * @throws InvalidArgumentException for malformed subject arguments
     * @throws OrganisationScopeUnsupportedException when the cache-only store cannot filter by organisation
     */
    public function erase(
        string $userId,
        string $userPublicId,
        string $mode,
        array $publicIds = [],
        array $identifiers = [],
        ?string $organisationId = null,
        bool $dryRun = false,
    ): ErasureReport {
        $credentialPublicIds = $this->validatedPublicIds($userPublicId, $mode, $publicIds, $identifiers);
        $connection = $this->connection;

        if ($connection === null) {
            if ($organisationId !== null) {
                throw new OrganisationScopeUnsupportedException('Organisation-scoped erasure requires database-backed idempotency.');
            }

            // One retained store, since individual entries cannot be counted by subject.
            $report = $this->report($mode, $dryRun, keptCache: 1);

            if (! $dryRun) {
                $callerConnection = $this->cacheTransactionConnection;

                if ($callerConnection !== null && $callerConnection->transactionLevel() > 0) {
                    $callerConnection->afterCommit(fn () => $this->dispatchErased($report));
                } else {
                    $this->dispatchErased($report);
                }
            }

            return $report;
        }

        $committedReport = null;

        try {
            $report = $connection->transaction(function () use ($connection, $userId, $credentialPublicIds, $organisationId, $mode, $dryRun, &$committedReport): ErasureReport {
                $subjectRows = $connection->table($this->table)
                    ->where(static fn (Builder $query): Builder => $query->where('user_id', $userId)->orWhereIn('credential_public_id', $credentialPublicIds));

                if ($organisationId !== null) {
                    $subjectRows->where('organisation_id', $organisationId);
                }

                if (! $dryRun) {
                    $subjectRows->lockForUpdate();
                }

                $changedRows = 0;
                $sealedKeys = [];
                $subjectRows->select(['id', 'bucket', 'key_hash'])->chunkById(250, function ($rows) use ($connection, $mode, $dryRun, &$changedRows, &$sealedKeys): void {
                    $recordIds = [];

                    foreach ($rows as $row) {
                        $recordIds[] = $row->id;

                        if (is_string($row->bucket) && is_string($row->key_hash)) {
                            $sealedKey = SealedKeyMarker::key($row->bucket, $row->key_hash);

                            if ($this->hasSeal($sealedKey)) {
                                $sealedKeys[] = $sealedKey;
                            }
                        }
                    }

                    if ($dryRun) {
                        $changedRows += count($recordIds);

                        return;
                    }

                    $records = $connection->table($this->table)->whereIn('id', $recordIds);
                    $changedRows += $mode === self::DELETE
                        ? $records->delete()
                        // A null bucket cannot be looked up by a retry and allows multiple detached rows.
                        : $records->update([
                            'public_id' => null,
                            'bucket' => null,
                            'user_id' => null,
                            'organisation_id' => null,
                            'credential_public_id' => null,
                            'key_hash' => '',
                            'path' => '',
                            'fingerprint' => '',
                            'lease' => '',
                            'status' => 'completed',
                            'status_code' => null,
                            'headers_json' => null,
                            'body' => null,
                            'request_id' => null,
                            'trace_id' => null,
                            'metadata' => null,
                        ]);
                });

                $report = $this->report($mode, $dryRun, $changedRows, count($sealedKeys));

                if (! $dryRun) {
                    $connection->afterCommit(function () use ($mode, $changedRows, $sealedKeys, &$committedReport): void {
                        $deletedSeals = 0;

                        foreach ($sealedKeys as $sealedKey) {
                            if ($this->canForgetSeal($sealedKey)) {
                                $deletedSeals++;
                            }
                        }

                        $committedReport = $this->report($mode, false, $changedRows, $deletedSeals, count($sealedKeys) - $deletedSeals);
                        $this->dispatchErased($committedReport);
                    });
                }

                return $report;
            });
        } catch (PDOException) {
            // Query exceptions embed the bindings. Never chain or report those values.
            throw new RuntimeException('Failed to erase database idempotency records.');
        }

        return $committedReport ?? $report;
    }

    /**
     * @param  array<array-key, mixed>  $publicIds
     * @param  array<array-key, mixed>  $identifiers
     * @return list<string>
     */
    private function validatedPublicIds(string $userPublicId, string $mode, array $publicIds, array $identifiers): array
    {
        if (! in_array($mode, [self::DELETE, self::ANONYMISE], true)) {
            throw new InvalidArgumentException('Invalid erasure mode.');
        }

        $credentialPublicIds = [];

        foreach ([$userPublicId, ...array_values($publicIds)] as $publicId) {
            if (! is_string($publicId) || ! Str::isUuid($publicId)) {
                throw new InvalidArgumentException('Erasure public identifiers must be UUIDs.');
            }

            $credentialPublicIds[] = strtolower($publicId);
        }

        foreach ($identifiers as $identifier) {
            if (! is_string($identifier) || trim($identifier) === '') {
                throw new InvalidArgumentException('Erasure identifiers must be non-empty strings.');
            }
        }

        return $credentialPublicIds;
    }

    private function hasSeal(string $sealedKey): bool
    {
        try {
            // Going through the repository would dispatch cache events carrying the key.
            return $this->cache->get($sealedKey) === true;
        } catch (Throwable) {
            throw new RuntimeException('Failed to inspect idempotency seals for erasure.');
        }
    }

    /** Attempts to delete the seal from the configured cache after the database commits. */
    private function canForgetSeal(string $sealedKey): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                if ($this->cache->forget($sealedKey) || ! $this->hasSeal($sealedKey)) {
                    return true;
                }
            } catch (Throwable) {
                // Retry a transient failure without exposing the key through the driver exception.
            }
        }

        // A persistently unavailable cache still expires seals by TTL. Keep processing the other keys.
        return false;
    }

    private function dispatchErased(ErasureReport $report): void
    {
        try {
            $this->events->dispatch(new SubjectErasedEvent($report));
        } catch (Throwable) {
            throw new RuntimeException('Failed to dispatch the committed idempotency erasure event.');
        }
    }

    private function report(string $mode, bool $dryRun, int $changedRows = 0, int $deletedSeals = 0, int $keptCache = 0): ErasureReport
    {
        return new ErasureReport('qiick/laravel-http-basics', $mode, $dryRun, [
            $this->table => [
                'deleted' => $mode === self::DELETE ? $changedRows : 0,
                'anonymised' => $mode === self::ANONYMISE ? $changedRows : 0,
                'kept' => 0,
                'reason' => null,
            ],
            'cache:'.$this->cacheName => [
                'deleted' => $deletedSeals,
                'anonymised' => 0,
                'kept' => $keptCache,
                'reason' => $keptCache > 0 ? 'cache_ttl' : null,
            ],
        ]);
    }
}
