<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Qiick\HttpBasics\Config\PackageConfig;

/**
 * @api Optional Eloquent model for the published idempotency migration.
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
        return $this->settings()->string('idempotency.database.table');
    }

    /**
     * Follows the store's connection so `model:prune` deletes where the rows live,
     * unless the caller picked one with `on()` or `setConnection()`.
     */
    public function getConnectionName(): ?string
    {
        if (is_string($this->connection)) {
            return $this->connection;
        }

        return $this->settings()->optionalString('idempotency.database.connection');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        // expires_at is UTC wall-clock, so the cutoff must be too, whatever app.timezone is.
        return static::query()->where('expires_at', '<=', now('UTC'));
    }

    /**
     * The same reads as the store's, so the store and `model:prune` never disagree.
     */
    private function settings(): PackageConfig
    {
        return new PackageConfig(app(ConfigRepository::class));
    }
}
