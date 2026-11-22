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
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
