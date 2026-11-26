<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Config;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * @internal Typed reads of `http-basics.*` with one fallback rule: a key that is
 * missing (a block a product published before the key existed: `mergeConfigFrom()`
 * merges only the top level), or a value of the wrong type or blank, falls back
 * to the default in defaults().
 *
 * The defaults are constants, not config/http-basics.php evaluated again: that file
 * reads the same environment, so a blank or `null` env var would come back as its
 * own fallback, and under `config:cache` the environment is not loaded at all.
 * PackageConfigTest keeps defaults() equal to the file read with no env vars set.
 */
final class PackageConfig
{
    /**
     * The shipped default of every key that has one, by its path under `http-basics.`.
     *
     * @return array<string, string|int|bool|list<string>>
     */
    public static function defaults(): array
    {
        return [
            'errors.format' => 'fleet',
            'request_id.hidden_context_keys' => ['http_basics.request_id'],
            'request_id.accept_pattern' => '/\A(?=[A-Za-z0-9\-_.:]{8,128}$)(?=.*[A-Za-z0-9])[A-Za-z0-9\-_.:]+\z/D',
            'request_id.accept_from' => 'trusted_proxy',
            'request_id.trust_wildcard_proxies' => false,
            'request_id.outbound.allowed_hosts' => [],
            'locale.supported' => [],
            'locale.machine_doors' => ['api', 'api/*', 'mcp', 'mcp/*'],
            'locale.cookie' => 'locale',
            'locale.fallback' => 'en',
            'locale.max_header_bytes' => 1024,
            'locale.max_languages' => 20,
            'security.enabled' => true,
            'security.x_frame_options' => 'DENY',
            'security.referrer_policy' => 'strict-origin-when-cross-origin',
            'security.permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'security.csp.mode' => 'enforce',
            'security.csp.nonce' => false,
            'security.csp.script_hashes' => [],
            'security.hsts.max_age' => 0,
            'security.hsts.include_subdomains' => false,
            'security.hsts.preload' => false,
            'security.baseline_on_machine_doors' => true,
            'idempotency.store' => 'cache',
            'idempotency.database.table' => 'idempotency_records',
            'idempotency.database.schedule' => true,
            'idempotency.ttl_seconds' => 86400,
            'idempotency.lock_ttl_seconds' => 60,
            'idempotency.max_handler_seconds' => 55,
            'idempotency.stale_after_seconds' => 60,
            'idempotency.key_format' => 'uuid',
            'idempotency.max_key_length' => 128,
            'idempotency.max_body_bytes' => 1048576,
            'idempotency.max_response_bytes' => 1048576,
            'idempotency.replay_header' => 'Idempotent-Replayed',
            'idempotency.methods' => ['POST', 'PATCH'],
            'idempotency.require_json' => true,
            'idempotency.anonymous.enabled' => false,
            'idempotency.anonymous.components' => ['ip', 'user_agent', 'route'],
            'idempotency.replay_headers' => [
                'Content-Type',
                'Content-Language',
                'Cache-Control',
                'Vary',
                'Retry-After',
                'Deprecation',
                'Sunset',
                'Link',
                'WWW-Authenticate',
                'Location',
            ],
            'idempotency.denied_replay_headers' => [
                'Authorization',
                'Proxy-Authenticate',
                'Set-Cookie',
                'X-Api-Key',
                'X-Api-Secret',
                'X-Webhook-Signature',
            ],
        ];
    }

    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * A non-empty string, or the default.
     */
    public function string(string $key): string
    {
        $value = $this->value($key);

        return is_string($value) && $value !== '' ? $value : $this->defaultString($key);
    }

    /**
     * A trimmed non-empty string, or null: for keys that are off when set blank or
     * null. Only a missing key takes the default, if there is one.
     */
    public function optionalString(string $key): ?string
    {
        $value = $this->value($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * An integer, or a numeric string read as one, or the default.
     */
    public function int(string $key): int
    {
        $value = $this->value($key);

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        $default = self::defaults()[$key] ?? null;

        return is_int($default) ? $default : throw new \LogicException("No integer default for http-basics.{$key}.");
    }

    /**
     * The value as a boolean; null takes the default, so a `false` stays false.
     */
    public function bool(string $key): bool
    {
        return (bool) ($this->value($key) ?? self::defaults()[$key] ?? false);
    }

    /**
     * The non-empty strings of a list, or of the default when the value is not a list.
     *
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->value($key);
        $list = is_array($value) ? $value : self::defaults()[$key] ?? [];

        return array_values(array_filter(
            is_array($list) ? $list : [],
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }

    /**
     * The value as configured, or the default when the key is missing, for keys with
     * their own validation: a value that is present stays as it is, even null.
     */
    public function value(string $key): mixed
    {
        $path = 'http-basics.'.$key;

        return $this->config->has($path) ? $this->config->get($path) : self::defaults()[$key] ?? null;
    }

    private function defaultString(string $key): string
    {
        $default = self::defaults()[$key] ?? null;

        return is_string($default) ? $default : throw new \LogicException("No string default for http-basics.{$key}.");
    }
}
