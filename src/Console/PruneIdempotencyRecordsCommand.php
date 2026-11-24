<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Qiick\HttpBasics\Idempotency\IdempotencyRecord;

/**
 * @internal The `http-basics:prune-idempotency` command is the public part; the class is not.
 *
 * Deletes expired idempotency rows (`expires_at` in the past).
 *
 * Prefer scheduling `model:prune` on {@see IdempotencyRecord} when Eloquent is
 * available; this command is the package-namespaced entry point.
 */
final class PruneIdempotencyRecordsCommand extends Command
{
    protected $signature = 'http-basics:prune-idempotency
                            {--hours=0 : Only prune rows expired at least this many hours ago}
                            {--dry-run : Count rows that would be deleted without deleting}';

    protected $description = 'Prune expired Idempotency-Key database records';

    public function handle(): int
    {
        $record = new IdempotencyRecord;
        $hours = max(0, (int) $this->option('hours'));
        // expires_at is UTC wall-clock, so the cutoff must be too, whatever app.timezone is.
        $cutoff = now('UTC')->subHours($hours);

        // The model resolves the configured table and connection, the store's own.
        $query = DB::connection($record->getConnectionName())
            ->table($record->getTable())
            ->where('expires_at', '<=', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run') === true) {
            $this->info("Would delete {$count} expired idempotency record(s).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} expired idempotency record(s).");

        return self::SUCCESS;
    }
}
