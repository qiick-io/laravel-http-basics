<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

use Illuminate\Http\Request;

/**
 * @api Default resolver that leaves `cors.allowed_origins` unchanged.
 */
final class NullAllowedOriginsResolver implements AllowedOriginsResolver
{
    public function origins(Request $request): ?array
    {
        return null;
    }
}
