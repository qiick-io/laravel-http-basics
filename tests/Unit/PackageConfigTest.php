<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Qiick\HttpBasics\Config\PackageConfig;

/**
 * @param  array<mixed>  $configured  http-basics.* as a product set it
 */
function packageConfig(array $configured): PackageConfig
{
    return new PackageConfig(new Repository(['http-basics' => $configured]));
}

it('reads a non-empty string, else the default', function (mixed $configured, string $expected): void {
    expect(packageConfig(['locale' => ['cookie' => $configured]])->string('locale.cookie'))->toBe($expected);
})->with([
    'set' => ['lang', 'lang'],
    'empty' => ['', 'locale'],
    'null' => [null, 'locale'],
    'not a string' => [['lang'], 'locale'],
]);

it('reads the default string of a missing key', function (): void {
    expect(packageConfig(['locale' => []])->string('locale.cookie'))->toBe('locale');
});

it('refuses a string key with no string default', function (): void {
    expect(fn () => packageConfig([])->string('locale.max_languages'))
        ->toThrow(LogicException::class, 'No string default for http-basics.locale.max_languages.');
});

it('reads an optional string trimmed, or null when blank, null or not a string', function (mixed $configured, ?string $expected): void {
    expect(packageConfig(['idempotency' => ['replay_header' => $configured]])->optionalString('idempotency.replay_header'))->toBe($expected);
})->with([
    'set' => ['  X-Replayed  ', 'X-Replayed'],
    'blank' => ['   ', null],
    'null' => [null, null],
    'not a string' => [5, null],
]);

it('reads the default of a missing optional string, or null without one', function (): void {
    expect(packageConfig([])->optionalString('idempotency.replay_header'))->toBe('Idempotent-Replayed')
        ->and(packageConfig([])->optionalString('idempotency.documentation_url'))->toBeNull();
});

it('reads an integer or a numeric string, else the default', function (mixed $configured, int $expected): void {
    expect(packageConfig(['locale' => ['max_languages' => $configured]])->int('locale.max_languages'))->toBe($expected);
})->with([
    'int' => [30, 30],
    'numeric string' => ['45', 45],
    'zero' => [0, 0],
    'float' => [12.9, 20],
    'not numeric' => ['abc', 20],
    'null' => [null, 20],
]);

it('refuses an integer key with no integer default', function (): void {
    expect(fn () => packageConfig([])->int('locale.cookie'))
        ->toThrow(LogicException::class, 'No integer default for http-basics.locale.cookie.');
});

it('reads a boolean, taking the default only when null or missing', function (mixed $configured, bool $expected): void {
    expect(packageConfig(['security' => ['enabled' => $configured]])->bool('security.enabled'))->toBe($expected);
})->with([
    'false over the default true' => [false, false],
    'true' => [true, true],
    'zero' => [0, false],
    'null takes the default true' => [null, true],
]);

it('reads false for a boolean with no default', function (): void {
    expect(packageConfig([])->bool('security.unknown'))->toBeFalse()
        ->and(packageConfig([])->bool('idempotency.anonymous.enabled'))->toBeFalse()
        ->and(packageConfig([])->bool('security.baseline_on_machine_doors'))->toBeTrue();
});

it('reads the non-empty strings of a list, or of the default when not a list', function (mixed $configured, array $expected): void {
    expect(packageConfig(['idempotency' => ['methods' => $configured]])->stringList('idempotency.methods'))->toBe($expected);
})->with([
    'a list' => [['PUT', '', null, 'DELETE'], ['PUT', 'DELETE']],
    'an empty list' => [[], []],
    'not a list' => ['PUT', ['POST', 'PATCH']],
    'null' => [null, ['POST', 'PATCH']],
]);

it('reads an empty list for a list key with no default', function (): void {
    expect(packageConfig(['other' => 'x'])->stringList('other'))->toBe([]);
});

it('reads a present value as it is, even null, and a missing one as its default', function (): void {
    expect(packageConfig(['errors' => ['format' => null]])->value('errors.format'))->toBeNull()
        ->and(packageConfig(['errors' => ['format' => 'nope']])->value('errors.format'))->toBe('nope')
        ->and(packageConfig(['errors' => []])->value('errors.format'))->toBe('fleet')
        ->and(packageConfig([])->value('errors.problem_type_base_uri'))->toBeNull();
});

it('keeps the defaults equal to the shipped config file read with no env vars', function (): void {
    $environmentVariables = array_filter(
        array_keys(getenv() + $_ENV + $_SERVER),
        static fn (string|int $name): bool => is_string($name) && (str_starts_with($name, 'HTTP_BASICS_') || $name === 'APP_FALLBACK_LOCALE'),
    );

    expect($environmentVariables)->toBe([]);

    /** @var array<string, mixed> $shippedConfig */
    $shippedConfig = require __DIR__.'/../../config/http-basics.php';
    unset($shippedConfig['uploads']);
    // Every leaf, a list counting as one value; null leaves are keys that are off by default.
    $shippedDefaults = array_filter(shippedLeaves($shippedConfig), static fn (mixed $value): bool => $value !== null);
    $defaults = PackageConfig::defaults();
    ksort($shippedDefaults);
    ksort($defaults);

    expect($defaults)->toBe($shippedDefaults);
});

/**
 * @param  array<mixed>  $config
 * @return array<string, mixed>
 */
function shippedLeaves(array $config, string $prefix = ''): array
{
    $leaves = [];

    foreach ($config as $key => $value) {
        if (is_array($value) && ! array_is_list($value)) {
            $leaves += shippedLeaves($value, $prefix.$key.'.');

            continue;
        }

        $leaves[$prefix.$key] = $value;
    }

    return $leaves;
}
