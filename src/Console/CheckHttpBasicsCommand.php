<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Console;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\HttpBasics\Config\PackageConfig;
use Qiick\HttpBasics\Config\PackageConfigValidator;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * @internal Checks the configuration and the selected idempotency backends
 * during deployment, before a request needs them.
 */
final class CheckHttpBasicsCommand extends Command
{
    protected $signature = 'http-basics:check';

    protected $description = 'Check HTTP Basics configuration and idempotency stores';

    /** Reads the configured stores without creating an idempotency record. */
    public function handle(PackageConfigValidator $validator, PackageConfig $settings, CacheFactory $cacheFactory): int
    {
        $problems = [];

        foreach ($validator->problems() as $problem) {
            $problems[] = $problem->getMessage();
        }

        $problems = array_filter([
            ...$problems,
            $this->cacheProblem($settings, $cacheFactory),
            $this->databaseProblem($settings),
        ], static fn (?string $problem): bool => $problem !== null);

        if ($problems === []) {
            $this->info('HTTP Basics configuration and stores are ready.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error(OutputFormatter::escape(str_replace(["\r", "\n"], ' ', $problem)));
        }

        return self::FAILURE;
    }

    private function cacheProblem(PackageConfig $settings, CacheFactory $cacheFactory): ?string
    {
        $storeName = $settings->optionalString('idempotency.cache_store');
        $resource = 'http-basics.idempotency.cache_store ('.($storeName ?? 'default').')';

        try {
            $cache = $cacheFactory->store($storeName);

            if (! $cache instanceof CacheRepository || ! $cache->getStore() instanceof LockProvider) {
                return "{$resource} must be an Illuminate cache repository with atomic locks.";
            }

            // Both idempotency adapters use cache, including for locks and seals.
            $cache->get('http-basics:check:'.Str::uuid());
        } catch (Throwable) {
            return "{$resource} is unreachable.";
        }

        return null;
    }

    private function databaseProblem(PackageConfig $settings): ?string
    {
        if ($settings->value('idempotency.store') !== 'database') {
            return null;
        }

        $connection = $settings->optionalString('idempotency.database.connection');
        $table = $settings->string('idempotency.database.table');

        try {
            // A replica can answer while the primary used for idempotency claims is down.
            DB::connection($connection)->table($table)->useWritePdo()->exists();
        } catch (Throwable) {
            $resource = ($connection ?? 'default').'.'.$table;

            return "http-basics.idempotency.database.table ({$resource}) is unreachable.";
        }

        return null;
    }
}
