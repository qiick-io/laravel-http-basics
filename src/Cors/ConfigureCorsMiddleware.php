<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Writes `cors.allowed_origins` for the current request before HandleCors.
 *
 * Prepend ahead of `Illuminate\Http\Middleware\HandleCors`. Origin enforcement
 * (API-key gates) stays in the product (externa).
 */
final class ConfigureCorsMiddleware
{
    public function __construct(
        private AllowedOriginsResolver $origins,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->origins->origins($request);

        if ($resolved !== null) {
            config(['cors.allowed_origins' => array_values($resolved)]);
        }

        return $next($request);
    }
}
