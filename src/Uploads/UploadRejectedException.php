<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @api Upload floor refusal with a stable fleet `error` code.
 */
final class UploadRejectedException extends HttpException
{
    public function __construct(
        private string $error,
        string $message,
        int $status = 422,
    ) {
        parent::__construct($status, $message);
    }

    public function errorCode(): string
    {
        return $this->error;
    }

    public static function forbiddenType(string $name): self
    {
        return new self('upload_forbidden_type', 'This file type is blocked by the security floor.');
    }

    public static function typeNotAllowed(string $name): self
    {
        return new self('upload_type_not_allowed', 'This file type is not allowed for this upload context.');
    }

    public static function contentMismatch(string $name): self
    {
        return new self('upload_content_mismatch', 'File content does not match the declared file type.');
    }

    public static function tooLarge(string $name): self
    {
        return new self('upload_too_large', 'The uploaded file is too large.');
    }

    public static function nameInvalid(string $name): self
    {
        return new self('upload_name_invalid', 'The uploaded file name is invalid.');
    }

    public static function infected(string $name): self
    {
        return new self('upload_infected', 'The uploaded file failed the malware scan.');
    }

    public static function scanFailed(string $name): self
    {
        return new self('upload_scan_failed', 'The malware scanner could not inspect this file.');
    }
}
