<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Contracts;

use DateTimeImmutable;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

/**
 * @api Persists idempotency markers, leases and completed replay payloads.
 */
interface IdempotencyStore
{
    public function claim(
        IdempotencyScope $scope,
        string $key,
        string $fingerprint,
        DateTimeImmutable $now,
    ): IdempotencyClaim;

    /**
     * Writes the completed record only when the claim lease still matches.
     *
     * @throws IdempotencyStoreException When the
     *                                   lease is missing, mismatched, or persistence fails (fail closed).
     */
    public function complete(
        IdempotencyClaim $claim,
        ReplayableResponse $response,
        DateTimeImmutable $now,
    ): void;

    /**
     * Drops an in-progress marker when the lease matches (handler failure / abandon).
     */
    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void;
}
