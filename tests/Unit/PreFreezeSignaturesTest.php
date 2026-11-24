<?php

declare(strict_types=1);

/*
 * Public signatures settled before the 1.0 freeze (issue #34).
 */

use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Uploads\UploadRejectedException;

it('types the claim lease as a nullable string', function (): void {
    $lease = (new ReflectionProperty(IdempotencyClaim::class, 'lease'))->getType();

    expect((string) $lease)->toBe('?string');
});

it('builds every upload rejection without the unused file name', function (string $factory): void {
    expect((new ReflectionMethod(UploadRejectedException::class, $factory))->getNumberOfParameters())->toBe(0);
})->with(['forbiddenType', 'typeNotAllowed', 'contentMismatch', 'tooLarge', 'nameInvalid', 'infected', 'scanFailed']);
