<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Locale;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Qiick\HttpBasics\Http\VaryHeaderMerger;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Register on the `web` group (after StartSession) and the `api` group.
 * Prepend globally as well when maintenance / early errors must carry
 * Content-Language (see README).
 */
final class SetLocaleMiddleware
{
    public function __construct(
        private LocaleNegotiator $negotiator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolution = $this->negotiator->resolve($request);
        App::setLocale($resolution->locale);

        $response = $next($request);

        if (! $response instanceof Response) {
            throw new \UnexpectedValueException(
                'SetLocaleMiddleware expected a Symfony Response from $next.',
            );
        }

        $final = $this->negotiator->current($request);
        App::setLocale($final->locale);

        $response->headers->set('Content-Language', $final->locale);

        // Vary whenever Accept-Language was read, not only when it won.
        if ($final->didReadAcceptLanguage) {
            VaryHeaderMerger::add($response->headers, 'Accept-Language');
        }

        return $response;
    }
}
