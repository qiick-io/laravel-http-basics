<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Errors\ErrorCode;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;
use Qiick\HttpBasics\RequestId\AssignRequestIdMiddleware;
use Qiick\HttpBasics\Testing\FakeCredentialBucketResolver;
use Qiick\HttpBasics\Testing\InMemoryIdempotencyStore;

use function Qiick\HttpBasics\Testing\assertIdempotentReplay;

beforeEach(function (): void {
    $this->store = new InMemoryIdempotencyStore(staleAfterSeconds: 1);

    $this->app->forgetInstance(IdempotencyStore::class);
    $this->app->instance(IdempotencyStore::class, $this->store);
    $this->app->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver('tenant:1'));

    static $routesRegistered = false;
    if ($routesRegistered) {
        return;
    }

    Route::middleware([AssignRequestIdMiddleware::class, HandleIdempotencyMiddleware::class])
        ->group(function (): void {
            Route::post('/api/items', function () {
                return response()->json(['created' => true], 201);
            });

            Route::post('/api/items-optional', function () {
                return response()->json(['created' => true], 201);
            })->middleware(HandleIdempotencyMiddleware::class.':optional');

            Route::post('/api/secrets', function () {
                return response()->json(['secret' => 'once'], 201);
            })->middleware(HandleIdempotencyMiddleware::class.':replayable=false');

            Route::post('/api/validate', function () {
                return response()->json(['message' => 'bad', 'error' => 'validation_failed'], 422);
            });

            Route::post('/api/fail', function () {
                return response()->json(['error' => 'server'], 500);
            });
        });

    $routesRegistered = true;
});

it('requires a valid idempotency key', function (): void {
    $this->postJson('/api/items', [])
        ->assertStatus(400)
        ->assertJsonPath('error', 'idempotency_key_required');
});

it('rejects non json content type with 415', function (): void {
    $this->call(
        'POST',
        '/api/items',
        [],
        [],
        [],
        [
            'HTTP_Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440000',
            'CONTENT_TYPE' => 'multipart/form-data',
        ],
        'name=test',
    )->assertStatus(415)
        ->assertJsonPath('error', 'idempotency_unsupported_media');
});

it('executes once and replays exact bytes including 422', function (): void {
    $key = '550e8400-e29b-41d4-a716-446655440001';

    $first = $this->postJson('/api/items', ['name' => 'a'], [
        'Idempotency-Key' => $key,
    ]);

    $second = $this->postJson('/api/items', ['name' => 'a'], [
        'Idempotency-Key' => $key,
    ]);

    assertIdempotentReplay($first->baseResponse, $second->baseResponse);

    $first422 = $this->postJson('/api/validate', ['name' => 'a'], [
        'Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440002',
    ]);

    $second422 = $this->postJson('/api/validate', ['name' => 'a'], [
        'Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440002',
    ]);

    assertIdempotentReplay($first422->baseResponse, $second422->baseResponse);
});

it('never stores 5xx responses', function (): void {
    $key = '550e8400-e29b-41d4-a716-446655440003';

    $this->postJson('/api/fail', [], ['Idempotency-Key' => $key])->assertStatus(500);

    $retry = $this->postJson('/api/fail', [], ['Idempotency-Key' => $key]);
    expect($retry->status())->not->toBe(409);
});

it('returns payload mismatch for the same key with a different body', function (): void {
    $key = '550e8400-e29b-41d4-a716-446655440004';

    $this->postJson('/api/items', ['name' => 'a'], ['Idempotency-Key' => $key])->assertCreated();

    $this->postJson('/api/items', ['name' => 'b'], ['Idempotency-Key' => $key])
        ->assertStatus(422)
        ->assertJsonPath('error', 'idempotency_payload_mismatch');
});

it('does not store when replayable is false', function (): void {
    $key = '550e8400-e29b-41d4-a716-446655440005';

    $this->postJson('/api/secrets', [], ['Idempotency-Key' => $key])->assertCreated();
    $this->postJson('/api/secrets', [], ['Idempotency-Key' => $key])->assertCreated();
});

it('renders in progress refusals with retry-after on the json payload', function (): void {
    $rejection = new IdempotencyRejection(
        ErrorCode::IdempotencyInProgress,
        409,
        ['Retry-After' => '30'],
    );

    $response = app(ErrorRenderer::class)->render($rejection, request());

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Retry-After'))->toBe('30')
        ->and($response->getData(true)['error'])->toBe('idempotency_in_progress');
});
