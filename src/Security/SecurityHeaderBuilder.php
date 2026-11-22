<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Computes security headers for a response without clobbering ones already
 * present (CSP, CSP-RO, XFO, Referrer, Permissions, nosniff, HSTS). If either
 * CSP or CSP-RO is already on the response, neither kind is added. See design §5.3.
 */
final class SecurityHeaderBuilder
{
    public function __construct(
        private CspPolicyBuilder $cspPolicyBuilder,
    ) {}

    /**
     * @return array<string, string> Header name => value to set (absent headers only).
     */
    public function forResponse(
        Request $request,
        Response $response,
        SecurityHeadersPolicy $policy,
    ): array {
        if (! $policy->enabled) {
            return [];
        }

        if ($this->isMachineDoor($request, $policy->machineDoorPatterns)) {
            if (! $policy->baselineOnMachineDoors) {
                return [];
            }

            return $this->baselineHeaders($response, $policy);
        }

        $headers = $this->baselineHeaders($response, $policy);

        $this->putIfAbsent($headers, $response, 'X-Frame-Options', $policy->xFrameOptions);

        $this->applyCsp($headers, $request, $response, $policy);
        $this->applyHsts($headers, $request, $response, $policy);

        return $headers;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function isMachineDoor(Request $request, array $patterns): bool
    {
        if ($patterns === []) {
            // Request::is() with zero patterns also returns false.
            return false; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        return $request->is(...$patterns);
    }

    /**
     * @return array<string, string>
     */
    private function baselineHeaders(Response $response, SecurityHeadersPolicy $policy): array
    {
        $headers = [];

        $this->putIfAbsent($headers, $response, 'X-Content-Type-Options', 'nosniff');
        $this->putIfAbsent($headers, $response, 'Referrer-Policy', $policy->referrerPolicy);
        $this->putIfAbsent($headers, $response, 'Permissions-Policy', $policy->permissionsPolicy);

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function applyCsp(
        array &$headers,
        Request $request,
        Response $response,
        SecurityHeadersPolicy $policy,
    ): void {
        if ($policy->cspMode === CspMode::Off) {
            // Off is neither Enforce/Both nor ReportOnly/Both, so fall-through adds nothing.
            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // Product already chose a CSP surface: do not add the other kind either.
        if (
            $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')
        ) {
            return;
        }

        if ($policy->cspMode === CspMode::Enforce || $policy->cspMode === CspMode::Both) {
            $value = $this->resolveCspValue($request, $policy->csp, $policy->cspReportUri);

            $this->putIfAbsent($headers, $response, 'Content-Security-Policy', $value);
        }

        if ($policy->cspMode === CspMode::ReportOnly || $policy->cspMode === CspMode::Both) {
            $reportOnly = $policy->cspReportOnly;

            if ($reportOnly === null || trim($reportOnly) === '') {
                $reportOnly = $this->resolveCspValue($request, $policy->csp, $policy->cspReportUri);
            } else {
                $reportOnly = $this->withReportUri(trim($reportOnly), $policy->cspReportUri);
            }

            $this->putIfAbsent($headers, $response, 'Content-Security-Policy-Report-Only', $reportOnly);
        }
    }

    private function resolveCspValue(Request $request, ?string $override, ?string $reportUri): string
    {
        if (is_string($override) && trim($override) !== '') {
            return $this->withReportUri(trim($override), $reportUri);
        }

        return $this->withReportUri($this->cspPolicyBuilder->build($request), $reportUri);
    }

    private function withReportUri(string $policy, ?string $reportUri): string
    {
        if ($reportUri === null || trim($reportUri) === '') {
            return $policy;
        }

        return rtrim($policy, '; ').'; report-uri '.trim($reportUri);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function applyHsts(
        array &$headers,
        Request $request,
        Response $response,
        SecurityHeadersPolicy $policy,
    ): void {
        if ($policy->hstsMaxAge <= 0) {
            return;
        }

        if (! $request->isSecure()) {
            return;
        }

        $value = 'max-age='.$policy->hstsMaxAge;

        if ($policy->hstsIncludeSubdomains) {
            $value .= '; includeSubDomains';
        }

        if ($policy->hstsPreload) {
            $value .= '; preload';
        }

        $this->putIfAbsent($headers, $response, 'Strict-Transport-Security', $value);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function putIfAbsent(array &$headers, Response $response, string $name, string $value): void
    {
        if ($response->headers->has($name)) {
            return;
        }

        $headers[$name] = $value;
    }
}
