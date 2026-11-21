<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Locale;

use Illuminate\Http\Request;

/** @api */
final readonly class MachineDoorClassifier
{
    /**
     * @param  list<string>  $patterns
     */
    public function __construct(
        private array $patterns,
    ) {}

    public function isMachineDoor(Request $request): bool
    {
        if ($this->patterns === []) {
            return false;
        }

        return $request->is(...$this->patterns);
    }
}
