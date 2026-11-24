<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Illuminate\Http\Request;

/**
 * @api Default nonce provider: 128 bits from the CSPRNG, base64-encoded, kept on
 * the request so the policy builder, Vite and templates all see the same value.
 */
final class RequestCspNonceProvider implements CspNonceProvider
{
    public const string ATTRIBUTE = 'http_basics.csp_nonce';

    public function nonceFor(Request $request): string
    {
        $nonce = $request->attributes->get(self::ATTRIBUTE);

        if (! is_string($nonce)) {
            $nonce = base64_encode(random_bytes(16));
            $request->attributes->set(self::ATTRIBUTE, $nonce);
        }

        return $nonce;
    }
}
