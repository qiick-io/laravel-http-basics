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
            $table->string('bucket', 128);
            // string(64) not char(64): PostgreSQL CHAR right-pads short values and breaks equality.
            $table->string('key_hash', 64);
            $table->string('method', 16);
            $table->string('path', 2048);
            $table->string('fingerprint', 64);
            $table->string('lease', 64);
            $table->string('status', 32);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('headers_json')->nullable();
            // longText: MySQL/MariaDB `text` is only 64 KiB; responses are capped by max_response_bytes.
            $table->longText('body')->nullable();
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
