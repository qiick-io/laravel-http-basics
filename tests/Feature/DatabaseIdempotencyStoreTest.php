<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Erasure\HttpBasicsEraser;
use Qiick\HttpBasics\Events\SubjectErasedEvent;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore;
use Qiick\HttpBasics\Idempotency\Fingerprinter;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyContext;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Idempotency\SealedKeyMarker;
use Qiick\HttpBasics\Testing\FakeCredentialBucketResolver;

beforeEach(function (): void {
    Schema::dropIfExists('idempotency_records');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_000001_create_idempotency_records_table.php';
    $migration->up();
});

it('creates a portable metadata column and a native organisation UUID where available', function (): void {
    $metadataType = match (DB::connection()->getDriverName()) {
        'sqlite' => 'text',
        'mariadb' => 'longtext',
        default => 'json',
    };

    expect(Schema::getColumnType('idempotency_records', 'metadata'))->toBe($metadataType);

    if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mariadb'], true)) {
        expect(Schema::getColumnType('idempotency_records', 'organisation_id'))->toBe('uuid');
    }
});

it('erases a middleware claim by its trusted user and organisation even when its bucket is opaque', function (): void {
    config()->set('http-basics.idempotency.store', 'database');
    config()->set('http-basics.idempotency.cache_store', 'array');
    app()->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver('opaque-bucket'));

    $user = new GenericUser(['id' => 123456]);
    $userPublicId = (string) Str::uuid();
    $credentialPublicId = (string) Str::uuid();
    $organisationId = (string) Str::uuid();
    $otherOrganisationId = (string) Str::uuid();
    $request = Request::create('/api/items', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
    ], content: json_encode(['organisation_id' => $otherOrganisationId], JSON_THROW_ON_ERROR));
    $request->setUserResolver(static fn (): GenericUser => $user);
    $request->attributes->set('http_basics.credential_public_id', strtoupper($credentialPublicId));
    $request->attributes->set('http_basics.organisation_id', strtoupper($organisationId));

    $response = app(HandleIdempotencyMiddleware::class)->handle($request, static fn () => response()->json(['created' => true], 201));

    expect($response->getStatusCode())->toBe(201)
        ->and((array) DB::table('idempotency_records')->first())->toMatchArray([
            'bucket' => 'opaque-bucket',
            'user_id' => '123456',
            'organisation_id' => $organisationId,
            'credential_public_id' => $credentialPublicId,
        ]);

    $eraser = app(HttpBasicsEraser::class);
    expect(fn () => $eraser->erase('123456', $userPublicId, HttpBasicsEraser::DELETE, organisationId: '42'))
        ->toThrow(InvalidArgumentException::class, 'The organisation id must be a UUID.');
    expect($eraser->erase('123456', $userPublicId, HttpBasicsEraser::DELETE, organisationId: $otherOrganisationId)->changed())->toBe(0)
        ->and($eraser->erase('123456', $userPublicId, HttpBasicsEraser::DELETE, organisationId: strtoupper($organisationId))->changed())->toBe(1);
});

function databaseIdempotencyStore(
    int $ttlSeconds = 86400,
    int $lockTtlSeconds = 30,
    int $staleAfterSeconds = 5,
    ?Repository $cache = null,
): DatabaseIdempotencyStore {
    return new DatabaseIdempotencyStore(
        DB::connection(),
        $cache ?? new Repository(new ArrayStore),
        'idempotency_records',
        $ttlSeconds,
        $lockTtlSeconds,
        $staleAfterSeconds,
    );
}

it('leaves unowned rows out of an erasure report even when they share the organisation scope', function (string $mode): void {
    config()->set('http-basics.idempotency.store', 'database');
    $store = databaseIdempotencyStore();
    $now = new DateTimeImmutable;
    $scope = new IdempotencyScope('signup', 'POST', '/register');
    $organisationId = (string) Str::uuid();
    $unowned = $store->claim($scope, 'signup-key', 'fingerprint', $now, new IdempotencyContext(IdempotencyKeyFormat::SfString, organisationId: $organisationId));
    $store->complete($unowned, new ReplayableResponse(201, [], 'person@example.test'), $now);
    $store->claim($scope, 'owned-key', 'fingerprint', $now, new IdempotencyContext(IdempotencyKeyFormat::SfString, userId: '123456'));
    $userPublicId = (string) Str::uuid();
    $eraser = app(HttpBasicsEraser::class);
    $emptySubjectPublicId = (string) Str::uuid();

    foreach ([null, $organisationId] as $erasureOrganisationId) {
        $emptyReport = $eraser->erase('654321', $emptySubjectPublicId, $mode, organisationId: $erasureOrganisationId);

        expect($emptyReport->changed())->toBe(0)
            ->and($emptyReport->stores['idempotency_records'])->toBe(['deleted' => 0, 'anonymised' => 0, 'kept' => 0, 'reason' => null]);
    }

    $dryRun = $eraser->erase('123456', $userPublicId, $mode, dryRun: true);
    $report = $eraser->erase('123456', $userPublicId, $mode);
    $retry = $eraser->erase('123456', $userPublicId, $mode);

    expect($dryRun->stores)->toBe($report->stores)
        ->and($report->changed())->toBe(1)
        ->and($report->stores['idempotency_records']['kept'])->toBe(0)
        ->and($report->stores['idempotency_records']['reason'])->toBeNull()
        ->and($retry->changed())->toBe(0)
        ->and($retry->stores['idempotency_records']['kept'])->toBe(0)
        ->and(DB::table('idempotency_records')->where('key_hash', hash('sha256', 'signup-key'))->value('body'))->toBe('person@example.test');
})->with([HttpBasicsEraser::DELETE, HttpBasicsEraser::ANONYMISE]);

