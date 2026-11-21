<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Register globally so errors, redirects and streamed responses still get
 * defense headers. Machine doors get baseline only (no CSP / X-Frame-Options).
 */
final class SetSecurityHeadersMiddleware
{
    public function __construct(
        private SecurityHeaderBuilder $builder,
        private SecurityHeadersPolicy $policy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            throw new \UnexpectedValueException(
                'SetSecurityHeadersMiddleware expected a Symfony Response from $next.',
            );
        }

        foreach ($this->builder->forResponse($request, $response, $this->policy) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
