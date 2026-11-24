<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Qiick\HttpBasics\Config\PackageConfig;

/**
 * @param  array<mixed>  $configured  http-basics.* as a product set it
 * @param  array<mixed>  $shipped  the package defaults
 */
function packageConfig(array $configured, array $shipped): PackageConfig
{
    return new PackageConfig(new Repository(['http-basics' => $configured]), $shipped);
}

it('reads a non-empty string, else the shipped one', function (mixed $configured, string $expected): void {
    expect(packageConfig(['group' => ['name' => $configured]], ['group' => ['name' => 'shipped']])->string('group.name'))->toBe($expected);
})->with([
    'set' => ['custom', 'custom'],
    'empty' => ['', 'shipped'],
    'null' => [null, 'shipped'],
    'not a string' => [['x'], 'shipped'],
]);

it('refuses a string key the package ships no string default for', function (): void {
    expect(fn () => packageConfig([], ['name' => 5])->string('name'))
        ->toThrow(LogicException::class, 'No shipped string default for http-basics.name.');
});

it('reads an optional string trimmed, or null when blank or not a string', function (mixed $configured, ?string $expected): void {
    expect(packageConfig(['uri' => $configured], [])->optionalString('uri'))->toBe($expected);
})->with([
    'set' => ['  https://x.test  ', 'https://x.test'],
    'blank' => ['   ', null],
    'null' => [null, null],
    'not a string' => [5, null],
]);

it('reads an integer or a numeric string, else the shipped integer', function (mixed $configured, int $expected): void {
    expect(packageConfig(['limit' => $configured], ['limit' => 60])->int('limit'))->toBe($expected);
})->with([
    'int' => [30, 30],
    'numeric string' => ['45', 45],
    'zero' => [0, 0],
    'not numeric' => ['abc', 60],
    'null' => [null, 60],
]);

it('refuses an integer key the package ships no integer default for', function (): void {
    expect(fn () => packageConfig([], ['limit' => '60'])->int('limit'))
        ->toThrow(LogicException::class, 'No shipped integer default for http-basics.limit.');
});

it('reads a boolean, falling back to the shipped one only when unset', function (mixed $configured, bool $shipped, bool $expected): void {
    expect(packageConfig(['flag' => $configured], ['flag' => $shipped])->bool('flag'))->toBe($expected);
})->with([
    'false over true' => [false, true, false],
    'true over false' => [true, false, true],
    'one' => [1, false, true],
    'null takes the shipped true' => [null, true, true],
    'null takes the shipped false' => [null, false, false],
]);

it('reads the non-empty strings of a list, or of the shipped list when not a list', function (mixed $configured, array $expected): void {
    expect(packageConfig(['items' => $configured], ['items' => ['a', '', 7, 'b']])->stringList('items'))->toBe($expected);
})->with([
    'a list' => [['x', '', null, 'y'], ['x', 'y']],
    'an empty list' => [[], []],
    'not a list' => ['x,y', ['a', 'b']],
]);

it('reads an empty list when neither value is a list', function (): void {
    expect(packageConfig(['items' => 'x'], ['items' => 'y'])->stringList('items'))->toBe([]);
});

it('loads the shipped defaults from the package config file', function (): void {
    $settings = PackageConfig::fromRepository(new Repository(['http-basics' => []]));

    expect($settings->string('locale.cookie'))->toBe('locale')
        ->and($settings->int('idempotency.max_key_length'))->toBe(128)
        ->and($settings->value('locale.cookie'))->toBeNull();
});