it('fails clearly when trusted middleware supplies a malformed credential public ID', function (mixed $credentialValue): void {
    app()->instance(CredentialBucketResolver::class, new FakeCredentialBucketResolver('opaque-bucket'));
    $request = Request::create('/api/items', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
    ], content: '{}');
    $request->attributes->set('http_basics.credential_public_id', $credentialValue);

    expect(fn () => app(HandleIdempotencyMiddleware::class)->handle($request, static fn () => response()->json([], 201)))
        ->toThrow(InvalidArgumentException::class, 'The trusted http_basics.credential_public_id attribute must be a UUID.');
})->with(['prefixed key' => 'key_abc123', 'numeric key' => 123456]);

it('retries seal forget failures, then reports any retained seals without losing other deletions', function (int $failures, int $expectedKept): void {
    Event::fake([SubjectErasedEvent::class]);
    $cache = new class extends ArrayStore
    {
        public string $failingKey = '';

        public int $remainingFailures = 0;

        public function forget($key): bool
        {
            if ($key === $this->failingKey && $this->remainingFailures > 0) {
                $this->remainingFailures--;

                throw new RuntimeException('Transient cache failure.');
            }

            return parent::forget($key);
        }
    };
    $store = databaseIdempotencyStore(cache: new Repository($cache));
    $now = new DateTimeImmutable;
    $scope = new IdempotencyScope('user:123456', 'POST', '/api/items');
    $sealKeys = [];

    foreach (['first', 'second'] as $key) {
        $store->claim($scope, $key, 'fingerprint', $now, new IdempotencyContext(IdempotencyKeyFormat::SfString, userId: '123456'));
        $sealKey = SealedKeyMarker::key($scope->bucket, hash('sha256', $key));
        $cache->put($sealKey, true, 86400);
        $sealKeys[] = $sealKey;
    }

    $cache->failingKey = $sealKeys[0];
    $cache->remainingFailures = $failures;
    $eraser = new HttpBasicsEraser(app(Dispatcher::class), $cache, 'array', 'idempotency_records', DB::connection());
    $report = $eraser->erase('123456', (string) Str::uuid(), HttpBasicsEraser::DELETE);

    expect($report->stores['cache:array'])->toBe([
        'deleted' => 2 - $expectedKept,
        'anonymised' => 0,
        'kept' => $expectedKept,
        'reason' => $expectedKept === 0 ? null : 'cache_ttl',
    ])
        ->and($cache->get($sealKeys[0]))->toBe($expectedKept === 0 ? null : true)
        ->and($cache->get($sealKeys[1]))->toBeNull();
    Event::assertDispatched(SubjectErasedEvent::class, static fn (SubjectErasedEvent $event): bool => $event->report === $report);
})->with([
    'transient failure' => [1, 0],
    'persistent failure' => [2, 1],
]);

it('reports listener failures separately from database writes without exposing their details', function (): void {
    config()->set('http-basics.idempotency.store', 'database');
    Event::listen(SubjectErasedEvent::class, static fn () => throw new PDOException('person@example.test'));

    expect(fn () => app(HttpBasicsEraser::class)->erase('123456', (string) Str::uuid(), HttpBasicsEraser::DELETE))
        ->toThrow(RuntimeException::class, 'Failed to dispatch the committed idempotency erasure event.');
});

it('rejects cache stores without atomic locks', function (): void {
    $storeWithoutLocks = new class implements Store
    {
        public function get($key): mixed
        {
            return null;
        }

        /** @param  array<int, string>  $keys */
        public function many(array $keys): array
        {
            return [];
        }

        public function put($key, $value, $seconds): bool
        {
            return true;
        }

        /** @param  array<string, mixed>  $values */
        public function putMany(array $values, $seconds): bool
        {
            return true;
        }

        public function increment($key, $value = 1): int|bool
        {
            return 1;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return 1;
        }

        public function forever($key, $value): bool
        {
            return true;
        }

        public function touch($key, $seconds): bool
        {
            return true;
        }

        public function forget($key): bool
        {
            return true;
        }

        public function flush(): bool
        {
            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }
    };

    expect(fn () => new DatabaseIdempotencyStore(
        DB::connection(),
        new Repository($storeWithoutLocks),
        'idempotency_records',
        60,
        30,
        5,
    ))->toThrow(InvalidArgumentException::class);
});

