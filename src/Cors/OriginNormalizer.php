<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

/**
 * @api Normalises browser Origin values to scheme://host[:port].
 *
 * Rejects userinfo, path, query, fragment, the string `null`, trailing DNS
 * dots, and non-positive ports.
 */
final class OriginNormalizer
{
    public function normalize(string $origin): ?string
    {
        $trimmed = trim($origin); // @pest-mutate-ignore: UnwrapTrim

        if ($trimmed === '' || strtolower($trimmed) === 'null') { // @pest-mutate-ignore: EmptyStringToNotEmpty, BooleanOrToBooleanAnd, UnwrapStrtolower
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts)) { // @pest-mutate-ignore: RemoveNot, IfNegated
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null; // @pest-mutate-ignore: BooleanAndToBooleanOr, UnwrapStrtolower, TernaryNegated
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : null; // @pest-mutate-ignore: BooleanAndToBooleanOr, UnwrapStrtolower, TernaryNegated

        if ($scheme === null || $host === null) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (! in_array($scheme, ['http', 'https'], true)) { // @pest-mutate-ignore: RemoveNot, RemoveArrayItem
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $host = rtrim($host, '.'); // @pest-mutate-ignore: UnwrapRtrim, EmptyStringToNotEmpty

        if ($host === '' || str_contains($host, '..')) { // @pest-mutate-ignore: EmptyStringToNotEmpty, BooleanOrToBooleanAnd
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null; // @pest-mutate-ignore: BooleanAndToBooleanOr, TernaryNegated

        if ($port !== null && $port <= 0) { // @pest-mutate-ignore: NotIdenticalToIdentical, BooleanAndToBooleanOr, SmallerToSmallerOrEqual, SmallerOrEqualToSmaller, DecrementInteger, IncrementInteger
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $defaultPort = $scheme === 'https' ? 443 : 80; // @pest-mutate-ignore: DecrementInteger, IncrementInteger, TernaryNegated

        if ($port === null || $port === $defaultPort) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
            return $scheme.'://'.$host; // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight
        }

        return $scheme.'://'.$host.':'.$port; // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight
    }
}
