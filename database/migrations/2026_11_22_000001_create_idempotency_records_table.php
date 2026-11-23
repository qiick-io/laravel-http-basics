<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional idempotency table for DatabaseIdempotencyStore.
 *
 * Publish with `php artisan vendor:publish --tag=http-basics-migrations`.
 * Not auto-loaded: products that use the cache store never need this table.
 * Edited in place until 1.0.0 (package migrations freeze at the first stable tag).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->id();
            // UUIDv7 written by the store: the id a product may expose, never `id`.
            $table->uuid('public_id')->unique();
            $table->string('bucket', 128);
            // Versions the bucket scheme (the anonymous HMAC components).
            $table->unsignedSmallInteger('bucket_version')->default(1);
            $table->uuid('credential_public_id')->nullable()->index();
            // string(64), not the char(64) of the standards review: PostgreSQL CHAR
            // right-pads shorter values, so equality on them breaks. Same for the
            // fingerprint and the lease below.
            $table->string('key_hash', 64);
            $table->string('key_hash_algorithm', 32)->default('sha256');
            // uuid or sf-string, written by the store from the claim context.
            $table->string('key_format', 16);
            $table->string('method', 16);
            $table->string('path', 2048);
            $table->string('fingerprint', 64);
            $table->string('fingerprint_algorithm', 32)->default('sha256');
            $table->unsignedSmallInteger('fingerprint_version')->default(1);
            $table->string('lease', 64);
            $table->string('status', 32)->default('in_progress');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('headers_json')->nullable();
            // longText: MySQL/MariaDB `text` is only 64 KiB; responses are capped by max_response_bytes.
            $table->longText('body')->nullable();
            // Leaves room for base64 bodies.
            $table->string('body_encoding', 16)->default('utf-8');
            $table->string('request_id', 128)->nullable();
            // W3C trace-id, lowercase hex.
            $table->char('trace_id', 32)->nullable()->index();
            // Namespaced keys; never secrets, never tracestate.
            $table->json('metadata')->nullable();
            // Row format, including how headers_json is serialised.
            $table->unsignedSmallInteger('schema_version')->default(1);
            // A past event: Laravel's default type (C11).
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            // Future-dated: dateTime (C11), never MySQL TIMESTAMP.
            $table->dateTime('expires_at');

            $table->unique(['bucket', 'key_hash']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