it('enforces unique bucket and key hash pairs', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claim = $store->claim($scope, $key, 'fp-1', $now, idempotencyContext());
    expect($claim->outcome)->toBe(ClaimOutcome::New)
        ->and(strlen((string) $claim->lease))->toBe(64);

    $store->complete($claim, new ReplayableResponse(201, ['X-A' => '1'], '{"ok":true}'), $now);

    $replay = $store->claim($scope, $key, 'fp-1', $now, idempotencyContext());
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(201)
        ->and($replay->replay?->body)->toBe('{"ok":true}')
        ->and($replay->replay?->headers)->toBe(['X-A' => '1'])
        ->and(DB::table('idempotency_records')->count())->toBe(1);

    $row = DB::table('idempotency_records')->first();
    expect($row->key_hash)->toBe(hash('sha256', $key))
        ->and($row->expires_at)->not->toBeNull();
});

it('returns mismatch for a completed row with a different fingerprint', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440001';

    $claim = $store->claim($scope, $key, 'fp-1', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);

    expect($store->claim($scope, $key, 'fp-2', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Mismatch);
});

it('conflicts while the lock is held and takes over after the row goes stale', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(staleAfterSeconds: 5, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440002';

    $first = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($first->outcome)->toBe(ClaimOutcome::New);
    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);

    $lockKey = $scope->cacheKey($key).':lock';
    $cache->getStore()->restoreLock($lockKey, (string) $first->lease)->forceRelease();

    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);

    $staleNow = $now->modify('+10 seconds');
    $takeover = $store->claim($scope, $key, 'fp-takeover', $staleNow, idempotencyContext());
    expect($takeover->outcome)->toBe(ClaimOutcome::New);

    $row = DB::table('idempotency_records')->first();
    expect($row->fingerprint)->toBe('fp-takeover')
        ->and($row->status)->toBe('in_progress')
        ->and($row->status_code)->toBeNull()
        ->and($row->headers_json)->toBeNull()
        ->and($row->body)->toBeNull();
});

it('replays completed rows with defensive header and status parsing', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440003';

    DB::table('idempotency_records')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp',
        'lease' => str_repeat('a', 64),
        'status' => 'completed',
        'status_code' => '202',
        'headers_json' => json_encode([
            'X-Text' => 'ok',
            'X-List' => ['a', 2],
            9 => 'skip',
            'X-Object' => ['nested' => true],
            'X-Mixed' => ['a', ['b'], 'c'],
        ], JSON_THROW_ON_ERROR),
        'body' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now->modify('+1 day'),
    ]);

    $replay = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(202)
        ->and($replay->replay?->body)->toBe('')
        // The integer key is skipped without dropping the string keys after it.
        ->and($replay->replay?->headers)->toBe([
            'X-Text' => 'ok',
            'X-List' => 'a, 2',
            'X-Object' => '1',
            'X-Mixed' => 'a, , c',
        ]);
});

it('defaults replay headers and body when the completed row is sparse', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440004';

    DB::table('idempotency_records')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp',
        'lease' => str_repeat('b', 64),
        'status' => 'completed',
        'status_code' => 204,
        'headers_json' => '{not-json',
        'body' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now,
    ]);

    $replay = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(204)
        ->and($replay->replay?->headers)->toBe([])
        ->and($replay->replay?->body)->toBe('');
});

it('never replays a completed row whose status code is unreadable', function (): void {
    // MySQL/pgsql reject non-numeric smallints; SQLite stores the corrupt fixture as text.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('A non-numeric status_code fixture requires SQLite column affinity.');
    }

    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440021';

    DB::table('idempotency_records')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp',
        'lease' => str_repeat('b', 64),
        'status' => 'completed',
        'status_code' => 'not-numeric',
        'headers_json' => '{}',
        'body' => 'stored',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now,
    ]);

    // The handler already ran; replaying a guessed 200 or re-running it would both lie.
    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable);
});

it('rejects completion unless the claim is new with a matching in-progress lease', function (): void {
    $store = databaseIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440005';
    $response = new ReplayableResponse(200, ['X' => '1'], '{}');

    expect(fn () => $store->complete(
        new IdempotencyClaim(ClaimOutcome::Replay, null, 'lease', $scope, $key),
        $response,
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    expect(fn () => $store->complete(
        new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key),
        $response,
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $completeAt = $now->modify('+2 seconds');
    $store->complete($claim, $response, $completeAt);

    $row = DB::table('idempotency_records')->first();
    expect($row->status)->toBe('completed')
        ->and($row->status_code)->toBe(200)
        ->and($row->body)->toBe('{}');

    expect(fn () => $store->complete(
        new IdempotencyClaim(ClaimOutcome::New, null, 'other-lease', $scope, $key),
        $response,
        $now,
    ))->toThrow(IdempotencyStoreException::class);
});

it('abandons only new in-progress claims with a matching lease', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440006';

    $store->abandon(new IdempotencyClaim(ClaimOutcome::Replay, null, 'lease', $scope, $key), $now);
    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key), $now);

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, 'wrong', $scope, $key), $now);
    expect(DB::table('idempotency_records')->count())->toBe(1);

    $store->abandon($claim, $now);
    expect(DB::table('idempotency_records')->count())->toBe(0);

    $lockKey = $scope->cacheKey($key).':lock';
    expect($cache->getStore()->lock($lockKey, 1, 'probe')->get())->toBeTrue();
});

