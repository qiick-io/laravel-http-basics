<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Resolves UploadPolicy entries from `http-basics.uploads.contexts`.
 */
final class ConfigUploadPolicyResolver implements UploadPolicyResolver
{
    /**
     * @param  array<mixed, mixed>  $contexts
     * @param  array<mixed, mixed>  $defaultMagic
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

        return new ArrayUploadPolicy(
            $this->stringList($config['allowed_extensions'] ?? null),
            $this->stringList($config['allowed_mime_types'] ?? null),
            $this->positiveIntOrNull($config['max_bytes'] ?? null),
            (bool) ($config['allows_svg'] ?? false), // @pest-mutate-ignore: RemoveBooleanCast, CoalesceRemoveLeft, FalseToTrue
            $this->mismatchMode($config['content_type_mismatch'] ?? MismatchMode::Reject->value), // @pest-mutate-ignore: CoalesceRemoveLeft
            $this->positiveIntOrNull($config['max_uncompressed_ratio'] ?? null),
            $this->stringListMap(is_array($config['magic_signatures'] ?? null) ? $config['magic_signatures'] : $this->defaultMagic), // @pest-mutate-ignore: TernaryNegated
        );
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return array_values($strings); // @pest-mutate-ignore: UnwrapArrayValues
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (! is_int($value)) { // @pest-mutate-ignore: IfNegated
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        return $value;
    }

    private function mismatchMode(mixed $value): MismatchMode
    {
        if (! is_string($value)) { // @pest-mutate-ignore: IfNegated
            return MismatchMode::Reject;
        }

        return MismatchMode::tryFrom($value) ?? MismatchMode::Reject; // @pest-mutate-ignore: CoalesceRemoveLeft
    }

    /**
     * @param  array<mixed, mixed>  $magic
     * @return array<string, list<string>>
     */
    private function stringListMap(array $magic): array
    {
        $normalized = [];

        foreach ($magic as $extension => $signatures) {
            if (! is_string($extension) || ! is_array($signatures)) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            $hexSignatures = [];

            foreach ($signatures as $signature) {
                if (is_string($signature)) {
                    $hexSignatures[] = $signature;
                }
            }

            $normalized[$extension] = array_values($hexSignatures); // @pest-mutate-ignore: UnwrapArrayValues
        }

        return $normalized;
    }
}
