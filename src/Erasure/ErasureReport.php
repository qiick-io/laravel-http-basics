<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Erasure;

/**
 * @api Counts package changes without returning any subject identifiers.
 *
 * @phpstan-type StoreCounts array{deleted: int, anonymised: int, kept: int, reason: ?string}
 */
final readonly class ErasureReport
{
    /** @param array<string, StoreCounts> $stores */
    public function __construct(
        public string $package,
        public string $mode,
        public bool $dryRun,
        public array $stores,
    ) {}

    public function changed(): int
    {
        return array_sum(array_map(static fn (array $counts): int => $counts['deleted'] + $counts['anonymised'], $this->stores));
    }

    /** @return array{package: string, mode: string, dry_run: bool, stores: array<string, StoreCounts>} */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'mode' => $this->mode,
            'dry_run' => $this->dryRun,
            'stores' => $this->stores,
        ];
    }
}
