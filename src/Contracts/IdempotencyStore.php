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
     * @throws IdempotencyStoreException When the lease is missing, mismatched, or
     *                                   persistence fails after the handler (fail closed:
     *                                   the marker is sealed unreplayable so retries never
     *                                   re-execute the handler).
     */
    public function complete(
        IdempotencyClaim $claim,
        ReplayableResponse $response,
        DateTimeImmutable $now,
    ): void;

    /**
     * Marks a New claim completed without a replay body (oversized response, handler
     * deadline exceeded, or persistence failure). Retries receive NotReplayable.
     *
     * @throws IdempotencyStoreException When the lease is missing or mismatched.
     */
    public function sealUnreplayable(IdempotencyClaim $claim, DateTimeImmutable $now): void;

    /**
     * Drops an in-progress marker when the lease matches (handler failure / abandon).
     */
    public function abandon(IdempotencyClaim $claim, DateTimeImmutable $now): void;
}
