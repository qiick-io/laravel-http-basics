<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Qiick\HttpBasics\Errors\ErrorCode;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @api Idempotency refusal thrown by middleware; products may catch and render.
 */
final class IdempotencyRejection extends HttpException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private ErrorCode $error,
        int $statusCode,
        array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $error->value, $previous, $headers);
    }

    public function errorCode(): string
    {
        return $this->error->value;
    }

    public function fleetErrorCode(): ErrorCode
    {
        return $this->error;
    }

    /**
     * @deprecated Use {@see fleetErrorCode()} — kept for call sites mid-migration.
     */
    public function idempotencyErrorCode(): ErrorCode
    {
        return $this->error;
    }
}
