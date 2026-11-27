<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Qiick\HttpBasics\Erasure\OrganisationScopeUnsupportedException;
use Qiick\HttpBasics\Errors\ErrorCode;
use Qiick\HttpBasics\Errors\FrameworkErrorCodeResolver;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\PayloadTooLargeException;
use Qiick\HttpBasics\Uploads\UploadRejectedException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

it('documents every ErrorCode case and keeps docs/error-codes.md in sync', function (): void {
    $docsPath = dirname(__DIR__, 2).'/docs/error-codes.md';
    $docs = file_get_contents($docsPath);
    expect($docs)->toBeString();

    foreach (ErrorCode::cases() as $code) {
        expect($code->documentation())->not->toBe('')
            ->and($docs)->toContain('`'.$code->value.'`')
            ->and($docs)->toContain($code->documentation());
    }

    preg_match_all('/^\| `([a-z0-9_]+)` \|/m', $docs, $matches);
    $documented = $matches[1] ?? [];
    sort($documented);
    $enumValues = ErrorCode::values();
    sort($enumValues);

    expect($documented)->toBe($enumValues);
});

it('maps every register framework exception to a documented ErrorCode', function (): void {
    $resolver = new FrameworkErrorCodeResolver;

    if (class_exists('Illuminate\\Validation\\ValidationException')) {
        $validation = ValidationException::withMessages(['field' => ['bad']]);
        expect($resolver->resolve($validation))->toBe(ErrorCode::ValidationFailed);
    }

    if (class_exists('Illuminate\\Auth\\AuthenticationException')) {
        expect($resolver->resolve(new AuthenticationException))->toBe(ErrorCode::Unauthenticated);
    }

    if (class_exists('Illuminate\\Auth\\Access\\AuthorizationException')) {
        expect($resolver->resolve(new AuthorizationException))->toBe(ErrorCode::Forbidden);
    }

    if (class_exists('Illuminate\\Database\\Eloquent\\ModelNotFoundException')) {
        expect($resolver->resolve(new ModelNotFoundException))->toBe(ErrorCode::NotFound);
    }

    expect($resolver->resolve(new NotFoundHttpException))->toBe(ErrorCode::NotFound)
        ->and($resolver->resolve(new MethodNotAllowedHttpException(['GET'])))->toBe(ErrorCode::MethodNotAllowed)
        ->and($resolver->resolve(new TooManyRequestsHttpException))->toBe(ErrorCode::TooManyRequests)
        ->and($resolver->resolve(new ConflictHttpException))->toBe(ErrorCode::Conflict)
        ->and($resolver->resolve(new UnsupportedMediaTypeHttpException))->toBe(ErrorCode::UnsupportedMediaType)
        ->and($resolver->resolve(new ServiceUnavailableHttpException))->toBe(ErrorCode::ServiceUnavailable)
        ->and($resolver->resolve(new HttpException(401, 'auth')))->toBe(ErrorCode::Unauthenticated)
        ->and($resolver->resolve(new HttpException(403, 'forbid')))->toBe(ErrorCode::Forbidden)
        ->and($resolver->resolve(new HttpException(415, 'media')))->toBe(ErrorCode::UnsupportedMediaType)
        ->and($resolver->resolve(new HttpException(422, 'invalid')))->toBe(ErrorCode::ValidationFailed)
        ->and($resolver->resolve(new HttpException(418, 'teapot')))->toBe(ErrorCode::HttpError)
        ->and($resolver->resolve(new HttpException(499, 'client-closed')))->toBe(ErrorCode::HttpError)
        ->and($resolver->resolve(new HttpException(500, 'fail')))->toBe(ErrorCode::ServerError)
        ->and($resolver->resolve(new HttpException(502, 'bad-gateway')))->toBe(ErrorCode::ServerError)
        ->and($resolver->resolve(new RuntimeException('boom')))->toBe(ErrorCode::ServerError);

    expect(ErrorCode::tryFrom($resolver->forException(new RuntimeException('boom'))))->toBe(ErrorCode::ServerError);
});

/**
 * Every concrete Throwable declared under src/, by PSR-4 path.
 *
 * @return list<class-string<Throwable>>
 */
