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
 * Driver-native :lock keys expire on their own. The separate cache-only adapter's
 * replay entries have a bounded TTL and cannot be enumerated by subject.
 * This package has no subject files or additional database connections.
 */
return new class implements ErasureFixture
{
    /** @var array<string, string> */
    private array $sealKeysByUser = [];

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
            if (! isset($this->sealKeysByUser[$person->userId])) {
                $marker = new SealedKeyMarker(Cache::store('array'), 86400);
                $marker->mark($scope, $key);
                $this->sealKeysByUser[$person->userId] = SealedKeyMarker::key($scope->bucket, hash('sha256', $key));
            }
        }
    }

    public function supportsOrganisationScope(): bool
    {
        return true;
    }

    public function organisationScopedTables(): array
    {
        return ['idempotency_records'];
    }

    public function cacheKeys(ErasurePerson $person): array
    {
        $sealKey = $this->sealKeysByUser[$person->userId] ?? null;

        return $sealKey === null ? [] : ['array' => [$sealKey]];
    }

    public function call(Closure $erase): object
    {
        return $erase();
    }
};
