<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional Eloquent model for the published idempotency migration.
 *
 * Publish with `--tag=http-basics-migrations`. Configure the table name via
 * `http-basics.idempotency.database.table` (default `idempotency_records`).
 *
 * @property string|null $expires_at
 */
class IdempotencyRecord extends Model
{
    use MassPrunable;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('http-basics.idempotency.database.table', 'idempotency_records');

        return is_string($table) && $table !== '' ? $table : 'idempotency_records';
    }

    /**
     * Follows the store's connection so `model:prune` deletes where the rows live.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('http-basics.idempotency.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        // expires_at is UTC wall-clock, so the cutoff must be too, whatever app.timezone is.
        return static::query()->where('expires_at', '<=', now('UTC'));
    }
}
