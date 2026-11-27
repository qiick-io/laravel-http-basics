<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Config;

use Generator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use Qiick\HttpBasics\Cors\CorsConfigValidator;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Security\ViteFriendlyCspPolicyBuilder;

/**
 * @internal Shares the boot-time checks with the deploy command so diagnostics
 * cannot drift from the configuration the service provider accepts.
 */
final class PackageConfigValidator
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly PackageConfig $settings,
    ) {}

    /**
     * Boot fails at the first problem. The deploy command can report all of them.
     * A fully valid boot also writes the normalized origins back to Laravel config.
     *
     * @return Generator<int, InvalidArgumentException>
     */
    public function problems(bool $shouldNormalizeCors = false): Generator
    {
        yield from $this->idempotencyProblems();

        if (! in_array($this->settings->value('errors.format'), ['fleet', 'problem_details'], true)) {
            yield new InvalidArgumentException('http-basics.errors.format must be fleet or problem_details.');
        }

        foreach ([
            $this->settings->requestIdAcceptFrom(...),
            $this->validateRequestIdAcceptPattern(...),
            $this->settings->cspMode(...),
            $this->validateCspHashes(...),
        ] as $validate) {
            try {
                $validate();
            } catch (InvalidArgumentException $exception) {
                yield $exception;
            }
        }

        $cors = $this->config->get('cors');

        if (is_array($cors)) {
            try {
                $normalizedCors = (new CorsConfigValidator)->validate($cors);
            } catch (InvalidArgumentException $exception) {
                yield $exception;

                return;
            }

            if ($shouldNormalizeCors) {
                $this->config->set('cors', $normalizedCors);
            }
        }
    }

    /** @return Generator<int, InvalidArgumentException> */
    private function idempotencyProblems(): Generator
    {
        $settings = $this->settings;

        foreach (['ttl_seconds', 'max_body_bytes', 'max_response_bytes'] as $positiveKey) {
            if ($settings->int('idempotency.'.$positiveKey) <= 0) {
                yield new InvalidArgumentException("http-basics.idempotency.{$positiveKey} must be greater than 0.");
            }
        }

        if (max($settings->int('idempotency.ttl_seconds'), $settings->int('idempotency.lock_ttl_seconds')) > 86400) {
            yield new InvalidArgumentException('Idempotency records and locks must expire within 24 hours.');
        }

        // Equal budgets leave no margin: the lock can expire while the handler is still allowed to run.
        if ($settings->int('idempotency.lock_ttl_seconds') <= $settings->int('idempotency.max_handler_seconds')) {
            yield new InvalidArgumentException(
                'http-basics.idempotency.lock_ttl_seconds must be greater than max_handler_seconds.',
            );
        }

        $keyFormat = $settings->value('idempotency.key_format');

        if (! is_string($keyFormat) || IdempotencyKeyFormat::tryFrom($keyFormat) === null) {
            yield new InvalidArgumentException('http-basics.idempotency.key_format must be uuid or sf-string.');
        }

        if (! in_array($settings->value('idempotency.store'), ['cache', 'database'], true)) {
            yield new InvalidArgumentException('http-basics.idempotency.store must be cache or database.');
        }

        if (! $settings->bool('idempotency.anonymous.enabled')) {
            return;
        }

        $secret = $settings->value('idempotency.anonymous.secret');

        if (! is_string($secret) || strlen($secret) < 32) {
            yield new InvalidArgumentException(
                'http-basics.idempotency.anonymous.secret must be at least 32 bytes when anonymous idempotency is enabled.',
            );
        }

        // A string such as 'ip' is a mistake to report, not a reason to use the default list.
        $components = $settings->value('idempotency.anonymous.components');

        try {
            self::normalizeAnonymousComponents(is_array($components) ? $settings->stringList('idempotency.anonymous.components') : []);
        } catch (InvalidArgumentException $exception) {
            yield $exception;
        }
    }

    /** @throws InvalidArgumentException when a configured pattern cannot validate the entire request ID. */
    private function validateRequestIdAcceptPattern(): void
    {
        $pattern = $this->settings->value('request_id.accept_pattern');
        $message = 'http-basics.request_id.accept_pattern must be a valid pattern anchored with \A and \z.';

        if (! is_string($pattern) || @preg_match($pattern, '') === false) {
            throw new InvalidArgumentException($message);
        }

        $pattern = ltrim($pattern);
        $closingDelimiter = match ($pattern[0]) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $pattern[0],
        };
        $closingDelimiterAt = strrpos($pattern, $closingDelimiter);
        $body = substr($pattern, 1, (int) $closingDelimiterAt - 1);

        if (! str_starts_with($body, '\A') || ! str_ends_with($body, '\z')) {
            throw new InvalidArgumentException($message);
        }
    }

    /** @throws InvalidArgumentException when hashes cannot safely be included in the CSP. */
    private function validateCspHashes(): void
    {
        $configuredHashes = $this->settings->value('security.csp.script_hashes');

        // A bare string would otherwise read as no hashes at all, dropping the one it holds.
        if ($configuredHashes !== null && ! is_array($configuredHashes)) {
            throw new InvalidArgumentException('http-basics.security.csp.script_hashes must be a list of hashes.');
        }

        $scriptHashes = $this->settings->stringList('security.csp.script_hashes');
        new ViteFriendlyCspPolicyBuilder(null, $scriptHashes);

        // CSP2+ browsers ignore 'unsafe-inline' once a hash is present. Other inline scripts need the nonce.
        if ($scriptHashes !== [] && ! $this->settings->bool('security.csp.nonce')) {
            throw new InvalidArgumentException('http-basics.security.csp.script_hashes needs http-basics.security.csp.nonce: without it a hash turns off \'unsafe-inline\' for every other inline script.');
        }
    }

    /**
     * @param  list<string>  $components
     * @return list<'ip'|'user_agent'|'route'>
     *
     * @throws InvalidArgumentException for an empty list or an unknown component.
     */
    public static function normalizeAnonymousComponents(array $components): array
    {
        $normalized = [];

        foreach ($components as $component) {
            if (! in_array($component, ['ip', 'user_agent', 'route'], true)) {
                throw new InvalidArgumentException(
                    'http-basics.idempotency.anonymous.components may only contain ip, user_agent, and route.',
                );
            }

            $normalized[] = $component;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'http-basics.idempotency.anonymous.components must contain at least one valid component.',
            );
        }

        return array_values(array_unique($normalized));
    }
}
