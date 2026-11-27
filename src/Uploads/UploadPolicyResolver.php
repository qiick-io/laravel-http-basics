<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Resolves the UploadPolicy for a named context.
 */
interface UploadPolicyResolver
{
    public function forContext(string $context): UploadPolicy;
}
