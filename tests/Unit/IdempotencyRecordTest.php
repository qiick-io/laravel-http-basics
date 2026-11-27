<?php

declare(strict_types=1);

use Qiick\HttpBasics\Idempotency\IdempotencyRecord;

it('uses the configured table name, or the default for an unusable one', function (mixed $configured, string $expected): void {
    config(['http-basics.idempotency.database.table' => $configured]);

    expect((new IdempotencyRecord)->getTable())->toBe($expected);
})->with([
    'custom' => ['idempotency_custom', 'idempotency_custom'],
    'empty' => ['', 'idempotency_records'],
    'not a string' => [['idempotency_custom'], 'idempotency_records'],
]);

it('uses the configured connection, or the default for an unusable one', function (mixed $configured, ?string $expected): void {
    config(['http-basics.idempotency.database.connection' => $configured]);

    expect((new IdempotencyRecord)->getConnectionName())->toBe($expected);
})->with([
    'custom' => ['idempotency', 'idempotency'],
    'empty' => ['', null],
    'not a string' => [['idempotency'], null],
]);
