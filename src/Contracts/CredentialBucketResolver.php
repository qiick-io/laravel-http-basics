<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Contracts;

use Illuminate\Http\Request;

/**
 * @api Resolves the idempotency bucket from authenticated server state only.
 *
 * Products bind this to map principals, tenants, or API keys to a stable bucket
 * string. Request input (query `bucket=`, `X-Bucket`, etc.) must never be used.
 */
interface CredentialBucketResolver
{
    /**
     * Prefer shapes such as `tenant:<uuid>:key:<uuid>` when multi-tenant.
     */
    public function bucket(Request $request): ?string;
}
