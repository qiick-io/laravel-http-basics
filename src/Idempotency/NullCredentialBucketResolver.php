<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;

/**
 * @api Default credential bucket resolver until the product binds a real implementation.
 */
final class NullCredentialBucketResolver implements CredentialBucketResolver
{
    public function bucket(Request $request): ?string
    {
        return null;
    }
}