it('holds the lock on the scoped :lock key', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440007';

    $wrong = $cache->getStore()->lock($scope->cacheKey($key), 30, 'foreign');
    expect($wrong->get())->toBeTrue();

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($claim->outcome)->toBe(ClaimOutcome::New);

    $right = $cache->getStore()->lock($scope->cacheKey($key).':lock', 30, 'blocker');
    expect($right->get())->toBeFalse();
});

it('uses updated_at then created_at when judging stale in-progress rows', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(staleAfterSeconds: 5, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440008';

    DB::table('idempotency_records')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'old',
        'lease' => str_repeat('c', 64),
        'status' => 'in_progress',
        'status_code' => null,
        'headers_json' => null,
        'body' => null,
        'created_at' => '2025-12-01 00:00:00',
        'updated_at' => '2025-12-01 00:00:00',
        'expires_at' => '2025-12-02 00:00:00',
    ]);

    $takeover = $store->claim($scope, $key, 'fresh', $now, idempotencyContext());
    expect($takeover->outcome)->toBe(ClaimOutcome::New);

    // Fresh updated_at must win over a stale created_at (kills CoalesceRemoveLeft on updated_at).
    DB::table('idempotency_records')->update([
        'created_at' => '2025-12-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
        'lease' => str_repeat('d', 64),
    ]);
    $cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'x')->forceRelease();

    expect($store->claim($scope, $key, 'fresh-2', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);

    // Dropping the trailing `?? null` collapses the timestamp to null/epoch 0 and would allow takeover.
    DB::table('idempotency_records')->update([
        'created_at' => $now->format('Y-m-d H:i:s'),
        'updated_at' => $now->format('Y-m-d H:i:s'),
        'lease' => str_repeat('e', 64),
    ]);
    $cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'y')->forceRelease();

    expect($store->claim($scope, $key, 'fresh-3', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);
});

it('returns replay before contending for the lock on completed rows', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440010';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);
    expect($cache->getStore()->lock($scope->cacheKey($key).':lock', 30, 'held')->get())->toBeTrue();

    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Replay);
});

it('conflicts when only the lock is held and no row exists', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440011';

    expect($cache->getStore()->lock($scope->cacheKey($key).':lock', 30, 'held')->get())->toBeTrue();
    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);
});

it('takes over at the exact stale boundary and clears payload columns', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(ttlSeconds: 90, staleAfterSeconds: 5, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440012';

    $first = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->complete($first, new ReplayableResponse(200, ['X' => '1'], 'body'), $now);

    DB::table('idempotency_records')->update([
        'status' => 'in_progress',
        'updated_at' => $now,
        'created_at' => $now,
    ]);
    $cache->getStore()->restoreLock($scope->cacheKey($key).':lock', (string) $first->lease)->forceRelease();

    $boundary = $now->modify('+5 seconds');
    $takeover = $store->claim($scope, $key, 'fp-2', $boundary, idempotencyContext());
    expect($takeover->outcome)->toBe(ClaimOutcome::New);

    $row = DB::table('idempotency_records')->first();
    expect($row->fingerprint)->toBe('fp-2')
        ->and($row->status)->toBe('in_progress')
        ->and($row->status_code)->toBeNull()
        ->and($row->headers_json)->toBeNull()
        ->and($row->body)->toBeNull()
        ->and($row->expires_at)->not->toBeNull();
});

it('persists null payload columns on the initial in-progress insert', function (): void {
    $store = databaseIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440013';

    $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $row = DB::table('idempotency_records')->first();

    expect($row->status_code)->toBeNull()
        ->and($row->headers_json)->toBeNull()
        ->and($row->body)->toBeNull()
        ->and($row->method)->toBe('POST')
        ->and($row->path)->toBe('/items')
        ->and($row->expires_at)->not->toBeNull();
});

it('releases the scoped lock after complete and abandon', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440014';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(200, [], '{}'), $now);
    expect($cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();

    $otherKey = '550e8400-e29b-41d4-a716-446655440015';
    $again = $store->claim($scope, $otherKey, 'fp', $now, idempotencyContext());
    $store->abandon($again, $now);
    expect($cache->getStore()->lock($scope->cacheKey($otherKey).':lock', 1, 'probe2')->get())->toBeTrue();
});

it('does not abandon in-progress rows for non-new outcomes even with a matching lease', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440016';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->abandon(
        new IdempotencyClaim(ClaimOutcome::Replay, null, (string) $claim->lease, $scope, $key),
        $now,
    );

    expect(DB::table('idempotency_records')->count())->toBe(1);
});

