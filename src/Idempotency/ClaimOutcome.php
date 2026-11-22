<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * @api Result of an idempotency claim attempt.
 */
enum ClaimOutcome: string
{
    case New = 'new';
    case Replay = 'replay';
    case Conflict = 'conflict';
    case Mismatch = 'mismatch';
    case NotReplayable = 'not_replayable';
}
