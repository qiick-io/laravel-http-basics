<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Erasure\HttpBasicsEraser;
use Qiick\HttpBasics\Erasure\OrganisationScopeUnsupportedException;
use Qiick\HttpBasics\Events\SubjectErasedEvent;
use Qiick\HttpBasics\Idempotency\IdempotencyContext;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

it('dispatches the cache-only event only after the caller commits', function (): void {
    Event::fake([SubjectErasedEvent::class]);
    $erase = static fn () => app(HttpBasicsEraser::class)->erase('123456', (string) Str::uuid(), HttpBasicsEraser::DELETE);

    expect(fn () => DB::transaction(function () use ($erase): void {
        $erase();

        throw new RuntimeException('Roll back the caller.');
    }))->toThrow(RuntimeException::class, 'Roll back the caller.');
    Event::assertNotDispatched(SubjectErasedEvent::class);

    DB::transaction(function () use ($erase): void {
        $erase();
        Event::assertNotDispatched(SubjectErasedEvent::class);
    });

    Event::assertDispatchedOnce(SubjectErasedEvent::class);
});

it('dispatches cache-only erasure when the product has no database connection', function (): void {
    Event::fake([SubjectErasedEvent::class]);
    $eraser = new HttpBasicsEraser(app(Dispatcher::class), new ArrayStore, 'array', 'idempotency_records', null);

    $eraser->erase('123456', (string) Str::uuid(), HttpBasicsEraser::ANONYMISE);

    Event::assertDispatchedOnce(SubjectErasedEvent::class);
});

it('reports cache-only retention without forgetting unrelated keys or falling back from organisation scope', function (): void {
    config()->set('http-basics.idempotency.cache_store', 'array');
    Event::fake([SubjectErasedEvent::class]);
    $scope = new IdempotencyScope('user:123456', 'POST', '/api/items');
    $key = (string) Str::uuid();
    $userPublicId = (string) Str::uuid();
    $now = new DateTimeImmutable;
    $store = app(IdempotencyStore::class);
    $claim = $store->claim($scope, $key, hash('sha256', $key), $now, new IdempotencyContext(IdempotencyKeyFormat::Uuid));
    $store->complete($claim, new ReplayableResponse(201, [], 'subject data'), $now);

    $eraser = app(HttpBasicsEraser::class);
    expect(fn () => $eraser->erase('123456', $userPublicId, HttpBasicsEraser::DELETE, organisationId: '1234'))
        ->toThrow(OrganisationScopeUnsupportedException::class);

    $dryRun = $eraser->erase('123456', $userPublicId, HttpBasicsEraser::DELETE, dryRun: true);
    Event::assertNotDispatched(SubjectErasedEvent::class);
    $report = $eraser->erase('123456', $userPublicId, HttpBasicsEraser::DELETE);
    Event::assertDispatchedOnce(SubjectErasedEvent::class);

    expect($report->changed())->toBe(0)
        ->and($report->stores)->toBe($dryRun->stores)
        ->and($report->stores['cache:array'])->toBe(['deleted' => 0, 'anonymised' => 0, 'kept' => 1, 'reason' => 'cache_ttl'])
        ->and(Cache::store('array')->has($scope->cacheKey($key)))->toBeTrue();

    $this->travel(86401)->seconds();

    expect(Cache::store('array')->has($scope->cacheKey($key)))->toBeFalse();
});