it('requires missing-lease failures on complete to use the dedicated message', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440017';
    $response = new ReplayableResponse(200, [], '{}');

    try {
        $store->complete(new IdempotencyClaim(ClaimOutcome::New, null, null, $scope, $key), $response, $now);
        expect(false)->toBeTrue();
    } catch (IdempotencyStoreException $exception) {
        expect($exception->getMessage())->toBe('Missing lease on idempotency claim.');
    }

    try {
        $store->complete(new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key), $response, $now);
        expect(false)->toBeTrue();
    } catch (IdempotencyStoreException $exception) {
        expect($exception->getMessage())->toBe('Missing lease on idempotency claim.');
    }
});

it('parses datetime objects and invalid timestamp strings when judging staleness', function (): void {
    // Invalid datetime strings are rejected by MySQL/pgsql; SQLite accepts them as text.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Invalid timestamp string fixtures require SQLite.');
    }

    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(staleAfterSeconds: 5, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:10Z');
    $key = '550e8400-e29b-41d4-a716-446655440018';

    DB::table('idempotency_records')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'old',
        'lease' => str_repeat('d', 64),
        'status' => 'in_progress',
        'status_code' => null,
        'headers_json' => null,
        'body' => null,
        'created_at' => $now->modify('-1 second'),
        'updated_at' => $now->modify('-1 second'),
        'expires_at' => $now,
    ]);

    expect($store->claim($scope, $key, 'fresh', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);

    DB::table('idempotency_records')->update([
        'updated_at' => 'not-a-date',
        'created_at' => 'not-a-date',
        'lease' => str_repeat('e', 64),
    ]);
    $cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'x')->forceRelease();

    expect($store->claim($scope, $key, 'fresh-2', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::New);
});

it('replays a completed row discovered after the lock is acquired', function (): void {
    $cache = new Repository(new ArrayStore);
    $completed = (object) [
        'status' => 'completed',
        'fingerprint' => 'fp',
        'headers_json' => '{"X-A":"1"}',
        'status_code' => 200,
        'body' => '{"race":true}',
    ];

    $connection = Mockery::mock(ConnectionInterface::class);
    $builder = Mockery::mock();
    $builder->shouldReceive('where')->andReturnSelf();
    $builder->shouldReceive('first')->andReturn(null, $completed);
    $connection->shouldReceive('table')->andReturn($builder);

    $store = new DatabaseIdempotencyStore(
        $connection,
        $cache,
        'idempotency_records',
        60,
        30,
        5,
    );
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440019';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($claim->outcome)->toBe(ClaimOutcome::Replay)
        ->and($claim->replay?->body)->toBe('{"race":true}');

    expect($cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();
});

it('wraps write failures as store exceptions and releases the lock', function (): void {
    $cache = new Repository(new ArrayStore);
    $connection = Mockery::mock(ConnectionInterface::class);
    $builder = Mockery::mock();
    $builder->shouldReceive('where')->andReturnSelf();
    $builder->shouldReceive('first')->andReturn(null);
    $builder->shouldReceive('insert')->once()->andThrow(new QueryException(
        'sqlite',
        'insert',
        [],
        new Exception('boom'),
    ));
    $connection->shouldReceive('table')->andReturn($builder);

    $store = new DatabaseIdempotencyStore(
        $connection,
        $cache,
        'idempotency_records',
        60,
        30,
        5,
    );
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440009';

    expect(fn () => $store->claim($scope, $key, 'fp', $now, idempotencyContext()))
        ->toThrow(IdempotencyStoreException::class);

    $lockKey = $scope->cacheKey($key).':lock';
    expect($cache->getStore()->lock($lockKey, 1, 'probe')->get())->toBeTrue();
});

it('judges expiry in UTC when the application timezone is not UTC', function (): void {
    // Laravel sets the PHP default timezone from app.timezone; stored datetimes are UTC wall-clock.
    $originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Rome');

    try {
        $store = databaseIdempotencyStore(ttlSeconds: 7200);
        $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $key = '550e8400-e29b-41d4-a716-446655440020';

        $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
        $store->complete($claim, new ReplayableResponse(201, [], 'created'), $now);

        // 90 minutes in: well inside the 2-hour TTL, so the stored response must replay.
        $retry = $store->claim($scope, $key, 'fp', $now->modify('+90 minutes'), idempotencyContext());

        expect($retry->outcome)->toBe(ClaimOutcome::Replay);
    } finally {
        date_default_timezone_set($originalTimezone);
    }
});

/**
 * Inserts a completed row for $key that replays 201 "created" until $expiresAt.
 */
function insertCompletedIdempotencyRow(IdempotencyScope $scope, string $key, string $expiresAt): void
{
    DB::table('idempotency_records')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp-old',
        'lease' => str_repeat('f', 64),
        'status' => 'completed',
        'status_code' => 201,
        'headers_json' => '{"X-Old":"1"}',
        'body' => 'old body',
        'created_at' => '2025-12-01 00:00:00',
        'updated_at' => '2025-12-01 00:00:00',
        'expires_at' => $expiresAt,
    ]);
}

