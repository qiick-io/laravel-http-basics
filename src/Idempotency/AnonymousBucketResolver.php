<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Http\Request;

/**
 * @api Anonymous bucket via HMAC over configurable request components.
 *
 * Best-effort de-duplication, not an identity boundary; depends on TrustProxies for `ip`.
 */
final class AnonymousBucketResolver
{
    /**
     * @param  list<'ip'|'user_agent'|'route'>  $components
     */
    public function __construct(
        private string $secret,
        private array $components,
    ) {}

    public function bucket(Request $request): string
    {
        $parts = [];
        foreach ($this->components as $component) {
            $parts[] = match ($component) {
                'ip' => $request->ip() ?? '',
                'user_agent' => $request->userAgent() ?? '',
                'route' => $request->route()?->getName()
                    ?? $request->route()?->uri()
                    ?? $request->path(),
                default => '',
            };
        }

        $material = implode("\n", $parts);

        return 'anon:'.hash_hmac('sha256', $material, $this->secret);
    }
}
