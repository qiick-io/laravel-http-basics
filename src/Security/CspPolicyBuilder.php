<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Illuminate\Http\Request;

/**
 * @api Builds the default Content-Security-Policy when config does not override it.
 */
interface CspPolicyBuilder
{
    public function build(Request $request): string;
}