it('stamps a new claim with UTC timestamps and the configured TTL', function (): void {
    $store = databaseIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    // 01:00 in UTC+1 is midnight UTC: the row must be stamped in UTC.
    $now = new DateTimeImmutable('2026-01-01T01:00:00+01:00');
    $key = '550e8400-e29b-41d4-a716-446655440022';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $row = DB::table('idempotency_records')->first();

    expect($row->lease)->toBe($claim->lease)
        ->and($row->fingerprint)->toBe('fp')
        ->and($row->status)->toBe('in_progress')
        ->and($row->created_at)->toBe('2026-01-01 00:00:00')
        ->and($row->updated_at)->toBe('2026-01-01 00:00:00')
        ->and($row->expires_at)->toBe('2026-01-01 00:02:00');
});

it('takes over an expired completed row with a fresh lease, TTL and no old payload', function (): void {
    $store = databaseIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440023';
    insertCompletedIdempotencyRow($scope, $key, '2025-12-31 23:59:59');

    $claim = $store->claim($scope, $key, 'fp-new', $now, idempotencyContext());
    $row = DB::table('idempotency_records')->first();

    expect($claim->outcome)->toBe(ClaimOutcome::New)
        ->and($row->lease)->toBe($claim->lease)
        ->and($row->fingerprint)->toBe('fp-new')
        ->and($row->status)->toBe('in_progress')
        ->and($row->status_code)->toBeNull()
        ->and($row->headers_json)->toBeNull()
        ->and($row->body)->toBeNull()
        ->and($row->created_at)->toBe('2026-01-01 00:00:00')
        ->and($row->updated_at)->toBe('2026-01-01 00:00:00')
        ->and($row->expires_at)->toBe('2026-01-01 00:02:00');
});

it('restarts the TTL from the moment the response is stored', function (): void {
    $store = databaseIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440024';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(201, [], 'created'), $now->modify('+30 seconds'));
    $row = DB::table('idempotency_records')->first();

    expect($row->updated_at)->toBe('2026-01-01 00:00:30')
        ->and($row->expires_at)->toBe('2026-01-01 00:02:30');
});

it('seals an in-progress claim as completed without a payload and frees its lock', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(ttlSeconds: 120, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440025';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    // Payload columns are cleared by the seal itself, not only by the insert defaults.
    DB::table('idempotency_records')->update(['status_code' => 200, 'headers_json' => '{}', 'body' => 'partial']);

    $store->sealUnreplayable($claim, $now->modify('+30 seconds'));
    $row = DB::table('idempotency_records')->first();

    expect($row->status)->toBe('completed')
        ->and($row->status_code)->toBeNull()
        ->and($row->headers_json)->toBeNull()
        ->and($row->body)->toBeNull()
        ->and($row->updated_at)->toBe('2026-01-01 00:00:30')
        ->and($row->expires_at)->toBe('2026-01-01 00:02:30')
        ->and($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable)
        ->and($cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();
});

it('refuses to seal claims it does not own', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440026';
    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());

    expect(fn () => $store->sealUnreplayable(
        new IdempotencyClaim(ClaimOutcome::Replay, null, $claim->lease, $scope, $key),
        $now,
    ))->toThrow(IdempotencyStoreException::class, 'Cannot seal a claim that is not new.')
        ->and(fn () => $store->sealUnreplayable(
            new IdempotencyClaim(ClaimOutcome::New, null, null, $scope, $key),
            $now,
        ))->toThrow(IdempotencyStoreException::class, 'Missing lease on idempotency claim.')
        ->and(fn () => $store->sealUnreplayable(
            new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key),
            $now,
        ))->toThrow(IdempotencyStoreException::class, 'Missing lease on idempotency claim.')
        ->and(fn () => $store->sealUnreplayable(
            new IdempotencyClaim(ClaimOutcome::New, null, str_repeat('0', 64), $scope, $key),
            $now,
        ))->toThrow(IdempotencyStoreException::class, 'Idempotency lease mismatch on seal.')
        ->and(DB::table('idempotency_records')->value('status'))->toBe('in_progress');
});

