<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;

/**
 * @api Returns a fixed credential bucket in tests (never from request input).
 */
final class FakeCredentialBucketResolver implements CredentialBucketResolver
{
    public function __construct(private ?string $bucket = 'test:principal:1') {}

    public function bucket(Request $request): ?string
    {
        unset($request);

        return $this->bucket;
    }
}
