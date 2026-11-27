<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Qiick\HttpBasics\Erasure\HttpBasicsEraser;
use Qiick\HttpBasics\Events\SubjectErasedEvent;
use Qiick\Standards\Erasure\Support\ErasureConformanceRunner;

foreach (glob(__DIR__.'/Support/*.php') as $supportFile) {
    require_once $supportFile;
}

$cacheFixture = (require __DIR__.'/fixture.php')->forCache();

// The pinned runner assumes all seeded data is deleted or unlinked, every real
// run writes SQL, and every seeded cache key can be forgotten. Rule 6 allows a
// TTL-only cache. Prove those cases below using that retention policy instead.
foreach (array_diff_key(ErasureConformanceRunner::CHECKS, array_flip(['E2', 'E3-delete', 'E3-anonymise', 'E9', 'E13'])) as $checkId => $description) {
    it("checks cache erasure ({$checkId}) {$description}", function () use ($cacheFixture, $checkId): void {
        (new ErasureConformanceRunner($cacheFixture))->run($checkId);

        expect(true)->toBeTrue();
    });
}

it('reports only the TTL policy for an empty cache subject (E2)', function () use ($cacheFixture): void {
    Event::fake([SubjectErasedEvent::class]);
    $person = $cacheFixture->makePerson();
    $report = app(HttpBasicsEraser::class)->erase(...$person->arguments(HttpBasicsEraser::DELETE));

    expect($report->changed())->toBe(0)
        ->and($report->stores['idempotency_records']['kept'])->toBe(0)
        ->and($report->stores['cache:array'])->toBe(['deleted' => 0, 'anonymised' => 0, 'kept' => 1, 'reason' => 'cache_ttl']);
    Event::assertDispatchedOnce(SubjectErasedEvent::class);
});

it('expires cache-only payloads and seals at the declared TTL (E3 and E13)', function (string $mode) use ($cacheFixture): void {
    $person = $cacheFixture->makePerson();
    $cacheFixture->seed($person, null);
    $cache = Cache::store('array');
    [$replayKey, $sealKey] = $cacheFixture->cacheKeys($person)['array'];
    $seededEntries = $cache->many([$replayKey, $sealKey]);

    $report = app(HttpBasicsEraser::class)->erase(...$person->arguments($mode));

    expect($seededEntries[$replayKey]['body'])->toBe($person->identifiers[0])
        ->and($seededEntries[$sealKey])->toBeTrue()
        ->and($cache->many([$replayKey, $sealKey]))->toBe($seededEntries)
        ->and($report->stores['cache:array']['reason'])->toBe('cache_ttl');

    $this->travel(86400)->seconds();

    expect($cache->many([$replayKey, $sealKey]))->toBe([$replayKey => null, $sealKey => null]);
})->with([HttpBasicsEraser::DELETE, HttpBasicsEraser::ANONYMISE]);

it('leaves retained cache data alone when the caller transaction fails (E9)', function (string $mode) use ($cacheFixture): void {
    Event::fake([SubjectErasedEvent::class]);
    $person = $cacheFixture->makePerson();
    $cacheFixture->seed($person, null);
    $cacheKeys = $cacheFixture->cacheKeys($person)['array'];
    $cache = Cache::store('array');
    $seededEntries = $cache->many($cacheKeys);
    DB::connection()->beforeExecuting(static function (string $query): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query) === 1) {
            throw new LogicException('A cache-only erasure must never write SQL.');
        }
    });

    expect(fn () => DB::transaction(function () use ($person, $mode): void {
        app(HttpBasicsEraser::class)->erase(...$person->arguments($mode));

        throw new RuntimeException('Roll back the caller.');
    }))->toThrow(RuntimeException::class, 'Roll back the caller.');

    expect($cache->many($cacheKeys))->toBe($seededEntries);
    Event::assertNotDispatched(SubjectErasedEvent::class);
})->with([HttpBasicsEraser::DELETE, HttpBasicsEraser::ANONYMISE]);
