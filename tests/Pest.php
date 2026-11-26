<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Qiick\HttpBasics\Tests\Support\PackageTestCase;

require_once __DIR__.'/Support/idempotency-hrtime-shim.php';
require_once __DIR__.'/Support/idempotency-helpers.php';
require_once __DIR__.'/Support/provider-helpers.php';

/*
 * Fleet conformance under tests/Conformance is app-scoped (needs a product
 * TestCase + migrated schema). phpunit.xml excludes that directory so the
 * synced suite stays for drift-check without running here.
 */
pest()->extend(PackageTestCase::class)->in('Feature', 'Unit', 'Http', 'Erasure');

pest()->in('Erasure')->beforeEach(function (): void {
    config()->set('http-basics.idempotency.store', 'database');
    config()->set('http-basics.idempotency.cache_store', 'array');

    Schema::dropIfExists('idempotency_records');
    $migration = require dirname(__DIR__).'/database/migrations/2026_11_22_000001_create_idempotency_records_table.php';
    $migration->up();
});
