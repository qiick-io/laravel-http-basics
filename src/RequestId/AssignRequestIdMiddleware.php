<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Global outermost middleware so 401/404/405/419/429/503 carry X-Request-Id.
 */
final class AssignRequestIdMiddleware
{
    public function __construct(
        private RequestIdResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolver->bind($request);

        $response = $next($request);

        if (! $response instanceof Response) {
            throw new \UnexpectedValueException(
                'AssignRequestIdMiddleware expected a Symfony Response from $next.',
            );
        }

        $this->resolver->echoInto($id, $response);

        return $response;
    }
}
