<?php

declare(strict_types=1);

/**
 * Every foreign key of the package's migrations is served by an index (fleet
 * convention F8; conformance C76 runs the same code in products).
 *
 * Run the migration directly because the provider publishes it for opt-in use.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Qiick\Standards\Conformance\Support\SchemaInspector;

require_once __DIR__.'/../Conformance/Support/SchemaInspector.php';

it('serves every foreign key with an index', function (): void {
    Schema::dropIfExists('idempotency_records');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_000001_create_idempotency_records_table.php';
    $migration->up();

    expect((new SchemaInspector)->foreignKeysWithoutIndex())->toBe([]);
});
