<?php

declare(strict_types=1);

/*
 * The idempotency rows of docs/security-checklist.md that had no test through the
 * middleware: I7 bucket isolation, I8 the anonymous secret, I10 a lease lost
 * mid-flight, I15 refusals, I16 the throttle order.
 */

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Testing\FakeCredentialBucketResolver;
use Qiick\HttpBasics\Testing\InMemoryIdempotencyStore;

const CHECKLIST_KEY = '550e8400-e29b-41d4-a716-446655440077';

beforeEach(function (): void {
    $this->store = new InMemoryIdempotencyStore;
    $this->handlerRuns = new ArrayObject(['count' => 0]);
    $handlerRuns = $this->handlerRuns;

    $this->app->instance(IdempotencyStore::class, $this->store);
    $this->app->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver('tenant:1'));

    Route::middleware(HandleIdempotencyMiddleware::class)->group(function () use ($handlerRuns): void {
        Route::post('/api/checklist', function () use ($handlerRuns) {
            $handlerRuns['count']++;

            return response()->json(['run' => $handlerRuns['count']], 201);
        });

        Route::post('/api/checklist-lease-lost', function () use ($handlerRuns) {
            $handlerRuns['count']++;
            // Another request took the record over while this handler ran.
            (function (): void {
                foreach (array_keys($this->records) as $recordKey) {
                    $this->records[$recordKey]['lease'] = 'taken-over';
                }
            })->call(app(IdempotencyStore::class));

            return response()->json(['run' => $handlerRuns['count']], 201);
        });
    });

    Route::middleware(['throttle:1,1', HandleIdempotencyMiddleware::class])->post('/api/checklist-throttled', function () use ($handlerRuns) {
        $handlerRuns['count']++;

        return response()->json(['run' => $handlerRuns['count']], 201);
    });
});

/**
 * @return array<string, mixed>
 */
function checklistRecords(InMemoryIdempotencyStore $store): array
{
    /** @var array<string, mixed> */
    return wiredProperty($store, 'records');
}

it('never replays one bucket\'s response to another (I7)', function (): void {
    $this->postJson('/api/checklist', [], ['Idempotency-Key' => CHECKLIST_KEY])->assertStatus(201)->assertJsonPath('run', 1);

    $this->app->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver('tenant:2'));

    $this->postJson('/api/checklist', [], ['Idempotency-Key' => CHECKLIST_KEY])
        ->assertStatus(201)
        ->assertJsonPath('run', 2)
        ->assertHeaderMissing('Idempotent-Replayed');
});

it('never replays an anonymous response once the client is authenticated (I7)', function (): void {
    config([
        'http-basics.idempotency.anonymous.enabled' => true,
        'http-basics.idempotency.anonymous.secret' => str_repeat('s', 32),
    ]);
    $this->app->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver(null));

    $this->postJson('/api/checklist', [], ['Idempotency-Key' => CHECKLIST_KEY])->assertStatus(201)->assertJsonPath('run', 1);

    $this->app->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver('tenant:1'));

    $this->postJson('/api/checklist', [], ['Idempotency-Key' => CHECKLIST_KEY])
        ->assertStatus(201)
        ->assertJsonPath('run', 2)
        ->assertHeaderMissing('Idempotent-Replayed');
});

it('keeps the anonymous secret out of logs, responses and boot errors (I8)', function (): void {
    $secret = 'never-show-this-anonymous-secret-'.str_repeat('x', 8);
    config([
        'http-basics.idempotency.anonymous.enabled' => true,
        'http-basics.idempotency.anonymous.secret' => $secret,
    ]);
    $this->app->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver(null));
    $loggedMessages = [];
    Log::listen(static function ($messageLogged) use (&$loggedMessages): void {
        $loggedMessages[] = $messageLogged->message.json_encode($messageLogged->context);
    });

    $responses = [
        $this->postJson('/api/checklist', [], ['Idempotency-Key' => CHECKLIST_KEY])->getContent(),
        $this->postJson('/api/checklist', [], ['Idempotency-Key' => CHECKLIST_KEY])->getContent(),
        $this->postJson('/api/checklist', ['changed' => true], ['Idempotency-Key' => CHECKLIST_KEY])->getContent(),
    ];

    config(['http-basics.idempotency.anonymous.secret' => 'too-short-secret']);

    try {
        bootProvider();
        $bootError = '';
    } catch (InvalidArgumentException $exception) {
        $bootError = $exception->getMessage();
    }

    expect(implode("\n", [...$responses, ...$loggedMessages, json_encode(checklistRecords($this->store))]))->not->toContain($secret)
        ->and($bootError)->not->toBe('')->not->toContain('too-short-secret');
});

it('answers 503 and keeps no completed record when the lease is lost mid-flight (I10)', function (): void {
    $this->postJson('/api/checklist-lease-lost', [], ['Idempotency-Key' => CHECKLIST_KEY])
        ->assertStatus(503)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'idempotency_unavailable');

    $records = checklistRecords($this->store);

    expect($records)->toHaveCount(1)
        ->and(array_values($records)[0]['completed'])->toBeNull();
});

it('shows no debug output on a refusal with app.debug on (I15)', function (): void {
    config(['app.debug' => true]);

    $response = $this->postJson('/api/checklist', [], ['Idempotency-Key' => 'not-a-uuid'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'idempotency_key_invalid');

    expect($response->json())->not->toHaveKeys(['exception', 'file', 'line', 'trace']);
});

it('stores nothing for a refused request (I15)', function (array $headers, int $status): void {
    $this->postJson('/api/checklist', [], $headers)->assertStatus($status);

    expect(checklistRecords($this->store))->toBe([])
        ->and($this->handlerRuns['count'])->toBe(0);
})->with([
    'missing key' => [[], 400],
    'invalid key' => [['Idempotency-Key' => 'not-a-uuid'], 400],
]);

it('lets the throttle refuse before the handler and the store when it runs outside (I16)', function (): void {
    $this->postJson('/api/checklist-throttled', [], ['Idempotency-Key' => CHECKLIST_KEY])->assertStatus(201);

    $this->postJson('/api/checklist-throttled', [], ['Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440078'])
        ->assertStatus(429);

    expect(checklistRecords($this->store))->toHaveCount(1)
        ->and($this->handlerRuns['count'])->toBe(1);
});

it('replays with the throttle\'s current rate-limit headers, not the stored ones (I16)', function (): void {
    $this->postJson('/api/checklist-throttled', [], ['Idempotency-Key' => CHECKLIST_KEY])
        ->assertStatus(201)
        ->assertHeader('X-RateLimit-Remaining', '0');

    $this->travel(61)->seconds();

    $this->postJson('/api/checklist-throttled', [], ['Idempotency-Key' => CHECKLIST_KEY])
        ->assertStatus(201)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertHeader('X-RateLimit-Remaining', '0');

    $stored = array_values(checklistRecords($this->store))[0]['completed']['replay'];

    expect(array_change_key_case($stored->headers))->not->toHaveKey('x-ratelimit-remaining')
        ->and($this->handlerRuns['count'])->toBe(1);
});
