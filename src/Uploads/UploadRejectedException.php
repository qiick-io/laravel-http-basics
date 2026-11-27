<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

use Qiick\HttpBasics\Errors\ErrorCode;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @api Upload floor refusal with a stable fleet `error` code.
 */
final class UploadRejectedException extends HttpException
{
    public function __construct(
        private ErrorCode $errorCode,
        string $message,
        int $status = 422,
    ) {
        parent::__construct($status, $message);
    }

    public function errorCode(): string
    {
        return $this->errorCode->value;
    }

    public function fleetErrorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public static function forbiddenType(): self
    {
        return new self(ErrorCode::UploadForbiddenType, 'This file type is blocked by the security floor.');
    }

    public static function typeNotAllowed(): self
    {
        return new self(ErrorCode::UploadTypeNotAllowed, 'This file type is not allowed for this upload context.');
    }

    public static function contentMismatch(): self
    {
        return new self(ErrorCode::UploadContentMismatch, 'File content does not match the declared file type.');
    }

    public static function tooLarge(): self
    {
        return new self(ErrorCode::UploadTooLarge, 'The uploaded file is too large.');
    }

    public static function nameInvalid(): self
    {
        return new self(ErrorCode::UploadNameInvalid, 'The uploaded file name is invalid.');
    }

    public static function infected(): self
    {
        return new self(ErrorCode::UploadInfected, 'The uploaded file failed the malware scan.');
    }

    public static function scanFailed(): self
    {
        return new self(ErrorCode::UploadScanFailed, 'The malware scanner could not inspect this file.');
    }
}
