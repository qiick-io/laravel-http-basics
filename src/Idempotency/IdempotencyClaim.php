<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * @api Outcome of a claim plus optional replay payload and opaque lease owner token.
 */
final readonly class IdempotencyClaim
{
    public function __construct(
        public ClaimOutcome $outcome,
        public ?ReplayableResponse $replay,
        /** Opaque owner token bound to the lock/marker; required for New claims. */
        public ?string $lease,
        public IdempotencyScope $scope,
        public string $key,
    ) {}
}