function packageExceptionClasses(): array
{
    $sourceRoot = dirname(__DIR__, 2).'/src';
    $exceptionClasses = [];
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        // PSR-4 class files start upper-case; `functions.php` must not be autoloaded twice.
        if (! ctype_upper($sourceFile->getFilename()[0])) {
            continue;
        }

        $relativePath = substr((string) $sourceFile, strlen($sourceRoot) + 1, -strlen('.php'));
        $className = 'Qiick\\HttpBasics\\'.str_replace('/', '\\', $relativePath);

        if (class_exists($className) && is_subclass_of($className, Throwable::class) && ! (new ReflectionClass($className))->isAbstract()) {
            $exceptionClasses[] = $className;
        }
    }

    return $exceptionClasses;
}

it('gives every exception the package lets escape a documented fleet code', function (): void {
    $docs = (string) file_get_contents(dirname(__DIR__, 2).'/docs/error-codes.md');
    $middlewareSource = (string) file_get_contents(dirname(__DIR__, 2).'/src/Idempotency/HandleIdempotencyMiddleware.php');
    $resolver = new FrameworkErrorCodeResolver;

    // Thrown and caught inside HandleIdempotencyMiddleware, which answers with an IdempotencyRejection.
    $internalExceptionClasses = [IdempotencyStoreException::class, PayloadTooLargeException::class];

    $idempotencyCodes = array_values(array_filter(
        ErrorCode::cases(),
        static fn (ErrorCode $code): bool => str_starts_with($code->value, 'idempotency_'),
    ));

    /** @var array<class-string<Throwable>, list<array{Throwable, ErrorCode}>> $samplesByExceptionClass */
    $samplesByExceptionClass = [
        // The product orchestrator handles this configuration failure; HTTP uses the generic server code.
        OrganisationScopeUnsupportedException::class => [[new OrganisationScopeUnsupportedException, ErrorCode::ServerError]],
        IdempotencyRejection::class => array_map(
            static fn (ErrorCode $code): array => [new IdempotencyRejection($code, 409), $code],
            $idempotencyCodes,
        ),
        UploadRejectedException::class => [
            [UploadRejectedException::forbiddenType(), ErrorCode::UploadForbiddenType],
            [UploadRejectedException::typeNotAllowed(), ErrorCode::UploadTypeNotAllowed],
            [UploadRejectedException::contentMismatch(), ErrorCode::UploadContentMismatch],
            [UploadRejectedException::tooLarge(), ErrorCode::UploadTooLarge],
            [UploadRejectedException::nameInvalid(), ErrorCode::UploadNameInvalid],
            [UploadRejectedException::infected(), ErrorCode::UploadInfected],
            [UploadRejectedException::scanFailed(), ErrorCode::UploadScanFailed],
        ],
    ];

    $uploadCodes = array_values(array_filter(
        ErrorCode::cases(),
        static fn (ErrorCode $code): bool => str_starts_with($code->value, 'upload_'),
    ));

    // Every upload_* case has a factory sample, and each sample keeps its wire value.
    expect(array_map(static fn (array $sample): ErrorCode => $sample[1], $samplesByExceptionClass[UploadRejectedException::class]))
        ->toBe($uploadCodes);

    foreach ($samplesByExceptionClass[UploadRejectedException::class] as [$exception, $expectedCode]) {
        expect($exception->errorCode())->toBe($expectedCode->value);
    }

    expect($idempotencyCodes)->not->toBe([]);

    foreach ($internalExceptionClasses as $internalExceptionClass) {
        expect($middlewareSource)->toContain('catch ('.class_basename($internalExceptionClass).')');
    }

    foreach (packageExceptionClasses() as $exceptionClass) {
        if (in_array($exceptionClass, $internalExceptionClasses, true)) {
            continue;
        }

        expect(array_key_exists($exceptionClass, $samplesByExceptionClass))
            ->toBeTrue("{$exceptionClass} has no fleet error code: add its cases to ErrorCode and docs/error-codes.md, then a sample here.");
    }

    foreach ($samplesByExceptionClass as $samples) {
        foreach ($samples as [$exception, $expectedCode]) {
            expect($resolver->resolve($exception))->toBe($expectedCode)
                ->and($docs)->toContain('| `'.$expectedCode->value.'` |');
        }
    }
});
