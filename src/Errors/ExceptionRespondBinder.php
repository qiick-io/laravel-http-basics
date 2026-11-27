<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Package-owned seam for registering exception respond callbacks.
 *
 * No Illuminate\Foundation types appear in the package. Products adapt their
 * bootstrap (for example Laravel's Exceptions configuration) to this binder.
 */
interface ExceptionRespondBinder
{
    /**
     * @param  callable(Response, \Throwable, Request): Response  $callback
     */
    public function onRespond(callable $callback): void;
}
