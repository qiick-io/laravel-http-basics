<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Register OUTERMOST (prepend) so 401/404/405/419/429/503, maintenance and
 * streamed responses still get defense headers. Machine doors get baseline only
 * (no CSP / X-Frame-Options). With `csp.nonce` on, the request nonce goes to
 * Vite before the handler runs, so the tags it renders carry it.
 */
final class SetSecurityHeadersMiddleware
{
    public function __construct(
        private SecurityHeaderBuilder $builder,
        private SecurityHeadersPolicy $policy,
        private CspNonceProvider $nonces = new RequestCspNonceProvider,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->policy->cspNonce) {
            $this->handNonceToVite($this->nonces->nonceFor($request));
        }

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

    /**
     * Vite puts the nonce on the tags it renders, so this must happen before the
     * handler runs. Without Vite (an API-only app) there is nothing to hand over.
     */
    private function handNonceToVite(string $nonce): void
    {
        try {
            $vite = Vite::getFacadeRoot();

            if (is_callable([$vite, 'useCspNonce'])) {
                \call_user_func([$vite, 'useCspNonce'], $nonce);
            }
        } catch (\Throwable) {
            // No Vite bound.
        }
    }
}
