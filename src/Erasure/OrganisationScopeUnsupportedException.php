<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Erasure;

use LogicException;

/** @api Signals that the configured idempotency store cannot filter by organisation. */
final class OrganisationScopeUnsupportedException extends LogicException {}
