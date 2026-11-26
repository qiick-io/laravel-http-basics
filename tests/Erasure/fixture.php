<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Erasure\HttpBasicsEraser;
use Qiick\HttpBasics\Idempotency\IdempotencyContext;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Idempotency\SealedKeyMarker;
use Qiick\Standards\Erasure\Support\ErasureFixture;
use Qiick\Standards\Erasure\Support\ErasurePerson;

/**
 * Seeds the database adapter and its `array` cache's http-basics:v1:<bucket>:<hash>:sealed entry.
 * The cache-only variant writes an `array` replay entry and a :sealed marker.
 * Driver-native :lock keys and cache-only entries expire on their own.
 * This package has no subject files or additional database connections.
 */
return new class implements ErasureFixture
{
    /** @var array<string, list<string>> */
    private array $cacheKeysByUser = [];

    public function __construct(private readonly bool $isCacheOnly = false) {}

    public function forCache(): self
    {
        return new self(isCacheOnly: true);
    }

    public function packageName(): string
    {
        return 'qiick/laravel-http-basics';
    }

    public function eraserClass(): string
    {
        return HttpBasicsEraser::class;
    }

    public function connectionName(): ?string
    {
        return null;
    }

    public function tables(): array
    {
        return ['idempotency_records' => [
            'id' => 'other',
            'public_id' => 'personal',
            'bucket' => 'personal',
            'user_id' => 'personal',
            'organisation_id' => 'personal',
            'bucket_version' => 'other',
            'credential_public_id' => 'personal',
            'key_hash' => 'personal',
            'key_hash_algorithm' => 'other',
            'key_format' => 'other',
            'method' => 'other',
            'path' => 'personal',
            'fingerprint' => 'personal',
            'fingerprint_algorithm' => 'other',
            'fingerprint_version' => 'other',
            'lease' => 'personal',
            'status' => 'other',
            'status_code' => 'other',
            'headers_json' => 'personal',
            'body' => 'personal',
            'body_encoding' => 'other',
            'request_id' => 'personal',
            'trace_id' => 'personal',
            'metadata' => 'personal',
            'schema_version' => 'other',
            'completed_at' => 'other',
            'created_at' => 'other',
            'updated_at' => 'other',
            'expires_at' => 'other',
        ]];
    }

    public function retainedCleartextColumns(): array
    {
        return [];
    }

    public function diskPaths(ErasurePerson $person): array
    {
        return [];
    }

    public function additionalConnections(): array
    {
        return [];
    }

    public function makePerson(): ErasurePerson
    {
        return ErasurePerson::fresh((string) Str::uuid());
    }

    public function seed(ErasurePerson $person, ?string $organisationId): void
    {
        $store = app(IdempotencyStore::class);
        $now = new DateTimeImmutable;
        $credentialPublicId = $person->publicIds[1];

        if ($this->isCacheOnly) {
            $scope = new IdempotencyScope('session:'.$person->userId, 'POST', '/people/'.$person->userPublicId);
            $key = (string) Str::uuid();
            $claim = $store->claim($scope, $key, hash('sha256', $key), $now, new IdempotencyContext(IdempotencyKeyFormat::Uuid));
            $store->complete($claim, new ReplayableResponse(201, ['Location' => $scope->path], $person->identifiers[0]), $now);
            $sealKey = SealedKeyMarker::key($scope->bucket, hash('sha256', $key));
            (new SealedKeyMarker(Cache::store('array'), 86400))->mark($scope, $key);
            $this->cacheKeysByUser[$person->userId] = [$scope->cacheKey($key), $sealKey];

            return;
        }

        // A session identifies the user; a machine credential may only provide its public ID.
        foreach (['session' => $person->userId, 'key' => $credentialPublicId] as $ownerType => $ownerId) {
            $scope = new IdempotencyScope("{$ownerType}:{$ownerId}:{$organisationId}", 'POST', '/people/'.$person->userPublicId);
            $key = (string) Str::uuid();
            $claim = $store->claim($scope, $key, hash('sha256', $key), $now, new IdempotencyContext(
                IdempotencyKeyFormat::Uuid,
                requestId: 'erasure:'.$person->userId,
                traceId: bin2hex(random_bytes(16)),
                credentialPublicId: $ownerType === 'key' ? $ownerId : null,
                metadata: ['subject.email' => $person->identifiers[0]],
                userId: $ownerType === 'session' ? $ownerId : null,
                organisationId: $organisationId,
            ));
            $store->complete($claim, new ReplayableResponse(201, ['Location' => $scope->path], $person->identifiers[0]), $now);

            // Give each person one fallback seal, in the first organisation they use.
            if (! isset($this->cacheKeysByUser[$person->userId])) {
                $marker = new SealedKeyMarker(Cache::store('array'), 86400);
                $marker->mark($scope, $key);
                $this->cacheKeysByUser[$person->userId] = [SealedKeyMarker::key($scope->bucket, hash('sha256', $key))];
            }
        }
    }

    public function supportsOrganisationScope(): bool
    {
        return ! $this->isCacheOnly;
    }

    public function organisationScopedTables(): array
    {
        return $this->isCacheOnly ? [] : ['idempotency_records'];
    }

    public function cacheKeys(ErasurePerson $person): array
    {
        $cacheKeys = $this->cacheKeysByUser[$person->userId] ?? [];

        return $cacheKeys === [] ? [] : ['array' => $cacheKeys];
    }

    public function call(Closure $erase): object
    {
        return $erase();
    }
};
