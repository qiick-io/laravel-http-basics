<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional Eloquent model for the idempotency table products create themselves.
 *
 * The package does not publish migrations; configure the table name via
 * `http-basics.idempotency.database.table` (default `idempotency_records`).
 *
 * @property string|null $expires_at
 */
class IdempotencyRecord extends Model
{
    use MassPrunable;

    public $incrementing = false;

    public $timestamps = true;

    protected $primaryKey = null;

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
