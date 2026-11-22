<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Qiick\HttpBasics\Errors\ErrorCode;
use Qiick\HttpBasics\Errors\FrameworkErrorCodeResolver;
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
