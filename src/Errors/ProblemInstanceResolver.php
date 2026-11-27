<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Illuminate\Http\Request;

/**
 * @api Optional URI reference for the RFC 9457 `instance` member.
 */
interface ProblemInstanceResolver
{
    public function instance(Request $request, string $errorCode): ?string;
}
