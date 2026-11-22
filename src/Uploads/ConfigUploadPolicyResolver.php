<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Resolves UploadPolicy entries from `http-basics.uploads.contexts`.
 */
final class ConfigUploadPolicyResolver implements UploadPolicyResolver
{
    /**
     * @param  array<string, array<string, mixed>>  $contexts
     * @param  array<string, list<string>>  $defaultMagic
     */
    public function __construct(
        private array $contexts,
        private array $defaultMagic = [],
    ) {}

    public function forContext(string $context): UploadPolicy
    {
        $config = $this->contexts[$context] ?? null;

        if (! is_array($config)) {
            throw new \InvalidArgumentException("Unknown upload context [{$context}].");
        }

        $extensions = $config['allowed_extensions'] ?? null;
        $mimes = $config['allowed_mime_types'] ?? null;
        $magic = $config['magic_signatures'] ?? $this->defaultMagic;
        $mismatch = $config['content_type_mismatch'] ?? MismatchMode::Reject->value;

        return new ArrayUploadPolicy(
            is_array($extensions) ? array_values(array_filter($extensions, 'is_string')) : null,
            is_array($mimes) ? array_values(array_filter($mimes, 'is_string')) : null,
            isset($config['max_bytes']) && is_int($config['max_bytes']) ? $config['max_bytes'] : null,
            (bool) ($config['allows_svg'] ?? false),
            is_string($mismatch) ? (MismatchMode::tryFrom($mismatch) ?? MismatchMode::Reject) : MismatchMode::Reject,
            isset($config['max_uncompressed_ratio']) && is_int($config['max_uncompressed_ratio'])
                ? $config['max_uncompressed_ratio']
                : null,
            is_array($magic) ? $this->stringListMap($magic) : [],
        );
    }

    /**
     * @param  array<mixed, mixed>  $magic
     * @return array<string, list<string>>
     */
    private function stringListMap(array $magic): array
    {
        $normalized = [];

        foreach ($magic as $extension => $signatures) {
            if (! is_string($extension) || ! is_array($signatures)) {
                continue;
            }

            $normalized[$extension] = array_values(array_filter($signatures, 'is_string'));
        }

        return $normalized;
    }
}
