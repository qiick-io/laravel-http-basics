<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

use Illuminate\Http\Request;

/**
 * @api Resolves per-request CORS allowed origins for ConfigureCorsMiddleware.
 *
 * Return `null` to leave `config('cors.allowed_origins')` unchanged.
 *
 * @return list<string>|null
 */
interface AllowedOriginsResolver
{
    /**
     * @return list<string>|null
     */
    public function origins(Request $request): ?array;
}
