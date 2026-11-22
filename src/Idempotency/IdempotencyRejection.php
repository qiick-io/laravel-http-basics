<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

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
        private IdempotencyErrorCode $idempotencyCode,
        int $statusCode,
        array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $idempotencyCode->value, $previous, $headers);
    }

    public function errorCode(): string
    {
        return $this->idempotencyCode->value;
    }

    public function idempotencyErrorCode(): IdempotencyErrorCode
    {
        return $this->idempotencyCode;
    }
}