it('abandons without touching the database when the claim holds no lease', function (?string $lease): void {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldNotReceive('table');
    $store = new DatabaseIdempotencyStore($connection, new Repository(new ArrayStore), 'idempotency_records', 60, 30, 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');

    $store->abandon(
        new IdempotencyClaim(ClaimOutcome::New, null, $lease, $scope, '550e8400-e29b-41d4-a716-446655440027'),
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
})->with([
    'null lease' => [null],
    'empty lease' => [''],
]);

it('releases the lock when the read under the lock fails', function (): void {
    $cache = new Repository(new ArrayStore);
    $connection = Mockery::mock(ConnectionInterface::class);
    $builder = Mockery::mock();
    $builder->shouldReceive('where')->andReturnSelf();
    $builder->shouldReceive('first')->andReturn(null)->once()->ordered();
    $builder->shouldReceive('first')->andThrow(new QueryException('sqlite', 'select', [], new Exception('gone')))->once()->ordered();
    $connection->shouldReceive('table')->andReturn($builder);
    $store = new DatabaseIdempotencyStore($connection, $cache, 'idempotency_records', 60, 30, 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $key = '550e8400-e29b-41d4-a716-446655440028';

    expect(fn () => $store->claim($scope, $key, 'fp', new DateTimeImmutable('2026-01-01T00:00:00Z'), idempotencyContext()))
        ->toThrow(IdempotencyStoreException::class, 'Failed to read idempotency marker.')
        ->and($cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();
});

it('falls back to created_at when an in-progress row has no updated_at', function (): void {
    $store = databaseIdempotencyStore(staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440029';

    DB::table('idempotency_records')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp',
        'lease' => str_repeat('c', 64),
        'status' => 'in_progress',
        'created_at' => '2025-12-31 23:59:58',
        'updated_at' => null,
        'expires_at' => '2026-01-02 00:00:00',
    ]);

    // Started two seconds ago by created_at: still in flight, so no takeover.
    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);
});

it('casts a numeric status code returned as a string by the driver', function (): void {
    $row = (object) [
        'status' => 'completed',
        'fingerprint' => 'fp',
        'status_code' => '202',
        'headers_json' => '{}',
        'body' => 'accepted',
        'expires_at' => '2026-01-02 00:00:00',
    ];
    $connection = Mockery::mock(ConnectionInterface::class);
    $builder = Mockery::mock();
    $builder->shouldReceive('where')->andReturnSelf();
    $builder->shouldReceive('first')->andReturn($row);
    $connection->shouldReceive('table')->andReturn($builder);
    $store = new DatabaseIdempotencyStore($connection, new Repository(new ArrayStore), 'idempotency_records', 60, 30, 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');

    $replay = $store->claim($scope, '550e8400-e29b-41d4-a716-446655440030', 'fp', new DateTimeImmutable('2026-01-01T00:00:00Z'), idempotencyContext());

    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(202);
});

it('keeps replaying a completed row whose expiry cannot be read', function (): void {
    // MySQL/pgsql reject invalid datetimes; SQLite stores the corrupt fixture as text.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('An invalid expires_at fixture requires SQLite.');
    }

    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $key = '550e8400-e29b-41d4-a716-446655440031';
    insertCompletedIdempotencyRow($scope, $key, 'not-a-date');

    // Unreadable expiry fails closed: the handler already ran, so never run it again.
    expect($store->claim($scope, $key, 'fp-old', new DateTimeImmutable('2026-01-01T00:00:00Z'), idempotencyContext())->outcome)
        ->toBe(ClaimOutcome::Replay);
});

it('takes over a just-expired completed row even inside the stale window', function (): void {
    // Staleness only guards in-progress rows; a finished row is free once it expires.
    $store = databaseIdempotencyStore(ttlSeconds: 2, staleAfterSeconds: 60);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440032';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(201, [], 'created'), $now);

    expect($store->claim($scope, $key, 'fp', $now->modify('+3 seconds'), idempotencyContext())->outcome)->toBe(ClaimOutcome::New);
});

function richIdempotencyContext(): IdempotencyContext
{
    return new IdempotencyContext(
        IdempotencyKeyFormat::SfString,
        fingerprintVersion: 2,
        requestId: 'req-0001-abcd',
        traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
        credentialPublicId: '019a5f6e-8c1b-7d2e-9f3a-4b5c6d7e8f90',
        metadata: ['request.content_digest' => 'sha-256=:abc=:'],
    );
}

it('records the claim context and the schema versions on a new row', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $store->claim($scope, 'key-context', 'fp', $now, richIdempotencyContext());
    $row = DB::table('idempotency_records')->first();

    expect($row->public_id)->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/')
        ->and((int) $row->bucket_version)->toBe(1)
        ->and($row->credential_public_id)->toBe('019a5f6e-8c1b-7d2e-9f3a-4b5c6d7e8f90')
        ->and($row->key_hash_algorithm)->toBe('sha256')
        ->and($row->key_format)->toBe('sf-string')
        ->and($row->fingerprint_algorithm)->toBe('sha256')
        ->and((int) $row->fingerprint_version)->toBe(2)
        ->and($row->body_encoding)->toBe('utf-8')
        ->and($row->request_id)->toBe('req-0001-abcd')
        ->and($row->trace_id)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and(json_decode((string) $row->metadata, true))->toBe(['request.content_digest' => 'sha-256=:abc=:'])
        ->and((int) $row->schema_version)->toBe(1)
        ->and($row->completed_at)->toBeNull();
});

it('stores empty metadata as null and plain context facts as null', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');

    $store->claim($scope, 'key-plain', 'fp', new DateTimeImmutable('2026-01-01T00:00:00Z'), idempotencyContext());
    $row = DB::table('idempotency_records')->first();

    expect($row->key_format)->toBe('uuid')
        ->and((int) $row->fingerprint_version)->toBe(Fingerprinter::VERSION)
        ->and($row->metadata)->toBeNull()
        ->and($row->request_id)->toBeNull()
        ->and($row->trace_id)->toBeNull()
        ->and($row->credential_public_id)->toBeNull();
});

