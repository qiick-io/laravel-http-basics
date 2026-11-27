<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Errors\ErrorCodeAugmentor;
use Qiick\HttpBasics\Errors\ExceptionErrorCodeAugmenter;
use Qiick\HttpBasics\Errors\FrameworkErrorCodeResolver;
use Qiick\HttpBasics\Errors\MachineDoorJson;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

it('adds error only on JSON responses with status at least 400', function (): void {
    $augmentor = new ErrorCodeAugmentor;

    $ok = $augmentor->augment(new JsonResponse(['message' => 'ok'], 200), 'server_error');
    expect($ok->getData(true))->toBe(['message' => 'ok']);

    $redirect = $augmentor->augment(new JsonResponse(['message' => 'go'], 399), 'http_error');
    expect($redirect->getData(true))->not->toHaveKey('error');

    $boundary = $augmentor->augment(new JsonResponse(['message' => 'bad'], 400), 'http_error');
    expect($boundary->getData(true)['error'])->toBe('http_error');

    $augmented = $augmentor->augment(new JsonResponse(['message' => 'gone'], 404), 'not_found');
    expect($augmented->getData(true))->toBe(['message' => 'gone', 'error' => 'not_found']);
});

it('leaves non-array JSON payloads untouched', function (): void {
    $augmentor = new ErrorCodeAugmentor;
    $response = new JsonResponse('plain-string-body', 500);

    expect($augmentor->augment($response, 'server_error')->getContent())->toBe('"plain-string-body"');
});

it('never overwrites an existing error member', function (): void {
    $augmentor = new ErrorCodeAugmentor;
    $response = new JsonResponse(['message' => 'no', 'error' => 'product_code'], 403);

    expect($augmentor->augment($response, 'forbidden')->getData(true)['error'])->toBe('product_code');
});

it('maps common HTTP exceptions through the framework resolver', function (): void {
    $resolver = new FrameworkErrorCodeResolver;

    expect($resolver->forException(new NotFoundHttpException))->toBe('not_found')
        ->and($resolver->forException(new TooManyRequestsHttpException))->toBe('too_many_requests')
        ->and($resolver->forException(new RuntimeException('boom')))->toBe('server_error');
});

it('augments only machine-door JSON through the exception callback', function (): void {
    $callback = ExceptionErrorCodeAugmenter::callback(
        new FrameworkErrorCodeResolver,
        new MachineDoorClassifier(['api/*']),
    );

    $request = Request::create('/api/widgets', 'GET');
    $response = new JsonResponse(['message' => 'missing'], 404);
    $augmented = $callback($response, new NotFoundHttpException, $request);

    expect($augmented)->toBeInstanceOf(JsonResponse::class)
        ->and($augmented->getData(true)['error'])->toBe('not_found');

    $web = Request::create('/settings', 'GET');
    $untouched = $callback(new JsonResponse(['message' => 'missing'], 404), new NotFoundHttpException, $web);
    expect($untouched->getData(true))->not->toHaveKey('error');
});

it('E3 builds a machine-door JSON gate callback without an Accept header', function (): void {
    $when = MachineDoorJson::whenCallback(['api/*', 'mcp', 'mcp/*']);
    $request = Request::create('/api/items', 'GET');
    $request->headers->remove('Accept');

    expect($request->headers->has('Accept'))->toBeFalse()
        ->and($when($request))->toBeTrue()
        ->and($when(Request::create('/home', 'GET')))->toBeFalse();
});
