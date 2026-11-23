<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Illuminate\Http\Request;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;

/**
 * @api Builds a shouldRenderJsonWhen-style callback from machine-door path patterns.
 *
 * Illuminate-only: returns a Closure products pass to their exception JSON gate.
 */
final class MachineDoorJson
{
    /**
     * @param  list<string>  $patterns
     * @return \Closure(Request): bool
     */
    public static function whenCallback(array $patterns): \Closure
    {
        $classifier = new MachineDoorClassifier($patterns);

        return static fn (Request $request): bool => $classifier->isMachineDoor($request);
    }
}
