<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Illuminate\Http\Request;

/**
 * @api The CSP nonce of one request: the same value every time it is asked for that
 * request, unguessable (at least 128 random bits, base64), never reused across requests.
 */
interface CspNonceProvider
{
    public function nonceFor(Request $request): string;
}