it('stamps completed_at in UTC when the response is stored or sealed', function (string $finish): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $claim = $store->claim($scope, 'key-finish', 'fp', $now, idempotencyContext());
    $finishedAt = new DateTimeImmutable('2026-01-01T01:00:30+01:00');

    $finish === 'complete'
        ? $store->complete($claim, new ReplayableResponse(201, [], 'created'), $finishedAt)
        : $store->sealUnreplayable($claim, $finishedAt);

    expect(DB::table('idempotency_records')->value('completed_at'))->toBe('2026-01-01 00:00:30');
})->with(['complete', 'seal']);

it('gives a takeover a new row id and the new claim context', function (): void {
    $store = databaseIdempotencyStore(ttlSeconds: 2);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $claim = $store->claim($scope, 'key-takeover', 'fp', $now, richIdempotencyContext());
    $store->complete($claim, new ReplayableResponse(201, [], 'created'), $now);
    $publicId = DB::table('idempotency_records')->value('public_id');

    // Expired, so claimed again by a request that carries other facts.
    $store->claim($scope, 'key-takeover', 'fp', $now->modify('+1 minute'), idempotencyContext());
    $row = DB::table('idempotency_records')->first();

    expect($row->public_id)->not->toBe($publicId)
        ->and($row->key_format)->toBe('uuid')
        ->and((int) $row->fingerprint_version)->toBe(Fingerprinter::VERSION)
        ->and($row->request_id)->toBeNull()
        ->and($row->trace_id)->toBeNull()
        ->and($row->credential_public_id)->toBeNull()
        ->and($row->metadata)->toBeNull()
        ->and($row->completed_at)->toBeNull();
});

it('gives a takeover a new public id and the new operation facts', function (): void {
    // Review of #22 (B2): one public id must never name two operations.
    $store = databaseIdempotencyStore(ttlSeconds: 2);
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $orders = new IdempotencyScope('bucket-a', 'POST', '/api/orders');
    $store->complete($store->claim($orders, 'key-reuse', 'fp-orders', $now, idempotencyContext()), new ReplayableResponse(201, [], 'order'), $now);
    $first = DB::table('idempotency_records')->first();

    $later = $now->modify('+1 minute');
    $store->claim(new IdempotencyScope('bucket-a', 'DELETE', '/api/accounts/7'), 'key-reuse', 'fp-delete', $later, idempotencyContext());
    $row = DB::table('idempotency_records')->first();

    expect($row->public_id)->not->toBe($first->public_id)
        ->and($row->method)->toBe('DELETE')
        ->and($row->path)->toBe('/api/accounts/7')
        ->and($row->created_at)->toBe('2026-01-01 00:01:00');
});

it('stamps the public id with the claim time', function (): void {
    $store = databaseIdempotencyStore();
    $now = new DateTimeImmutable('2026-01-01T00:00:00.123Z');

    $store->claim(new IdempotencyScope('bucket-a', 'POST', '/items'), 'key-time', 'fp', $now, idempotencyContext());
    // UUIDv7: the first 48 bits are Unix milliseconds.
    $milliseconds = hexdec(substr(str_replace('-', '', (string) DB::table('idempotency_records')->value('public_id')), 0, 12));

    expect($milliseconds)->toBe(1_767_225_600_123);
});

it('keeps buckets that differ only in letter case apart on every engine', function (): void {
    // MySQL and MariaDB compare with a case-insensitive collation unless the column says otherwise.
    $store = databaseIdempotencyStore();
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $upper = $store->claim(new IdempotencyScope('user:Luca', 'POST', '/items'), 'key-case', 'fp', $now, idempotencyContext());
    $lower = $store->claim(new IdempotencyScope('user:luca', 'POST', '/items'), 'key-case', 'fp', $now, idempotencyContext());

    expect($upper->outcome)->toBe(ClaimOutcome::New)
        ->and($lower->outcome)->toBe(ClaimOutcome::New)
        ->and(DB::table('idempotency_records')->count())->toBe(2);
});

it('keeps buckets that differ only by trailing spaces apart on every engine', function (): void {
    // MySQL and MariaDB binary collations still PAD SPACE: 'a' and 'a ' compare equal.
    $store = databaseIdempotencyStore();
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $plain = $store->claim(new IdempotencyScope('user:a', 'POST', '/items'), 'key-pad', 'fp', $now, idempotencyContext());
    $padded = $store->claim(new IdempotencyScope('user:a ', 'POST', '/items'), 'key-pad', 'fp', $now, idempotencyContext());

    expect($plain->outcome)->toBe(ClaimOutcome::New)
        ->and($padded->outcome)->toBe(ClaimOutcome::New)
        ->and(DB::table('idempotency_records')->count())->toBe(2);
});
