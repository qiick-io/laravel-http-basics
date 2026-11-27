<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * @api FormRequest rule that runs the upload floor and keeps Laravel's 422 shape.
 */
final class SafeUpload implements ValidationRule
{
    public function __construct(
        private UploadValidator $validator,
        private string $context,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded file.');

            return;
        }

        try {
            $this->validator->validate($value, $this->context, $attribute);
        } catch (UploadRejectedException $exception) {
            $fail($exception->getMessage());
        }
    }
}
