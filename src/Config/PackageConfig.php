<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Config;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Arr;

/**
 * @internal Typed reads of `http-basics.*` with one fallback rule: a value of the
 * wrong type (a product config that sets `null`, an env var cast badly) falls back
 * to the package's shipped default for the same key, so the provider never repeats
 * default literals that could drift from config/http-basics.php.
 */
final class PackageConfig
{
    /**
     * @param  array<mixed>  $shippedDefaults  config/http-basics.php as shipped
     */
    public function __construct(
        private ConfigRepository $config,
        private array $shippedDefaults,
    ) {}

    public static function fromRepository(ConfigRepository $config): self
    {
        /** @var array<mixed> $shippedDefaults */
        $shippedDefaults = require __DIR__.'/../../config/http-basics.php';

        return new self($config, $shippedDefaults);
    }

    /**
     * A non-empty string, or the shipped default.
     */
    public function string(string $key): string
    {
        $value = $this->value($key);

        return is_string($value) && $value !== '' ? $value : $this->shippedString($key);
    }

    /**
     * A trimmed non-empty string, or null: for keys that are off unless set.
     */
    public function optionalString(string $key): ?string
    {
        $value = $this->value($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * An integer, or a numeric string read as one, or the shipped default.
     */
    public function int(string $key): int
    {
        $value = $this->value($key);

        if (is_numeric($value)) {
            return (int) $value;
        }

        $shipped = Arr::get($this->shippedDefaults, $key);

        return is_int($shipped) ? $shipped : throw new \LogicException("No shipped integer default for http-basics.{$key}.");
    }

    public function bool(string $key): bool
    {
        $value = $this->value($key);

        return (bool) ($value ?? Arr::get($this->shippedDefaults, $key));
    }

    /**
     * The non-empty strings of a list, or of the shipped default when the value is
     * not a list at all.
     *
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->value($key);
        $list = is_array($value) ? $value : Arr::get($this->shippedDefaults, $key);

        return array_values(array_filter(
            is_array($list) ? $list : [],
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }

    /**
     * The value as configured, for keys with their own validation.
     */
    public function value(string $key): mixed
    {
        return $this->config->get('http-basics.'.$key);
    }

    private function shippedString(string $key): string
    {
        $shipped = Arr::get($this->shippedDefaults, $key);

        return is_string($shipped) ? $shipped : throw new \LogicException("No shipped string default for http-basics.{$key}.");
    }
}
