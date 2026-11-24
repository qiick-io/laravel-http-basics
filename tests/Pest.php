<?php

declare(strict_types=1);

use Qiick\HttpBasics\Tests\Support\PackageTestCase;

require_once __DIR__.'/Support/idempotency-hrtime-shim.php';
require_once __DIR__.'/Support/idempotency-helpers.php';
require_once __DIR__.'/Support/provider-helpers.php';

/*
 * Fleet conformance under tests/Conformance is app-scoped (needs a product
 * TestCase + migrated schema). phpunit.xml excludes that directory so the
 * synced suite stays for drift-check without running here.
 */
pest()->extend(PackageTestCase::class)->in('Feature', 'Unit', 'Http');
